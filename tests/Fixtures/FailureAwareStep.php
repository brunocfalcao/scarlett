<?php

declare(strict_types=1);

namespace Scarlett\Tests\Fixtures;

use Scarlett\Execution\Context;
use Scarlett\Execution\Decision;
use Scarlett\Execution\Failure;
use Scarlett\Models\WorkflowRecord;
use Throwable;

class FailureAwareStep extends ControlledStep
{
    public static int $hookCalls = 0;

    public static bool $hookThrows = false;

    public static bool $constructionThrows = false;

    public function __construct(string $name, string $mode = 'success', mixed $value = null)
    {
        if (self::$constructionThrows) {
            throw new \RuntimeException('Construction unavailable.');
        }
        parent::__construct($name, $mode, $value);
    }

    public function before(Context $context): ?Decision
    {
        return match ($this->mode) {
            'fail' => Decision::fail('A useful failure reason.'),
            'approved' => Decision::complete(null, Approved::class),
            'stop' => Decision::stop('A useful stop reason.'),
            'handler-crash', 'classified' => throw new \RuntimeException('Technical exception.'),
            default => parent::before($context),
        };
    }

    public function onException(Throwable $exception, Context $context): Decision
    {
        if ($this->mode === 'handler-crash') {
            throw new \RuntimeException('Handler crashed.');
        }

        return $this->mode === 'classified' ? Decision::fail('Readable explanation.') : parent::onException($exception, $context);
    }

    public function onFailure(Context $context, Failure $failure): void
    {
        self::$hookCalls++;
        if (self::$hookThrows) {
            throw new \RuntimeException('Temporary hook error.');
        }
        self::record($context, $failure, $this->name, false);
    }

    public static function onFailureWithoutInstance(Context $context, Failure $failure): void
    {
        self::record($context, $failure, $failure->literalInput('name'), true);
    }

    private static function record(Context $context, Failure $failure, string $name, bool $fallback): void
    {
        $context->whileCurrent(function () use ($context, $failure, $name, $fallback): void {
            $workflow = WorkflowRecord::findOrFail($context->workflowId);
            $metadata = $workflow->metadata;
            $metadata['failures'][] = ['name' => $name, 'cause' => $failure->cause->value, 'reason' => $failure->reason, 'id' => $failure->id, 'fallback' => $fallback];
            $workflow->update(['metadata' => $metadata]);
        });
    }
}
