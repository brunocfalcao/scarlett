<?php

declare(strict_types=1);

namespace Scarlett\Execution;

use InvalidArgumentException;
use Scarlett\Definition\ChildPlan;
use Scarlett\Definition\Node;
use Scarlett\Definition\Workflow;
use Scarlett\Inputs\FromResult;
use Scarlett\Inputs\PayloadCodec;
use Scarlett\Models\StepRecord;
use Scarlett\States\Running;
use Scarlett\States\WaitingForChildren;

/** Called only after Engine has locked the workflow and verified the current attempt. */
final class RuntimeChildren
{
    public function __construct(private NodeStore $store, private PayloadCodec $codec, private Journal $journal) {}

    public function admit(StepRecord $owner, Decision $decision): void
    {
        if ($owner->workflow->steps()->where('parent_key', $owner->key)->lockForUpdate()->first() !== null) {
            throw new InvalidArgumentException('A runtime child plan cannot be combined with predefined children.');
        }
        app(Completion::class)->validate($decision->state, WaitingForChildren::class);
        app(Completion::class)->validate($decision->state, Running::class);
        $definition = $this->definition($owner, $decision->children);
        $result = $decision->children->resultFrom === null ? $this->codec->encode($decision->result) : null;
        $this->store->store($owner->workflow_id, $definition);
        $owner->update([
            'result' => $result,
            'has_result' => $decision->children->resultFrom === null,
            'completion_state' => $decision->state,
            'action_completed' => true,
            'child_phase' => ChildPhase::Waiting,
            'child_result_key' => $decision->children->resultFrom === null ? null : $this->key($owner, $decision->children->resultFrom),
            'lease_until' => null,
            'error' => null,
        ]);
        $this->journal->transition($owner, WaitingForChildren::class);
    }

    private function definition(StepRecord $owner, ChildPlan $plan): Workflow
    {
        $local = new Workflow($plan->nodes);
        if ($local->nodes !== []) {
            $local->validate();
        }
        $indexed = collect($local->nodes)->keyBy('key');
        if ($plan->resultFrom !== null) {
            $source = $indexed->get($plan->resultFrom);
            if ($source === null || $source->parent !== null || $source->rescues !== null) {
                throw new InvalidArgumentException('The result source must be a direct child in this plan.');
            }
        }
        $qualified = [];
        $routing = [];
        // Resolve routing independently of declaration order. Workflow validation has excluded cycles.
        $inherit = function (Node $node) use (&$inherit, &$routing, $indexed, $owner): array {
            if (isset($routing[$node->key])) {
                return $routing[$node->key];
            }
            $ancestor = $node->parent ?? $node->rescues;
            $defaults = $ancestor === null ? [$owner->queue, $owner->connection, $owner->priority] : $inherit($indexed[$ancestor]);

            return $routing[$node->key] = [$node->queue ?? $defaults[0], $node->connection ?? $defaults[1], $node->priority ?? $defaults[2]];
        };
        foreach ($local->nodes as $node) {
            // Check local references before attaching roots to the external owner.
            $prerequisites = array_merge($local->dependencies($node), array_filter([$node->parent, $node->rescues], fn (?string $key): bool => $key !== null));
            foreach ($node->inputs as $input) {
                if ($input instanceof FromResult && ! in_array($input->step, $prerequisites, true)) {
                    throw new InvalidArgumentException("Result {$input->step} must come from a prerequisite in this child plan.");
                }
            }
            $inputs = array_map(fn (mixed $input): mixed => $input instanceof FromResult ? new FromResult($this->key($owner, $input->step)) : $input, $node->inputs);
            [$queue, $connection, $priority] = $inherit($node);
            $qualified[] = new Node(
                $this->key($owner, $node->key), $node->step, $node->stage, $inputs,
                parent: $node->parent !== null ? $this->key($owner, $node->parent) : ($node->rescues === null ? $owner->key : null),
                rescues: $node->rescues === null ? null : $this->key($owner, $node->rescues),
                priority: $priority, queue: $queue, connection: $connection,
                notBefore: $node->notBefore, execution: $node->execution,
            );
        }

        return new Workflow($qualified);
    }

    private function key(StepRecord $owner, string $key): string
    {
        $qualified = $owner->id.'/'.$key;
        if (mb_strlen($qualified, 'UTF-8') > Node::MAX_KEY_LENGTH) {
            throw new InvalidArgumentException('Runtime child identity exceeds the storage length limit.');
        }

        return $qualified;
    }
}
