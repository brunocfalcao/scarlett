# Subject ownership and durable failure handling

All APIs below are package-owned and application-agnostic. `Scarlett\Facades\Scarlett`
forwards the public methods of `Scarlett\Engine`.

## Replace the current workflow of a subject

```php
Scarlett\Definition\SubjectSlot::__construct(
    string $subjectType,
    string $subjectId,
    string $name,
)
Scarlett\Engine::replace(SubjectSlot $slot, callable $prepare): ?WorkflowRecord
Scarlett\Engine::current(SubjectSlot $slot): ?string
```

The callback contract is `callable(?WorkflowRecord $previous): Workflow|null|false`.
The engine serializes the subject/slot, locks and revokes its previous workflow,
then calls the callback inside that same database transaction. The callback sees
the revoked previous record, or `null` for a new slot or deleted history.
Return a definition to admit its replacement; `null` or `false` vetoes **all**
changes, including old cancellation, callback writes and slot assignment.
Thrown exceptions and invalid definitions also roll everything back and propagate.
The returned record is the newly admitted workflow; a veto returns `null`.

```php
use App\Models\Document;
use App\Workflows\DocumentWorkflow;
use Scarlett\Definition\SubjectSlot;
use Scarlett\Definition\Workflow;
use Scarlett\Facades\Scarlett;
use Scarlett\Models\WorkflowRecord;

$slot = new SubjectSlot('document', (string) $documentId, 'processing');

$workflow = Scarlett::replace($slot, function (?WorkflowRecord $previous) use ($documentId): ?Workflow {
    $document = Document::query()->lockForUpdate()->findOrFail($documentId);
    if (! $document->canProcess()) {
        return null;
    }

    $document->markProcessing();

    return DocumentWorkflow::definition($document->getKey());
});

$currentId = Scarlett::current($slot);
```

The example's application classes/methods belong to the consumer. The engine binds
the definition to the slot's subject; an explicitly different subject is rejected.
The current pointer remains after completion/failure and becomes `null` when its
workflow history is deleted. There is no automatic adoption of workflows previously
started with `start()`. Introduce slots at an explicit cutover boundary.

Slots are exact, case-sensitive, non-empty UTF-8 identities. Each subject can have
several slots. Only the chosen slot is replaced; independent `start()` workflows
and other slots remain untouched. A replacement respects admission cooldown.
Revocation also cancels unfinished failure handlers and fences their writes.

**Lock order:** call `replace()` before acquiring consumer row locks. The engine
locks admission, then the slot, then the previous workflow, then your callback
may lock consumer rows. Do not call it from a `whileCurrent` callback or while
holding another workflow/consumer lock. All transactional writes must use the
same database connection. Laravel may retry deadlocked transactions under
`scarlett.transaction_attempts`; callbacks must be replay-safe database work,
with external effects scheduled after commit. No consumer replacement retry loop
is needed. An enclosing transaction keeps publication deferred until its commit.

## Read current workflows for a page of subjects

```php
Scarlett\Engine::currentForSubjects(
    string $subjectType,
    array $subjectIds,
    string $slotName,
): array
```

Pass string or integer subject IDs, for example:

```php
$currentIds = Scarlett::currentForSubjects('document', $documents->modelKeys(), 'processing');
// subjectId => workflowId
```

The method executes **one SELECT** for a non-empty batch and no query for an empty
array. It reads the same assignments as `current(SubjectSlot)`: held, running,
completed, failed and archived workflows remain current until replaced or deleted.
Missing slots, null pointers and deleted workflow history are omitted from the
map. Independent workflows and other types/slots are excluded. No consumer mirror
column or knowledge of Scarlett's tables is required.

Integers are converted to their string identity (`0` and `'0'` identify the same
subject); other values are rejected before querying. Non-empty batches use the
same non-empty UTF-8 identity validation as `SubjectSlot`. Matching remains exact
for case, accents and trailing spaces on SQLite, MySQL and PostgreSQL. Duplicate IDs
collapse, and results preserve the first requested order. PHP's normal array-key
rules apply: numeric strings such as `'42'` become integer keys; `'042'` stays a
string. The return type is `array<string|int, string>`.

Pass the current page's IDs; the query uses one bound parameter per distinct slot
and is subject to the database's parameter limits. This is a read, not an ownership
lock: assignments can change afterward. Continue using `whileCurrent` for guarded
writes. The package does not cache this mapping across refreshes.

## Delete all workflows of a subject

```php
Scarlett\Definition\Subject::__construct(string $subjectType, string $subjectId)
Scarlett\Engine::deleteSubject(Subject $subject, callable $prepare, array $guardSubjects = []): bool
```

`$guardSubjects` is a list of `Subject` definitions for related subjects whose
running state must remain stable during a veto check. The callback receives an
`Illuminate\Database\Eloquent\Collection` of the target and guarded workflows,
with their fresh `steps` relations already loaded under locks:
`callable(Collection<int, WorkflowRecord> $locked): bool|null`.

Unlike replacement, this callback runs **before revocation**, after all engine
workflow locks. Acquire consumer row locks inside it, recheck deletion eligibility,
perform transactional consumer cleanup, and return `true` to approve. Returning
`false` or `null` vetoes everything, including callback writes. An exception rolls
back and propagates; other return types are rejected. The method returns `true`
after successful transactional deletion and `false` after a veto. The callback
also runs when the target has no workflow history.

Success revokes every unfinished target workflow, including independent unslotted
work, every named slot, held work, and unfinished failure handlers. It deletes all
target workflow history, including completed/archived workflows, failure records,
delivery intents and transitions, plus every target slot pointer. Guard subjects
are locked for inspection only and remain unchanged. Matching is exact and
case-sensitive even on databases with case-insensitive subject columns.

For a shared resource (such as an address), pass **all relevant related subjects**
as guards. Inspect the callback's loaded steps for running work and apply your
own domain rule. Recheck the shared-resource relationship after locking consumer
rows; veto if the supplied guard set no longer covers it. Scarlett does not infer
shared addresses, send-step names or deletion permissions. Avoid ordinary
snapshot reads for the running-state decision: the supplied locked records remain
current even inside an older MySQL transaction snapshot.

Call `deleteSubject()` before taking workflow or consumer locks. It briefly holds
an **exclusive global admission lock**, then locks relevant workflows and steps,
then invokes the callback. New admissions, replacements, cooldown and reopening
wait until the transaction ends. Existing unrelated workers can continue. This
reuses the existing admission gate and requires no additional migration. Keep the
callback short, database-only, replay-safe and on the same database connection.
Do not start/replace workflows or call engine mutation methods inside this callback.
An outer rollback restores both engine and consumer records. Internally the
approved deletion calls `History::delete(string $workflowId, bool $includingRunning = false): bool`
with `includingRunning: true`; removing attempts under the workflow lock withdraws
their ownership atomically. Ordinary history deletion retains its settled-only
default. History cleanup uses current reads rather than an older transaction snapshot.

Deletion does not permanently ban future use of the subject identity. A producer
admitted after deletion must transactionally recheck that its consumer subject
still exists, using engine-before-consumer lock order, and roll back if absent.
This prevents a later independent admission from recreating orphan work without
adding a package-owned business tombstone.

An old worker's later claim/outcome/guarded-write finds no record and is rejected.
Already published transport jobs may still arrive harmlessly. External calls
already in flight cannot be undone; use the callback veto to preserve any required
handover protections, and keep post-call database writes inside `whileCurrent`.

## Admit follow-up work atomically with consumer writes

```php
Scarlett\Engine::withAdmission(callable $operation): mixed
```

This transaction wrapper acquires shared admission **before** invoking its
callback. It supports a consumer write and `start()` in the same transaction,
including a step or failure handler scheduling a separate follow-up workflow.
It returns the callback's value unchanged. Exceptions roll back all writes and
admissions; returning `false`/`null` is an ordinary return, not a rollback veto.
Queue publication waits for the outermost commit. Cooldown still admits held work.

**Required order:** admission, then any owning workflow lock, then consumer rows,
then `start()`. Call `withAdmission()` before acquiring workflow or consumer locks;
it cannot repair locks already held. It may be inside an existing transaction only
if this order is preserved. Ordinary `start()` remains valid without prior locks.
Calling it after locking consumer rows, or inside an unwrapped `whileCurrent`,
is unsupported: deletion can hold exclusive admission while waiting for those
same rows. A subject-only fence would still have this cycle for that subject.

For a follow-up inside a step (the same pattern applies to failure handlers):

```php
$written = Scarlett::withAdmission(fn () => $context->whileCurrent(
    function () use ($documentId): void {
        $document = Document::query()->lockForUpdate()->findOrFail($documentId);
        $document->markProcessing(); // Consumer-owned database change.
        Scarlett::start(DocumentWorkflow::definition($document->getKey()));
    },
));
```

For a consumer action that already needs a transaction, make this the transaction
boundary before locking the subject:

```php
$workflow = Scarlett::withAdmission(function () use ($documentId) {
    $document = Document::query()->lockForUpdate()->findOrFail($documentId);
    $document->markProcessing();

    return Scarlett::start(DocumentWorkflow::definition($document->getKey()));
});
```

The application classes/methods above are illustrative. Recheck the subject's
existence and business eligibility under its consumer lock. If deletion committed
first, a missing subject must prevent admission. If an attempt was cancelled,
`whileCurrent` returns `false` without invoking its callback; ownership checks use
current locking reads even inside an older MySQL transaction snapshot.

Use one database connection and short, replay-safe database callbacks. The wrapper
uses `scarlett.transaction_attempts`; consumer row ordering still matters and
external calls must stay outside the transaction. The shared gate permits concurrent
admissions but makes deletion/cooldown/reopening wait until the enclosing transaction
ends. Do not call `deleteSubject()`, `cooldown()` or `reopen()` inside the wrapper:
those operations require exclusive admission. `replace()` retains its own documented
slot/workflow-before-consumer order; use its preparation callback instead of invoking
replacement after taking workflow or consumer locks. Ordinary `whileCurrent` writes
that do not call `start()` need no admission wrapper.

## Handle every terminal failure durably

```php
Scarlett\Step::onFailure(Context $context, Failure $failure): void
Scarlett\Step::onFailureWithoutInstance(Context $context, Failure $failure): void // static
Scarlett\Execution\Decision::fail(?string $reason = null): self // static
Scarlett\Execution\Decision::stop(?string $reason = null): self // static
```

Override `onFailure` for business failure effects. Scarlett creates one durable
record per failed/stopped step, including exhausted retries, verification failure,
lease expiry, input restoration failure and exception-handler crashes. It also
supports custom settled states whose `activatesRecovery()` is true. Cancelled,
skipped and successful steps do not create handlers. Steps inheriting both default
hooks need no extra job.

The handler runs as its own queued execution, using the original constructor
bindings and a new `Context`. Constructor values are restored at handler time:
model references and services remain live bindings, while literal snapshots keep
their admitted values. Constructors must remain free of effects. The queue message
contains only identifiers and timeout settings, never the original exception,
step instance, model or service.

A failed child's handler settles before its parent's handler starts. The parent
also waits for already-running descendants that could subsequently fail. Siblings
have no promised relative order. Rescue nodes activate from their target's settled
failure, independently of handler completion. A parent with a failed child stays
`waitingforchildren` until its children and their rescue branches settle; only
then does it fail, register its own hook and admit its own rescues. This includes
already-running siblings, nested resolver children and rescues of failed resolvers.
Use rescue nodes
for a distinct recovery workflow; do not duplicate the same business effect in
both a rescue and a failure hook.

`Decision::fail($reason)` and `stop($reason)` persist the supplied text as the
step's error and handler reason, overriding the caught exception message. Empty
text is preserved; omitted text falls back to the available engine diagnostic, or remains `null`.

### Typed failure data

`Scarlett\Execution\Failure` exposes readonly properties:

| Property | Type / meaning |
| --- | --- |
| `id` | `string`: stable logical failure ID across handler retries; suitable for deduplication |
| `cause` | `Scarlett\Execution\FailureCause` |
| `reason` | `?string`: persisted terminal reason |
| `stepKey`, `stepClass` | `string`: original definition identity |
| `bindings` | `array`: original encoded input bindings; inspect without resolving them |
| `subjectType`, `subjectId` | `?string`: workflow subject |
| `metadata` | `array`: workflow metadata snapshot when the failure was registered |
| `preparationError` | `?string`: current handler input/construction diagnostic |

`Failure::literalInput(string $name): mixed` decodes one named literal binding.
It never restores unrelated bindings. Use scalar identifiers for the fallback;
requesting a model-reference literal can still fail if that model disappeared.
Requesting a service/result binding or absent name throws `InvalidArgumentException`.

| `FailureCause` case | Backed value | Origin |
| --- | --- | --- |
| `Decided` | `decided` | The step's precheck or exception policy chose fail/stop |
| `BudgetExhausted` | `budget_exhausted` | Business, preparation or verification retry budget exhausted |
| `AttemptLost` | `attempt_lost` | Expired execution lease exhausted the applicable budget |
| `HandlerCrashed` | `handler_crashed` | The action's exception classifier failed |
| `InputResolution` | `input_resolution` | Input/constructor failure classified terminal directly |
| `ChildFailed` | `child_failed` | A failed/stopped child concluded its parent |
| `ChildResultMissing` | `child_result_missing` | A runtime parent selected a child that settled without a durable result |
| `ResultPersistence` | `result_persistence` | A successful action's result could not be stored |
| `CompletionRejected` | `completion_rejected` | A completion state transition was denied |

`HandlerCrashed` describes the original exception classifier, not a crash of
`onFailure`. Hook failures update the failure record's separate `error` and
`failures` fields; the original cause/reason never changes.

### Missing inputs and deleted models

If restoration or construction fails, Scarlett invokes the static
`onFailureWithoutInstance` without constructing the step. Implement it using
`Failure` identifiers/metadata and `Context::whileCurrent`:

```php
public static function onFailureWithoutInstance(Context $context, Failure $failure): void
{
    $documentId = $failure->literalInput('documentId');

    $context->whileCurrent(function () use ($documentId, $failure): void {
        // Consumer-owned, idempotent update; tolerate an already-deleted document.
        app(DocumentOutcomes::class)->recordFailure($documentId, $failure->id, $failure->reason);
    });
}
```

An instance-only override without a fallback records a diagnostic and retries
within its budget instead of silently reporting success. A static-only override
also handles normally restored steps: the default instance hook delegates to it.
If the step class itself has been removed, no business method can run; the durable
record retains the bounded failure diagnostic. Keep deployed step classes while
work or failure handlers remain outstanding.

### Ownership, attempts and recovery

A handler `Context` exposes the original `workflowId`/`stepId`, its own `attempt`
and `failureId`, and its own failed-attempt count. `whileCurrent`, `isCurrent`,
`heartbeat` and `canRetry` all address this handler execution. Acquire consumer
locks only inside `whileCurrent`; perform external calls outside the lock.

The hook uses the original step's snapshotted timeout, lease, attempts and backoff,
with a **separate counter** from the business action. Exceptions and expired leases
retry recoverably; exhaustion records handler status `failed`. Hooks do not call
`onException` or recursively create failure hooks. `scarlett:recover` and
`Scarlett::stalled()` / `resume()` include handler delivery and lease recovery.

There is one logical failure record, **not exactly-once external effects**. A crash
after an effect but before acknowledgement can invoke the hook again. Use
`Failure::id` as the business deduplication key and make effects idempotent. Keep
DB effects and their deduplication marker in the same `whileCurrent` transaction.

`WorkflowRecord::failures(): HasMany` exposes records for diagnostics. Their
`status` is `Scarlett\Failures\Status`: Pending, Queued, Running, Completed,
Failed or Cancelled. Workflow `finished_at` and history cleanup wait for all
handlers to settle. Handler exhaustion does not erase the original step failure.
Replacement/cancellation cancels unfinished handlers; it cannot interrupt an
external request already in flight.

## Declare class execution defaults

```php
Scarlett\Step::executionOptions(): Scarlett\Definition\ExecutionOptions // static
```

Override it on your shared base step or individual class. Read values from Laravel
configuration; do not construct the business step to calculate its defaults.

```php
public static function executionOptions(): ExecutionOptions
{
    return new ExecutionOptions(
        workerTimeout: config('processing.timeout'),
        leaseSeconds: config('processing.lease'),
        attempts: config('processing.attempts'),
        backoffSeconds: config('processing.backoff'),
    );
}
```

When `Node::execution` is omitted, Scarlett uses the class defaults. An explicit
node `ExecutionOptions` object replaces the class object entirely; its omitted
fields use package configuration, not class defaults. Effective options are
snapshotted at admission. Later config/code changes do not alter admitted budgets.
This applies to timeout, lease, attempts, backoff and both verification options.
The static method may run during validation and admission; keep it deterministic
and free of external effects. Successors remain unconstructed until eligible.

## Package upgrade

Run ordinary `php artisan migrate` after updating the package and before starting
updated workers. Additive migrations `000005_create_subject_slots` and
`000006_create_failure_executions` are discovered by the provider; do not publish
or copy them. Existing workflows remain unslotted, without invented historical
failure records. New failures after upgrade use the hooks currently deployed.
Migration `000007_add_runtime_children` adds nullable runtime handoff fields and
refuses rollback while an unfinished workflow uses them. For Scarlett 1.x, also
follow the [2.0 upgrade checklist](runtime-children.md#upgrade-from-scarlett-1x).

Stop admission/workers and drain or deliberately cancel outstanding work before
rolling back code/schema. Rolling back these migrations removes slot pointers
and failure history; it is not a live feature toggle. Preserve required history
before a downgrade. No migration touches consumer business tables.

## Database transaction semantics

The lock order applies to MySQL and PostgreSQL row locks and SQLite's single-writer
transactions. Configure concurrent SQLite workers as described in the
[database guide](guide.md#database-configuration). SQLite serializes admissions and
writes globally; it cannot provide the parallel writers available on server databases.

Progression, cancellation, failure-hook ordering and retention use current locked
records under the workflow lock. They do not use an older enclosing MySQL snapshot
to overwrite a completed step or leave a joined parent waiting. Consumers must still
acquire admission before their own locks when admitting follow-up work.
