<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler\XDebug;

use Buggregator\Trap\Support\TimeSlice;

/**
 * Links the calls of one profile to their callers as they end: the calls made from a call are the last ones
 * ended before it. A call takes a few integers in flat arrays, indexed in the order the calls end.
 *
 * Times are rounded to microseconds so that the sum over the calls of a caller-callee pair stays exact:
 * rounding each call on its own adds up to a large error over many sub-microsecond calls,
 * 1000 calls of 0.5 µs would take 1000 µs or none. So the rounding error of a pair is carried over to its next call.
 * A call never takes less than its children, to keep the exclusive times non-negative;
 * the excess is taken back from the next calls of the pair.
 *
 * @internal
 */
final class CallLinker
{
    /** @var list<non-empty-string> Function names by IDs */
    public array $names = [];

    /** @var list<int> Function IDs of the calls */
    public array $function = [];

    /** @var array<int, int> Callers of the calls; -1 for a root */
    public array $caller = [];

    /** @var array<int, int> Inclusive microseconds of the calls */
    public array $wt = [];

    /** @var list<int> Inclusive memory of the calls in bytes */
    public array $memory = [];

    /**
     * Offsets of the calls from their caller in the pre-order: 1 and the sizes of the subtrees of the earlier
     * callees. {@see self::preOrder()} turns them into positions.
     *
     * @var array<int, int>
     */
    private array $offset = [];

    /** @var array<non-empty-string, int> */
    private array $ids = [];

    /** @var list<int> Calls waiting for their caller */
    private array $ended = [];

    /** @var array<int, int> Inclusive time of the waiting calls, in the profile units */
    private array $time = [];

    /** @var array<int, int> Microseconds of the callees of the waiting calls */
    private array $calleesWt = [];

    /** @var array<int, int> Calls in the subtrees of the waiting calls */
    private array $size = [];

    /** @var array<string, int> Inclusive time of the caller-callee pairs, in the profile units */
    private array $pairTime = [];

    /** @var array<string, int> Microseconds given to the calls of the pairs */
    private array $pairWt = [];

    /**
     * @param float $timeDivisor Divisor of the time event to microseconds.
     */
    public function __construct(
        private readonly float $timeDivisor,
        private readonly ?TimeSlice $timeSlice = null,
    ) {}

    /**
     * @throws \InvalidArgumentException When the calls the call lists did not end before it.
     */
    public function end(Call $call): void
    {
        $name = $call->name;
        $count = \count($call->callees);
        $count > \count($this->ended) and throw new \InvalidArgumentException(
            "Call of `$name` lists more calls than ended before it.",
        );

        $node = \count($this->function);
        $this->function[] = $this->ids[$name] ??= \array_push($this->names, $name) - 1;
        $this->caller[] = -1;
        $this->wt[] = 0;
        $this->offset[] = 0;
        $time = $call->time;
        $memory = $call->memory;
        $calleesWt = 0;
        $size = 1;
        // Not `array_splice()`: it copies the rest, and a function may make a million calls
        $children = $count === 0 ? [] : \array_slice($this->ended, -$count);
        for ($i = 0; $i < $count; ++$i) {
            \array_pop($this->ended);
        }
        foreach ($children as $i => $child) {
            $childName = $this->names[$this->function[$child]];
            $childName === $call->callees[$i] or throw new \InvalidArgumentException(
                "Call of `$name` lists `{$call->callees[$i]}`, but `$childName` ended there.",
            );
            $this->round($child, $name);
            $time += $this->time[$child];
            $memory += $this->memory[$child];
            $calleesWt += $this->wt[$child];
            $this->caller[$child] = $node;
            $this->offset[$child] = $size;
            $size += $this->size[$child];
            unset($this->time[$child], $this->calleesWt[$child], $this->size[$child]);
        }

        $this->memory[] = $memory;
        $this->time[$node] = $time;
        $this->calleesWt[$node] = $calleesWt;
        $this->size[$node] = $size;
        $this->ended[] = $node;
        $this->timeSlice?->tick();
    }

    /**
     * Rounds the calls that ended with no caller.
     */
    public function finish(): void
    {
        $offset = 0;
        foreach ($this->ended as $node) {
            $this->round($node, '');
            $this->offset[$node] = $offset;
            $offset += $this->size[$node];
        }
        $this->ended = $this->time = $this->calleesWt = $this->size = $this->pairTime = $this->pairWt = [];
    }

    /**
     * @return array<int, int> The calls by their positions in the pre-order: each after its caller, the callees of a call in their order.
     */
    public function preOrder(): array
    {
        $count = \count($this->function);
        // A caller ends after its callees: from the last, the callers come first
        for ($node = $count - 1; $node >= 0; --$node) {
            $caller = $this->caller[$node];
            $caller === -1 or $this->offset[$node] += $this->offset[$caller];
        }

        $order = $count === 0 ? [] : \array_fill(0, $count, 0);
        foreach ($this->offset as $node => $position) {
            $order[$position] = $node;
        }
        $this->offset = [];

        return $order;
    }

    private function round(int $node, string $caller): void
    {
        $pair = $caller . "\0" . $this->names[$this->function[$node]];
        $this->pairTime[$pair] = ($this->pairTime[$pair] ?? 0) + $this->time[$node];
        $given = $this->pairWt[$pair] ?? 0;
        $value = \max(
            $this->calleesWt[$node],
            (int) \round((float) $this->pairTime[$pair] / $this->timeDivisor) - $given,
        );
        $this->pairWt[$pair] = $given + $value;
        $this->wt[$node] = $value;
    }
}
