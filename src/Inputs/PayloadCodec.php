<?php

declare(strict_types=1);

namespace Scarlett\Inputs;

use BackedEnum;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Scarlett\Contracts\PayloadMapper;

/**
 * Persists supported values as versioned JSON instead of PHP object serialization.
 * Object restoration uses configured aliases; unsupported values fail explicitly.
 */
final class PayloadCodec
{
    private const VERSION = 2;

    private const READABLE_VERSIONS = [1, self::VERSION];

    public function encode(mixed $value): array
    {
        return ['version' => self::VERSION, 'value' => $this->pack($value)];
    }

    public function decode(array $payload): mixed
    {
        if (! in_array($payload['version'] ?? null, self::READABLE_VERSIONS, true)) {
            throw new InvalidArgumentException('Unsupported Scarlett payload version.');
        }

        return $this->unpack($payload['value']);
    }

    private function pack(mixed $value): array
    {
        if ($value === null || is_scalar($value)) {
            if (is_float($value) && ! is_finite($value)) {
                throw new InvalidArgumentException('Non-finite numbers cannot be persisted.');
            }

            return ['type' => is_float($value) ? 'float' : 'scalar', 'data' => $value];
        }
        if (is_array($value)) {
            return ['type' => 'array', 'data' => array_map($this->pack(...), $value)];
        }
        if ($value instanceof DateTimeInterface) {
            return ['type' => 'date', 'data' => $value->format('Y-m-d\TH:i:s.uP'), 'timezone' => $value->getTimezone()->getName()];
        }
        if ($value instanceof Collection) {
            return ['type' => 'collection', 'data' => $this->pack($value->all())];
        }
        if ($value instanceof BackedEnum) {
            $alias = array_search($value::class, config('scarlett.enums', []), true);
            if ($alias !== false) {
                return ['type' => 'enum', 'alias' => $alias, 'data' => $value->value];
            }
        }
        if ($value instanceof ModelReference) {
            return ['type' => 'model', 'alias' => $value->alias, 'id' => $value->id, 'connection' => $value->connection];
        }
        if (is_object($value)) {
            foreach (config('scarlett.payload_mappers', []) as $alias => $class) {
                $mapper = app($class);
                if (! $mapper instanceof PayloadMapper) {
                    throw new InvalidArgumentException('Payload mappers must implement PayloadMapper.');
                }
                if ($mapper->supports($value)) {
                    return ['type' => 'mapped', 'alias' => $alias, 'data' => $this->pack($mapper->encode($value))];
                }
            }
        }
        throw new InvalidArgumentException('Unsupported payload type: '.get_debug_type($value).'. Register a mapper or use an explicit model reference/snapshot.');
    }

    private function unpack(array $node): mixed
    {
        $type = $node['type'] ?? null;
        if (! is_string($type)) {
            throw new InvalidArgumentException('Malformed Scarlett payload node.');
        }
        $required = match ($type) {
            'scalar', 'float' => ['data'],
            'array', 'collection' => ['data'],
            'date' => ['data'],
            'enum', 'mapped' => ['alias', 'data'],
            'model' => ['alias', 'id', 'connection'],
            default => null,
        };
        if ($required === null) {
            throw new InvalidArgumentException('Unknown Scarlett payload type.');
        }
        foreach ($required as $key) {
            if (! array_key_exists($key, $node)) {
                throw new InvalidArgumentException('Malformed Scarlett payload node.');
            }
        }
        if (in_array($type, ['array', 'collection'], true) && ! is_array($node['data'])) {
            throw new InvalidArgumentException('Malformed Scarlett payload node.');
        }

        return match ($type) {
            'scalar' => $node['data'],
            'float' => (float) $node['data'],
            'array' => array_map($this->unpack(...), $node['data']),
            'date' => $this->restoreDate($node),
            'collection' => new Collection($this->unpack($node['data'])),
            'enum' => $this->registered('enums', $node['alias'])::from($node['data']),
            'mapped' => app($this->registered('payload_mappers', $node['alias']))->decode($this->unpack($node['data'])),
            'model' => $this->restoreModel($node),
        };
    }

    private function restoreDate(array $node): DateTimeImmutable
    {
        $date = new DateTimeImmutable($node['data']);

        return isset($node['timezone']) ? $date->setTimezone(new DateTimeZone($node['timezone'])) : $date;
    }

    private function registered(string $registry, string|int $alias): string
    {
        return config("scarlett.$registry", [])[$alias] ?? throw new InvalidArgumentException("Unknown $registry alias: $alias");
    }

    private function restoreModel(array $node): Model
    {
        $class = $this->registered('models', $node['alias']);
        $model = new $class;
        if (! $model instanceof Model) {
            throw new InvalidArgumentException('Model aliases must reference Eloquent models.');
        }

        if ($node['connection'] !== null) {
            $model->setConnection($node['connection']);
        }

        return $model->newQuery()->findOrFail($node['id']);
    }
}
