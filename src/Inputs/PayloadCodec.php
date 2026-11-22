<?php

declare(strict_types=1);

/**
 * Converts supported PHP values to structured JSON data and restores them later.
 *
 * Each payload has a format version and a typed value tree. Type tags preserve
 * information plain JSON loses, such as an integral float versus an integer, a
 * collection versus an array, or a date's timezone. Class restoration uses configured
 * aliases rather than arbitrary class names from stored data. New writes use version
 * 2; readers also accept version 1 so retained historical work remains readable.
 */

namespace Scarlett\Inputs;

use BackedEnum;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Scarlett\Contracts\PayloadMapper;
use ValueError;

/**
 * Persists supported values as versioned JSON instead of PHP object serialization.
 * Object restoration uses configured aliases; unsupported values fail explicitly.
 */
final class PayloadCodec
{
    private const VERSION = 2;

    private const READABLE_VERSIONS = [1, self::VERSION];

    /**
     * Each array level adds two JSON levels to the stored payload. 254 levels is the deepest
     * value that the model's array cast can still read back with json_decode's default
     * depth of 512, so this bound refuses nothing that could be saved and restored, and it
     * stops self-containing arrays long before PHP's own recursion limit is reached.
     */
    private const MAXIMUM_DEPTH = 254;

    public function encode(mixed $value): array
    {
        $resolvedMappers = [];

        return ['version' => self::VERSION, 'value' => $this->pack($value, 0, $resolvedMappers)];
    }

    public function decode(array $payload): mixed
    {
        if (! in_array($payload['version'] ?? null, self::READABLE_VERSIONS, true)) {
            throw new InvalidArgumentException('Unsupported Scarlett payload version.');
        }
        if (! is_array($payload['value'] ?? null)) {
            throw new InvalidArgumentException('Malformed Scarlett payload node.');
        }

        return $this->unpack($payload['value']);
    }

    /** @param array<string|int, PayloadMapper> $resolvedMappers Mappers resolved so far in this encode call only. */
    private function pack(mixed $value, int $depth, array &$resolvedMappers): array
    {
        if ($depth > self::MAXIMUM_DEPTH) {
            throw new InvalidArgumentException('Payload values are nested too deeply.');
        }
        /**
         * JSON cannot represent NaN or infinity. Give integral floats their own type tag
         * so encoding 1.0 and decoding it through JSON does not silently turn it into int 1.
         */
        if ($value === null || is_scalar($value)) {
            if (is_float($value) && ! is_finite($value)) {
                throw new InvalidArgumentException('Non-finite numbers cannot be persisted.');
            }
            if (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
                throw new InvalidArgumentException('Payload strings must be valid UTF-8.');
            }

            return ['type' => is_float($value) ? 'float' : 'scalar', 'data' => $value];
        }
        if (is_array($value)) {
            $packed = [];
            foreach ($value as $key => $item) {
                if (is_string($key) && ! mb_check_encoding($key, 'UTF-8')) {
                    throw new InvalidArgumentException('Payload keys must be valid UTF-8.');
                }
                $packed[$key] = $this->pack($item, $depth + 1, $resolvedMappers);
            }

            return ['type' => 'array', 'data' => $packed];
        }
        if ($value instanceof DateTimeInterface) {
            return ['type' => 'date', 'data' => $value->format('Y-m-d\TH:i:s.uP'), 'timezone' => $value->getTimezone()->getName()];
        }
        if ($value instanceof Collection) {
            return ['type' => 'collection', 'data' => $this->pack($value->all(), $depth + 1, $resolvedMappers)];
        }
        if ($value instanceof BackedEnum) {
            $alias = array_search($value::class, config('scarlett.enums', []), true);
            if ($alias !== false) {
                $this->assertUtf8('Payload strings must be valid UTF-8.', $value->value);

                return ['type' => 'enum', 'alias' => $alias, 'data' => $value->value];
            }
        }
        /**
         * Store an explicit live-row reference, never an arbitrary Eloquent object.
         * Applications choose a snapshot or mapper instead when historical field values
         * must be retained rather than fetched afresh.
         */
        if ($value instanceof ModelReference) {
            $this->assertUtf8('Payload strings must be valid UTF-8.', $value->alias, $value->id, $value->connection);

            return ['type' => 'model', 'alias' => $value->alias, 'id' => $value->id, 'connection' => $value->connection];
        }
        /**
         * Custom objects need a registered mapper. Store the mapper alias and recursively
         * encode its data, keeping class selection in trusted application configuration
         * rather than embedding executable PHP object serialization in database records.
         */
        if (is_object($value)) {
            $mappers = config('scarlett.payload_mappers', []);
            if (! is_array($mappers)) {
                throw new InvalidArgumentException('scarlett.payload_mappers must be an array.');
            }
            foreach ($mappers as $alias => $class) {
                $mapper = $resolvedMappers[$alias] ??= $this->resolveMapper($class);
                if ($mapper->supports($value)) {
                    return ['type' => 'mapped', 'alias' => $alias, 'data' => $this->pack($mapper->encode($value), $depth + 1, $resolvedMappers)];
                }
            }
        }
        throw new InvalidArgumentException('Unsupported payload type: '.get_debug_type($value).'. Register a mapper or use an explicit model reference/snapshot.');
    }

    private function unpack(mixed $node): mixed
    {
        if (! is_array($node)) {
            throw new InvalidArgumentException('Malformed Scarlett payload node.');
        }
        $type = $node['type'] ?? null;
        if (! is_string($type)) {
            throw new InvalidArgumentException('Malformed Scarlett payload node.');
        }
        /**
         * Validate every visited node before reading its fields, including nested ones.
         * Use array_key_exists() below because a present data field containing null is
         * valid and must not be mistaken for a missing field.
         */
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
        $this->assertNodeFields($type, $node);

        return match ($type) {
            'scalar' => $node['data'],
            'float' => (float) $node['data'],
            'array' => array_map($this->unpack(...), $node['data']),
            'date' => $this->restoreDate($node),
            'collection' => new Collection($this->unpack($node['data'])),
            'enum' => $this->restoreEnum($node),
            'mapped' => $this->resolveMapper($this->registered('payload_mappers', $node['alias']))->decode($this->unpack($node['data'])),
            'model' => $this->restoreModel($node),
        };
    }

    /**
     * Each type's fields must have the shape its restoration reads. Without this check a
     * wrong-typed field fails later as a PHP TypeError, or is coerced into a plausible
     * value such as 0.0 for 'abc'. Nested nodes are checked when unpack() visits them.
     */
    private function assertNodeFields(string $type, array $node): void
    {
        $valid = match ($type) {
            'scalar' => $node['data'] === null || is_scalar($node['data']),
            'float' => is_int($node['data']) || is_float($node['data']),
            'array' => is_array($node['data']),
            'collection' => is_array($node['data']) && ($node['data']['type'] ?? null) === 'array',
            'date' => is_string($node['data']) && (! isset($node['timezone']) || is_string($node['timezone'])),
            'enum' => $this->isAlias($node['alias']) && (is_string($node['data']) || is_int($node['data'])),
            'mapped' => $this->isAlias($node['alias']) && is_array($node['data']),
            'model' => $this->isAlias($node['alias']) && $this->isIdentifier($node['id'])
                && ($node['connection'] === null || is_string($node['connection'])),
            default => false,
        };
        if (! $valid) {
            throw new InvalidArgumentException('Malformed Scarlett payload node.');
        }
    }

    private function isAlias(mixed $alias): bool
    {
        return is_string($alias) || is_int($alias);
    }

    private function isIdentifier(mixed $id): bool
    {
        return is_string($id) || is_int($id);
    }

    private function restoreDate(array $node): DateTimeImmutable
    {
        try {
            $date = new DateTimeImmutable($node['data']);

            return isset($node['timezone']) ? $date->setTimezone(new DateTimeZone($node['timezone'])) : $date;
        } catch (Exception $exception) {
            throw new InvalidArgumentException('Malformed Scarlett payload node.', previous: $exception);
        }
    }

    private function restoreEnum(array $node): BackedEnum
    {
        $class = $this->registered('enums', $node['alias']);
        if (! is_subclass_of($class, BackedEnum::class)) {
            throw new InvalidArgumentException('Enum aliases must reference backed enums.');
        }
        try {
            return $class::from($node['data']);
        } catch (ValueError $error) {
            throw new InvalidArgumentException('Malformed Scarlett payload node.', previous: $error);
        }
    }

    /** Text stored beside a value (aliases, identifiers, enum values) must survive the JSON write too. */
    private function assertUtf8(string $message, int|string|null ...$values): void
    {
        foreach ($values as $value) {
            if (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
                throw new InvalidArgumentException($message);
            }
        }
    }

    /** Mapper classes are checked before instantiation, so a misconfigured class is never constructed. */
    private function resolveMapper(mixed $class): PayloadMapper
    {
        if (! is_string($class) || ! is_subclass_of($class, PayloadMapper::class)) {
            throw new InvalidArgumentException('Payload mappers must implement PayloadMapper.');
        }

        return app($class);
    }

    private function registered(string $registry, string|int $alias): string
    {
        $class = config("scarlett.$registry", [])[$alias] ?? null;
        if (! is_string($class)) {
            throw new InvalidArgumentException("Unknown $registry alias: $alias");
        }

        return $class;
    }

    private function restoreModel(array $node): Model
    {
        $class = $this->registered('models', $node['alias']);
        if (! is_subclass_of($class, Model::class)) {
            throw new InvalidArgumentException('Model aliases must reference Eloquent models.');
        }
        $model = new $class;

        /**
         * Only an explicit override replaces the model's configured connection. Setting
         * null here would erase a connection declared by the model and could query the
         * wrong database when the application default differs.
         */
        if ($node['connection'] !== null) {
            $model->setConnection($node['connection']);
        }

        return $model->newQuery()->findOrFail($node['id']);
    }
}
