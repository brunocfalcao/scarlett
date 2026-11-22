<?php

declare(strict_types=1);

/**
 * Small shared vocabulary for tests that manually drive workflow deliveries.
 *
 * statesOf() returns a deterministic key-to-state map, controlled() creates a Node
 * using the observable ControlledStep fixture, and runStep() delivers the current
 * saved attempt to the real runner. These helpers do not bypass engine claims or
 * create results directly; queue-faked cases still exercise the execution lifecycle.
 */
use Illuminate\Support\Facades\DB;
use Scarlett\Definition\Node;
use Scarlett\Execution\Runner;
use Scarlett\Models\StepRecord;
use Scarlett\Tests\Fixtures\ControlledStep;

function statesOf(string $workflow): array
{
    return StepRecord::where('workflow_id', $workflow)->orderBy('key')->get()->mapWithKeys(fn ($step) => [$step->key => (string) $step->state])->all();
}
function controlled(string $key, string $mode = 'success', int $stage = 0, ?string $parent = null, ?string $rescues = null): Node
{
    return new Node($key, ControlledStep::class, stage: $stage, inputs: ['name' => $key, 'mode' => $mode], parent: $parent, rescues: $rescues);
}
function runStep(string $workflow, string $key): void
{
    /**
     * Read the current token on each delivery so the helper follows genuine retries.
     * The unclaimed fallback deliberately cannot acquire authority for a pending step.
     */
    $step = StepRecord::where('workflow_id', $workflow)->where('key', $key)->sole();
    app(Runner::class)->run($step->id, $step->attempt ?? 'unclaimed');
}
/**
 * Make the database itself refuse history rows that record a move to $toState, with $message.
 *
 * The refusal is a real database error raised inside the engine's own write, so the code under
 * test sees what a failed write looks like on each driver, including PostgreSQL ending the
 * transaction. Each driver has its own trigger syntax; the PostgreSQL function is replaced on
 * every call because dropping the tables between tests does not drop functions.
 */
function blockHistoryInsert(string $trigger, string $toState, string $message): void
{
    $connection = DB::connection();
    $state = $connection->getPdo()->quote($toState);
    $error = $connection->getPdo()->quote($message);
    match ($connection->getDriverName()) {
        'sqlite' => $connection->unprepared("CREATE TRIGGER $trigger BEFORE INSERT ON scarlett_history WHEN NEW.to_state = $state BEGIN SELECT RAISE(ABORT, $error); END"),
        'mysql', 'mariadb' => $connection->unprepared("CREATE TRIGGER $trigger BEFORE INSERT ON scarlett_history FOR EACH ROW BEGIN IF NEW.to_state = $state THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = $error; END IF; END"),
        'pgsql' => $connection->unprepared(
            "CREATE OR REPLACE FUNCTION $trigger() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF NEW.to_state = $state THEN RAISE EXCEPTION $error; END IF; RETURN NEW; END \$\$;"
            ." CREATE TRIGGER $trigger BEFORE INSERT ON scarlett_history FOR EACH ROW EXECUTE FUNCTION $trigger();"
        ),
    };
}
