<?php

declare(strict_types=1);

namespace Scarlett\Definition;

use InvalidArgumentException;
use ReflectionClass;
use Scarlett\Execution\Invoker;
use Scarlett\Step;

/**
 * Describes the steps a workflow should run and how those steps relate to each other.
 *
 * A Node describes one step: its unique key, PHP class, inputs, and links to other
 * steps. It is an instruction for future execution, not an instance doing work now.
 * A parent link groups a step under another step; a rescues link attaches work
 * intended to run when the linked step fails. Stages order steps within each group.
 *
 * Engine::start() checks this definition before saving workflow and step records.
 * NodeStore saves the definition; queue workers later create and run step objects.
 * Keeping those phases separate lets us reject bad definitions without running
 * business constructors or fetching services and results that are needed later.
 * Steps that create children while running also reuse these checks for their plan.
 */
final readonly class Workflow
{
    /**
     * Store the caller's declaration without validating, saving, or running it.
     *
     * The optional subject identifies what this workflow belongs to: for example,
     * type "order" and identifier "42". Metadata holds extra JSON-compatible data.
     * Title and description explain the workflow to people; canonical supplies an
     * optional stable name. These fields do not establish step execution order.
     * Call validate() before treating the declaration as valid.
     *
     * @param  list<Node>  $nodes
     */
    public function __construct(
        public array $nodes,
        public ?string $subjectType = null,
        public ?string $subjectId = null,
        public array $metadata = [],
        public ?string $title = null,
        public ?string $description = null,
        public ?string $canonical = null,
    ) {}

    /**
     * Check the declaration before Engine::start() saves it or queues any work.
     *
     * An exception stops the caller at the first invalid part. Success returns no
     * value and leaves the definition unchanged. Saving inputs still has its own
     * checks: values must be encodable, and a referenced result must come from a
     * step the current step is already allowed to depend on.
     */
    public function validate(): void
    {
        /**
         * There must be at least one step to run. Reject an empty declaration instead of
         * registering a workflow with no work for the engine to process.
         */
        if ($this->nodes === []) {
            throw new InvalidArgumentException('A workflow needs at least one step.');
        }

        /**
         * The subject needs both a kind and an identifier: "42" alone does not tell us
         * whether this is order 42 or invoice 42. Both null means no subject was supplied.
         * Comparing the two null checks rejects exactly the cases where only one is absent.
         */
        if (($this->subjectType === null) !== ($this->subjectId === null)) {
            throw new InvalidArgumentException('A workflow subject requires both its type and its identifier.');
        }

        /**
         * These values will be saved in database columns. Subject fields must fit short
         * string columns; descriptive fields allow longer text. All must be valid UTF-8
         * so the same text can be stored and read back without an encoding failure.
         */
        $this->validateBoundedString($this->subjectType, 'subject type');
        $this->validateBoundedString($this->subjectId, 'subject identifier');
        $this->validateUtf8($this->title, 'workflow title');
        $this->validateUtf8($this->description, 'workflow description');
        $this->validateUtf8($this->canonical, 'workflow canonical name');

        /**
         * The paired-null check above allows empty strings because they are not null.
         * Reject those separately: a supplied subject must actually name its kind and identifier.
         */
        foreach ([$this->subjectType, $this->subjectId] as $value) {
            if ($value !== null && $value === '') {
                throw new InvalidArgumentException('Workflow subject values must not be empty.');
            }
        }

        /**
         * Metadata must survive a JSON round trip. Encoding first catches invalid text,
         * arrays containing themselves, and values such as infinity that JSON cannot store.
         * Encoding alone can accept objects, so validateMetadata() then checks every nested
         * value and limits the data to arrays, strings, numbers, booleans, and null.
         */
        json_encode($this->metadata, JSON_THROW_ON_ERROR);
        $this->validateMetadata($this->metadata);

        /**
         * First pass: check each step and build a lookup of step key => Node.
         * Do not follow parent/rescue links yet: a valid target may appear later in this
         * array. Building the complete lookup first avoids requiring parents to be listed first.
         */
        $indexed = [];
        foreach ($this->nodes as $node) {
            /**
             * A key is the name other steps use to refer to this step. Check the entry's
             * type before reading its fields, then require a name that can be referenced.
             */
            if (! $node instanceof Node || $node->key === '') {
                throw new InvalidArgumentException('Each step needs a non-empty identity.');
            }

            /**
             * Parent and rescue values are other steps' keys. Queue and connection name
             * where the worker should receive the job. These names and the PHP class name
             * must fit their columns, just as this step's own key must fit its column.
             */
            $this->validateBoundedString($node->key, 'step identity', Node::MAX_KEY_LENGTH);
            $this->validateBoundedString($node->step, 'step class');
            $this->validateBoundedString($node->parent, 'parent identity', Node::MAX_KEY_LENGTH);
            $this->validateBoundedString($node->rescues, 'rescue identity', Node::MAX_KEY_LENGTH);
            $this->validateBoundedString($node->queue, 'queue');
            $this->validateBoundedString($node->connection, 'connection');
            $this->validateUtf8($node->title, 'step title');
            $this->validateUtf8($node->description, 'step description');
            $this->validateUtf8($node->canonical, 'step canonical name');

            /**
             * Two steps named "charge" would make a link to "charge" ambiguous. They would
             * also occupy the same lookup entry, so reject duplicates before adding the node.
             */
            if (isset($indexed[$node->key])) {
                throw new InvalidArgumentException("Duplicate step identity: {$node->key}");
            }

            /**
             * The worker needs a concrete Scarlett Step with a handle() method to call.
             * Reflection reads the class declaration without creating an object. Creating
             * it here could run constructor code before its execution-time inputs are ready.
             */
            if (! is_subclass_of($node->step, Step::class) || ! (new ReflectionClass($node->step))->isInstantiable() || ! method_exists($node->step, 'handle')) {
                throw new InvalidArgumentException("{$node->step} must be a concrete Scarlett step with a handle method.");
            }

            /**
             * A parent says "I am a child of this step"; rescues says "I handle this step's
             * failure". A recovery root cannot have both roles. This also means each node
             * has at most one link to follow toward its owner; ordinary roots have neither.
             */
            if ($node->parent !== null && $node->rescues !== null) {
                throw new InvalidArgumentException('A recovery root cannot also have a parent.');
            }

            /**
             * A step may override settings such as timeout and retry count. snapshot()
             * fills in defaults and validates the resulting settings; its return value is
             * intentionally unused here because NodeStore obtains and saves them later.
             * Check the constructor/handle inputs too, then add this valid node to the lookup.
             */
            $node->executionOptions()->snapshot();
            $this->validateSignature($node);
            $indexed[$node->key] = $node;
        }

        /**
         * Second pass: follow each node's parent or rescue links until reaching a root.
         * Every referenced key must exist. A chain must also end: A -> B -> A is a cycle
         * with no root. Keep a separate visited-key set for each walk so different children
         * can legitimately share an ancestor without being mistaken for a cycle.
         */
        foreach ($this->nodes as $node) {
            // Mark the starting node too, so A -> A is caught on the very first iteration.
            $seen = [$node->key => true];
            $ancestor = $node->parent ?? $node->rescues;
            while ($ancestor !== null) {
                if (isset($seen[$ancestor])) {
                    throw new InvalidArgumentException('Cycle in workflow ancestry.');
                }
                if (! isset($indexed[$ancestor])) {
                    throw new InvalidArgumentException("Unknown ancestor: {$ancestor}");
                }
                /**
                 * Remember this ancestor before moving to its owner. The next iteration
                 * will reject it if the chain ever returns here, even through a rescue link.
                 */
                $seen[$ancestor] = true;
                $ancestor = $indexed[$ancestor]->parent ?? $indexed[$ancestor]->rescues;
            }
        }
    }

    /**
     * Check the allowed value types at every depth of the metadata array.
     *
     * JSON encoding can accept objects; this additional walk excludes them from
     * metadata even when they provide a serializable representation.
     */
    private function validateMetadata(array $values): void
    {
        /**
         * Checking only the outer array would miss an object hidden inside a nested array.
         * Reuse this check for each nested array; scalar means string, number, or boolean.
         */
        foreach ($values as $value) {
            if (is_array($value)) {
                $this->validateMetadata($value);
            } elseif ($value !== null && ! is_scalar($value)) {
                throw new InvalidArgumentException('Workflow metadata accepts JSON values only.');
            }
        }
    }

    /** Check optional storage-bound strings by UTF-8 character count, not bytes. */
    private function validateBoundedString(?string $value, string $field, int $maximum = 255): void
    {
        /**
         * Validate the encoding before counting characters. A character such as "é" takes
         * multiple UTF-8 bytes, so a byte count would reject text that still fits the column.
         */
        $this->validateUtf8($value, $field);
        if ($value !== null && mb_strlen($value, 'UTF-8') > $maximum) {
            throw new InvalidArgumentException("$field exceeds the storage length limit.");
        }
    }

    /** Reject malformed text while allowing absent optional values. */
    private function validateUtf8(?string $value, string $field): void
    {
        /**
         * Optional fields may be absent. When text is supplied, reject malformed bytes;
         * do not silently replace characters and change an identity or label the caller sent.
         */
        if ($value !== null && ! mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidArgumentException("$field must be valid UTF-8.");
        }
    }

    /**
     * Check supplied input names against both the constructor and handle method.
     *
     * Both signatures share the node's input map. Required parameters need bindings,
     * optional parameters may use defaults, and execution context is engine-supplied.
     * Literal checks delegate to Invoker without resolving services or result inputs.
     */
    private function validateSignature(Node $node): void
    {
        /**
         * "Signature" means a method's visibility, parameter names, and parameter types.
         * Inspect the declaration rather than executing it. The worker calls handle() on
         * an object, so the method must be publicly callable and must not be static.
         */
        $reflection = new ReflectionClass($node->step);
        $handler = $reflection->getMethod('handle');
        if (! $handler->isPublic() || $handler->isStatic()) {
            throw new InvalidArgumentException('The handle method must be public and non-static.');
        }

        /**
         * Each input is supplied by parameter name, not by position. The same map supplies
         * both __construct() and handle(), so check both methods. A class may have no
         * constructor; array_filter() removes that null entry before inspecting parameters.
         * Keep every recognized name to detect unused or misspelled inputs afterward.
         */
        $known = [];
        foreach (array_filter([$reflection->getConstructor(), $handler]) as $method) {
            foreach ($method->getParameters() as $parameter) {
                $name = $parameter->getName();
                $known[] = $name;

                /**
                 * A parameter declared with & expects a reference to someone else's variable.
                 * Inputs are saved and restored for a later worker, so there is no original
                 * caller variable to share. Reject that unsupported parameter contract.
                 */
                if ($parameter->isPassedByReference()) {
                    throw new InvalidArgumentException("Input $name cannot be passed by reference.");
                }

                /**
                 * Context is the engine's object for the current step execution. The caller
                 * must not supply it as ordinary input, and a variadic parameter (...$items)
                 * cannot stand for that single object. Once checked, skip the normal input
                 * rules because the engine supplies Context when the worker runs the step.
                 */
                if (Invoker::isContext($parameter)) {
                    if (array_key_exists($name, $node->inputs) || $parameter->isVariadic()) {
                        throw new InvalidArgumentException('Execution context is supplied by the engine and cannot be bound or variadic.');
                    }

                    continue;
                }

                /**
                 * Every required parameter needs a matching input name. Optional parameters
                 * can use their PHP defaults. array_key_exists() counts an explicit null as
                 * supplied; isset() would confuse it with a missing input.
                 */
                if (! array_key_exists($name, $node->inputs) && ! $parameter->isOptional()) {
                    throw new InvalidArgumentException("Missing input $name for {$node->key}.");
                }
                if (array_key_exists($name, $node->inputs)) {
                    /**
                     * Check direct values such as strings and numbers against the PHP type.
                     * A service or earlier-step result is only a recipe at this point; Invoker
                     * leaves those unresolved until the worker can fetch their actual values.
                     */
                    Invoker::validateLiteral($parameter, $node->inputs[$name]);
                }
            }
        }

        /**
         * An input named "ammount" must not silently disappear when the method expects
         * "amount". Wait until both methods have been inspected: a name used only by the
         * constructor is still valid even if handle() does not accept it, and vice versa.
         */
        foreach (array_keys($node->inputs) as $name) {
            if (! in_array($name, $known, true)) {
                throw new InvalidArgumentException("Unknown input $name for {$node->key}.");
            }
        }
    }

    /**
     * Return all earlier-stage sibling keys in declaration order for persistence.
     *
     * Siblings must share both parent and rescue links. Every earlier stage is a
     * prerequisite, while equal-stage siblings may run in parallel. Parent and
     * rescue links are stored separately and are not added to this dependency list.
     *
     * @return list<string>
     */
    public function dependencies(Node $node): array
    {
        /**
         * A stage is an ordering number within one sibling group. For example, steps A
         * and B at stage 0 can run together; C at stage 1 must wait for both of them.
         * Include every lower stage, not just the immediately preceding stage.
         *
         * Matching both links keeps different child and rescue groups independent.
         * Filter to the earlier siblings, extract their keys, then reset array indexes
         * so the stored result is a list even when filtering left gaps in the original keys.
         */
        return array_values(array_map(fn (Node $other): string => $other->key, array_filter(
            $this->nodes,
            fn (Node $other): bool => $other->parent === $node->parent && $other->rescues === $node->rescues && $other->stage < $node->stage,
        )));
    }
}
