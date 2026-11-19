<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler\XDebug;

use Buggregator\Trap\Module\Profiler\Struct\Cost;
use Buggregator\Trap\Support\TimeSlice;

/**
 * Links the calls of one profile to their callers as they end: the calls made from a call are the last ones
 * ended before it in its stack. A call takes a few integers in flat arrays, indexed in the order the calls end.
 *
 * Times are rounded to microseconds so that the sum over the calls of a caller-callee pair stays exact:
 * rounding each call on its own adds up to a large error over many sub-microsecond calls,
 * 1000 calls of 0.5 µs would take 1000 µs or none. So the rounding error of a pair is carried over to its next call.
 * A call never takes less than its children, to keep the exclusive times non-negative;
 * the excess is taken back from the next calls of the pair.
 *
 * Fibers: Xdebug 3.4.0 writes the calls of all the fiber stacks into one log in the order they end, with no fiber ID.
 * The switches still show: the end of `Fiber::suspend()` means a fiber went on, the next end of `Fiber->resume()`
 * or `Fiber->throw()` means the stack that resumed it went on. So the waiting calls are kept by stacks, and
 * a call's callees are looked for in its own stack only. Two gaps remain, filled by matching the names and
 * inclusive times the callers list:
 * - which suspended fiber a `Fiber::suspend()` end belongs to: the stack goes on as a new one until a call in it
 *   lists calls ended before, then it is joined to the suspended stack whose last calls those are, the latest
 *   suspended first. Fibers that run the same calls for the same times can't be told apart and may be swapped;
 * - the calls a fiber makes in `Fiber->start()` before it first suspends, which end with no switch between those
 *   of the starting stack: such a window is left in the starting stack, its calls may be skipped by the callers
 *   there, and the fiber's stack takes them from the top of the window.
 * A fiber destroyed while suspended ends its calls with no switch back: when the stack that destroyed it goes on,
 * its calls are put back to that stack once they no longer fit the fiber's.
 *
 * The function a fiber runs is listed by no caller and stays a root, while its cost also counts in the
 * `Fiber->start()` or `Fiber->resume()` call that ran it; such roots, of the fiber stacks, are kept in `$fibers`.
 * A call whose callees are not found takes their listed cost as its own and is counted in `$unlinked`; the calls left
 * without a caller stay roots, so the cost of the listed calls that are in the log counts twice.
 *
 * @internal
 */
final class CallLinker
{
    private const SUSPEND = 'php::Fiber::suspend';
    private const START = 'php::Fiber->start';
    private const RESUMES = ['php::Fiber->resume' => true, 'php::Fiber->throw' => true];

    /** Failed tries of suspended stacks per call: a call whose callees are not found within them is unlinked. */
    private const BUDGET = 10_000;

    /** @var list<non-empty-string> Function names by IDs */
    public array $names = [];

    /** @var list<int> Function IDs of the calls */
    public array $function = [];

    /** @var array<int, int> Callers of the calls; -1 for a root */
    public array $caller = [];

    /** @var array<int, int> Inclusive microseconds of the calls */
    public array $wt = [];

    /** Calls whose callees were not found among the calls ended before them. */
    public int $unlinked = 0;

    /** @var list<int> Roots that fibers ran: their cost counts in other roots too */
    public array $fibers = [];

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

    /** @var array<int, array<int, int>> Calls waiting for their caller by stacks, in the order they ended; stack 0 is the main one */
    private array $ended = [0 => []];

    /** @var array<int, array<int, int>> Inclusive time of the waiting calls, in the profile units, in the order of `$ended` */
    private array $time = [0 => []];

    /** @var array<int, array<int, int>> Microseconds of the callees of the waiting calls, in the order of `$ended` */
    private array $calleesWt = [0 => []];

    /** @var array<int, array<int, int>> Calls in the subtrees of the waiting calls, in the order of `$ended` */
    private array $size = [0 => []];

    private int $stacks = 1;
    private int $current = 0;

    /** @var list<int> Stacks that resumed the fibers running, the innermost last */
    private array $resumers = [];

    /** @var array<int, int> First call ended since each stack last went on */
    private array $since = [0 => 0];

    /**
     * Stacks of started fibers: the stack that started it, and the bounds of the `Fiber->start()` window in it,
     * the first call that may be the fiber's and the `Fiber->start()` call.
     *
     * @var array<int, array{int, int, int}>
     */
    private array $origin = [];

    /** @var array<int, array<int, true>> Stacks of started fibers by the stacks that started them */
    private array $started = [];

    /** @var array<int, true> Stacks of resumed fibers not joined to the suspended stack they go on yet */
    private array $unbound = [];

    /** @var array<string, array<int, true>> Suspended stacks by the function ID and time of their top call */
    private array $suspended = [];

    /** @var array<int, string> Keys of the suspended stacks in `$suspended` */
    private array $suspendedKey = [];

    /** @var array<int, int> Calls ended in a `Fiber->start()` window: the `Fiber->start()` call of the innermost one */
    private array $window = [];

    /** @var array<int, int> The call of a fiber stack that took all the other calls of the stack: maybe its function */
    private array $fiberEnd = [];

    /** @var array<int, true> Calls ended right before a `Fiber->start()`: a fiber's function, if it ended there */
    private array $beforeStart = [];

    /** @var list<array{int, int, int, int}> Calls left without a caller by an unlinked call: node, time, callees' wt, size */
    private array $orphans = [];

    /** @var array<int, array<int, int>> Inclusive time of the caller-callee pairs by function IDs, in the profile units */
    private array $pairTime = [];

    /** @var array<int, array<int, int>> Microseconds given to the calls of the pairs by function IDs */
    private array $pairWt = [];

    /**
     * @param float $timeDivisor Divisor of the time event to microseconds.
     */
    public function __construct(
        private readonly float $timeDivisor,
        private readonly ?TimeSlice $timeSlice = null,
    ) {}

    public function end(Call $call): void
    {
        $name = $call->name;
        $node = \count($this->function);
        if ($name === self::SUSPEND) {
            $this->resumers[] = $this->current;
            $stack = $this->stacks++;
            $this->ended[$stack] = $this->time[$stack] = $this->calleesWt[$stack] = $this->size[$stack] = [];
            $this->unbound[$stack] = true;
            $this->current = $stack;
            $this->since[$stack] = $node;
        } elseif (isset(self::RESUMES[$name]) && $this->resumers !== []) {
            $this->leave($node);
        }

        $plan = $this->find($call) ?? $this->findInResumer($call, $node);
        $this->function[] = $function = $this->ids[$name] ??= \array_push($this->names, $name) - 1;
        $this->caller[] = -1;
        $this->wt[] = 0;
        $this->offset[] = 0;
        $time = $call->time;
        $memory = $call->memory;
        $calleesWt = 0;
        $size = 1;
        if ($plan === null) {
            ++$this->unlinked;
            foreach ($call->calleeTime as $i => $calleeTime) {
                $time = Cost::saturate($time + $calleeTime);
                $memory += $call->calleeMemory[$i];
            }
            $this->orphan($call);
        } else {
            foreach ($this->take($plan) as [$child, $childTime, $childCalleesWt, $childSize]) {
                $this->round($child, $function, $childTime, $childCalleesWt);
                $time = Cost::saturate($time + $childTime);
                $memory += $this->memory[$child];
                $calleesWt = Cost::saturate($calleesWt + $this->wt[$child]);
                $this->caller[$child] = $node;
                $this->offset[$child] = $size;
                $size += $childSize;
            }
        }

        $stack = $this->current;
        $this->memory[] = $memory;
        $this->ended[$stack][] = $node;
        $this->time[$stack][] = $time;
        $this->calleesWt[$stack][] = $calleesWt;
        $this->size[$stack][] = $size;
        $this->resumers !== [] && $plan !== null && $plan[0] !== [] && \count($this->ended[$stack]) === 1
            and $this->fiberEnd[$stack] = $node;
        $name === self::START and $this->start($node);
        $this->timeSlice?->tick();
    }

    /**
     * Rounds the calls that ended with no caller.
     */
    public function finish(): void
    {
        $roots = $this->orphans;
        $fiber = [];
        foreach ($this->ended as $stack => $nodes) {
            foreach ($nodes as $at => $node) {
                $roots[] = [$node, $this->time[$stack][$at], $this->calleesWt[$stack][$at], $this->size[$stack][$at]];
                ($stack !== 0 || isset($this->beforeStart[$node])) and $fiber[$node] = true;
            }
        }
        \usort($roots, static fn(array $a, array $b): int => $a[0] <=> $b[0]);

        $offset = 0;
        foreach ($roots as [$node, $time, $calleesWt, $size]) {
            $this->round($node, -1, $time, $calleesWt);
            isset($fiber[$node]) and $this->fibers[] = $node;
            $this->offset[$node] = $offset;
            $offset += $size;
        }
        $this->ended = $this->time = $this->calleesWt = $this->size = $this->pairTime = $this->pairWt = [];
        $this->since = $this->origin = $this->started = $this->unbound = $this->suspended = $this->suspendedKey = [];
        $this->window = $this->fiberEnd = $this->beforeStart = $this->orphans = $this->resumers = [];
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

    /**
     * The fiber running suspended or ended: the stack that resumed it goes on.
     */
    private function leave(int $node): void
    {
        $this->suspend($this->current);
        $this->current = \array_pop($this->resumers) ?? 0;
        $this->since[$this->current] = $node;
    }

    /**
     * The fiber started by the call has suspended or ended: its calls so far are on top of the current stack.
     */
    private function start(int $node): void
    {
        $stack = $this->stacks++;
        $this->ended[$stack] = $this->time[$stack] = $this->calleesWt[$stack] = $this->size[$stack] = [];
        $starter = $this->current;
        $low = $this->since[$starter];
        $this->origin[$stack] = [$starter, $low, $node];
        $this->started[$starter][$stack] = true;
        $ended = $this->ended[$starter];
        for ($at = \count($ended) - 2; $at >= 0 && $ended[$at] >= $low; --$at) {
            $this->window[$ended[$at]] ??= $node;
        }
        $node > 0 && \count($ended) > 1 && $ended[\count($ended) - 2] === $node - 1 and $this->beforeStart[$node - 1] = true;
        $this->suspend($stack);
    }

    /**
     * Keeps a stack that does not run until a fiber stack joins it.
     */
    private function suspend(int $stack): void
    {
        $key = $this->topKey($stack);
        if ($key === null) {
            // Nothing to join: its calls are taken, or the fiber made none before it first suspended
            return;
        }
        $this->suspended[$key][$stack] = true;
        $this->suspendedKey[$stack] = $key;
    }

    private function unsuspend(int $stack): void
    {
        $key = $this->suspendedKey[$stack] ?? null;
        if ($key !== null) {
            unset($this->suspended[$key][$stack], $this->suspendedKey[$stack]);
            if ($this->suspended[$key] === []) {
                unset($this->suspended[$key]);
            }
        }
    }

    /**
     * @return string|null Function ID and time of the top call of the stack, of the window it started from when empty.
     */
    private function topKey(int $stack): ?string
    {
        $top = \count($this->ended[$stack]) - 1;
        if ($top >= 0) {
            return $this->function[$this->ended[$stack][$top]] . ':' . $this->time[$stack][$top];
        }
        if (!isset($this->origin[$stack])) {
            return null;
        }
        [$starter, $low, $start] = $this->origin[$stack];
        $at = $this->below($starter, $start);

        return $at >= 0 && $this->ended[$starter][$at] >= $low
            ? $this->function[$this->ended[$starter][$at]] . ':' . $this->time[$starter][$at]
            : null;
    }

    /**
     * @return int Position in the stack of the last call ended before the given one; -1 when none.
     */
    private function below(int $stack, int $node): int
    {
        $ended = $this->ended[$stack];
        $low = 0;
        $high = \count($ended) - 1;
        while ($low <= $high) {
            $middle = ($low + $high) >> 1;
            $ended[$middle] < $node ? $low = $middle + 1 : $high = $middle - 1;
        }

        return $high;
    }

    /**
     * @return array{list<array{int, int}>, list<int>}|null The callees as stacks and positions, from the last one,
     *         and the suspended stacks the current one joins, nearest first; null when they are not all there.
     */
    private function find(Call $call): ?array
    {
        if ($call->callees === []) {
            return [[], []];
        }

        $plan = $this->search($call);
        if ($plan !== null) {
            return $plan;
        }

        // Costs that do not add up, as in hand-made profiles: the callees on top of the stack are trusted by their names
        $count = \count($call->callees);
        $ended = $this->ended[$this->current];
        $top = \count($ended) - 1;
        if ($count > $top + 1) {
            return null;
        }
        $taken = [];
        for ($i = $count - 1, $at = $top; $i >= 0; --$i, --$at) {
            if ($this->names[$this->function[$ended[$at]]] !== $call->callees[$i]) {
                return null;
            }
            $taken[] = [$this->current, $at];
        }

        return [$taken, []];
    }

    /**
     * A fiber destroyed while suspended ends its calls with no switch back, so the stack that destroyed it goes on
     * unmarked: when a call does not fit the fiber's stack, the calls ended after the fiber's function are put back
     * to that stack, and the call is looked for there.
     *
     * @return array{list<array{int, int}>, list<int>}|null
     */
    private function findInResumer(Call $call, int $node): ?array
    {
        $fiber = $this->current;
        $end = $this->fiberEnd[$fiber] ?? null;
        if ($end === null || $this->resumers === []) {
            return null;
        }
        $from = $this->below($fiber, $end + 1);
        if ($from < 0 || $this->ended[$fiber][$from] !== $end) {
            return null;
        }

        $resumer = \array_pop($this->resumers);
        $since = $this->since[$resumer];
        $moved = \count($this->ended[$fiber]) - $from - 1;
        $this->move($fiber, $from + 1, $resumer);
        $this->current = $resumer;
        $this->since[$resumer] = $moved > 0 ? $this->ended[$resumer][\count($this->ended[$resumer]) - $moved] : $node;
        $plan = $this->find($call);
        if ($plan !== null) {
            $this->suspend($fiber);

            return $plan;
        }

        $this->move($resumer, \count($this->ended[$resumer]) - $moved, $fiber);
        $this->current = $fiber;
        $this->since[$resumer] = $since;
        $this->resumers[] = $resumer;

        return null;
    }

    /**
     * Takes the callees `0..$i` of the call from the top of a stack down, then from the stack it goes on.
     * A depth-first walk over the suspended stacks, with no recursion: a fiber that suspended a thousand times
     * in one call leaves a thousand stacks to join.
     *
     * @return array{list<array{int, int}>, list<int>}|null
     */
    private function search(Call $call): ?array
    {
        $taken = $chain = [];
        $i = \count($call->callees) - 1;
        $found = $this->walk($call, $i, $this->current, $taken);
        if ($found !== 3) {
            return $found === 0 ? null : [$taken, []];
        }

        $budget = self::BUDGET;
        $partial = null;
        /** @var list<array{list<int>, int, int, int, string}> $levels Candidates, the one tried, `$i`, `$taken` count, key */
        $levels = [$this->candidates($call, $i, $taken)];
        while ($levels !== []) {
            $level = \count($levels) - 1;
            [$candidates, $n, $i, $taking] = $levels[$level];
            if ($n >= \count($candidates) || $budget <= 0) {
                // No stack goes on with the rest: the stack tried a level up does not fit
                \array_pop($levels);
                $level === 0 or $this->reject($levels[$level - 1], $taken, $chain, $budget);
                continue;
            }
            $candidate = $candidates[$n];
            if (!isset($this->suspendedKey[$candidate])) {
                ++$levels[$level][1];
                continue;
            }

            $this->unsuspend($candidate);
            $chain[] = $candidate;
            $found = $this->walk($call, $i, $candidate, $taken);
            if ($found === 2) {
                return [$taken, $chain];
            }
            if ($found === 3) {
                $levels[] = $this->candidates($call, $i, $taken);
                continue;
            }
            $found === 1 and $partial ??= [$taken, $chain];
            $this->reject($levels[$level], $taken, $chain, $budget);
        }

        return $partial;
    }

    /**
     * The fiber went on from one of the suspended stacks whose top calls are the rest. The latest one first:
     * a fiber that suspends in a loop goes on from the stack it left last; the others are listed when it does not fit.
     *
     * @param list<array{int, int}> $taken
     *
     * @return array{list<int>, int, int, int, string}
     */
    private function candidates(Call $call, int $i, array $taken): array
    {
        $id = $this->ids[$call->callees[$i]] ?? null;
        $key = $id === null ? '' : $id . ':' . $call->calleeTime[$i];
        $latest = \array_key_last($this->suspended[$key] ?? []);

        return [$latest === null ? [] : [$latest], 0, $i, \count($taken), $key];
    }

    /**
     * Puts back the stack tried at the level, which did not fit, and moves to the next one.
     *
     * @param array{list<int>, int, int, int, string} $level
     * @param list<array{int, int}> $taken
     * @param list<int> $chain
     */
    private function reject(array &$level, array &$taken, array &$chain, int &$budget): void
    {
        [$candidates, $n, , $taking, $key] = $level;
        $candidate = $candidates[$n];
        \array_splice($taken, $taking);
        while ($chain !== [] && \array_pop($chain) !== $candidate) {
            // The stacks joined below it were put back already
        }
        $this->suspend($candidate);
        --$budget;
        $n === 0 and \array_push($level[0], ...\array_reverse(\array_slice(\array_keys($this->suspended[$key]), 0, -1)));
        ++$level[1];
    }

    /**
     * Takes the callees `0..$i` of the call from the top of the stack down, and from the window the stack started from.
     *
     * @param list<array{int, int}> $taken Gets the callees found, as stacks and positions.
     *
     * @return int<0, 3> 0 when not all found; 3 when the stack ran out and goes on from a suspended stack not known yet;
     *         2 when found; 1 when found with calls left above the bottom of such a stack, a worse match than
     *         another stack that fits whole.
     */
    private function walk(Call $call, int &$i, int $stack, array &$taken): int
    {
        $ended = $this->ended[$stack];
        for ($at = \count($ended) - 1; $at >= 0; --$at) {
            if ($this->isCallee($call, $i, $stack, $at)) {
                $taken[] = [$stack, $at];
                if (--$i < 0) {
                    return $at > 0 && isset($this->unbound[$stack]) ? 1 : 2;
                }
            } elseif (!isset($this->window[$ended[$at]])) {
                return 0;
            }
        }

        if (isset($this->origin[$stack])) {
            // The calls the fiber made before it first suspended, on top of the window; below them are the starter's
            [$starter, $low, $start] = $this->origin[$stack];
            for ($at = $this->below($starter, $start); $at >= 0; --$at) {
                $node = $this->ended[$starter][$at];
                if ($node < $low) {
                    return 0;
                }
                if ($this->isCallee($call, $i, $starter, $at)) {
                    $taken[] = [$starter, $at];
                    if (--$i < 0) {
                        return 2;
                    }
                } elseif (($this->window[$node] ?? $start) === $start) {
                    // Not in the window of a fiber started within this one
                    return 0;
                }
            }

            return 0;
        }

        return isset($this->unbound[$stack]) ? 3 : 0;
    }

    private function isCallee(Call $call, int $i, int $stack, int $at): bool
    {
        return $this->time[$stack][$at] === $call->calleeTime[$i]
            && $this->names[$this->function[$this->ended[$stack][$at]]] === $call->callees[$i];
    }

    /**
     * Removes the callees found from the waiting calls and joins the stacks the walk went through.
     *
     * @param array{list<array{int, int}>, list<int>} $plan
     *
     * @return list<array{int, int, int, int}> The callees in the call order: node, time, callees' wt, size.
     */
    private function take(array $plan): array
    {
        [$taken, $chain] = $plan;
        $children = $positions = [];
        foreach (\array_reverse($taken) as [$stack, $at]) {
            $children[] = [$this->ended[$stack][$at], $this->time[$stack][$at], $this->calleesWt[$stack][$at], $this->size[$stack][$at]];
            $positions[$stack][] = $at;
            unset($this->window[$this->ended[$stack][$at]]);
        }
        $joined = \array_flip($chain);
        foreach ($positions as $stack => $at) {
            \sort($at);
            $this->remove($stack, $at);
            isset($this->suspendedKey[$stack]) && !isset($joined[$stack]) and $this->reindex($stack);
        }

        if ($chain !== []) {
            // The current stack goes on the joined ones: the farthest takes the calls of the nearer ones
            $current = $this->current;
            $since = $this->since[$current];
            $into = \array_pop($chain);
            $this->unsuspend($into);
            foreach ([...\array_reverse($chain), $current] as $stack) {
                $this->unsuspend($stack);
                $this->move($stack, 0, $into);
                foreach ($this->started[$stack] ?? [] as $fiber => $_) {
                    $this->origin[$fiber][0] = $into;
                    $this->started[$into][$fiber] = true;
                }
                unset($this->ended[$stack], $this->time[$stack], $this->calleesWt[$stack], $this->size[$stack]);
                unset($this->origin[$stack], $this->unbound[$stack], $this->fiberEnd[$stack], $this->since[$stack]);
                unset($this->started[$stack]);
            }
            $this->since[$into] = $since;
            $this->current = $into;
        }

        return $children;
    }

    private function reindex(int $stack): void
    {
        $this->unsuspend($stack);
        $this->suspend($stack);
    }

    /**
     * Takes the waiting calls on top of the current stack named as the callees of an unlinked call,
     * so they do not stand between the callees of its caller.
     */
    private function orphan(Call $call): void
    {
        $stack = $this->current;
        $ended = $this->ended[$stack];
        $positions = [];
        for ($i = \count($call->callees) - 1, $at = \count($ended) - 1; $i >= 0 && $at >= 0; --$at) {
            if ($this->names[$this->function[$ended[$at]]] === $call->callees[$i]) {
                $positions[] = $at;
                --$i;
            } elseif (!isset($this->window[$ended[$at]])) {
                break;
            }
        }
        if ($positions === []) {
            return;
        }

        $positions = \array_reverse($positions);
        foreach ($positions as $at) {
            $this->orphans[] = [$ended[$at], $this->time[$stack][$at], $this->calleesWt[$stack][$at], $this->size[$stack][$at]];
            unset($this->window[$ended[$at]]);
        }
        $this->remove($stack, $positions);
    }

    /**
     * Removes the linked calls from the waiting ones of a stack, keeping the order of the rest.
     *
     * @param non-empty-list<int> $positions Ascending.
     */
    private function remove(int $stack, array $positions): void
    {
        $linked = \array_flip($positions);
        // Taken out, so that the only reference is changed in place
        $ended = $this->ended[$stack];
        $time = $this->time[$stack];
        $calleesWt = $this->calleesWt[$stack];
        $size = $this->size[$stack];
        unset($this->ended[$stack], $this->time[$stack], $this->calleesWt[$stack], $this->size[$stack]);
        $count = \count($ended);
        $to = $positions[0];
        for ($at = $to; $at < $count; ++$at) {
            if (!isset($linked[$at])) {
                $ended[$to] = $ended[$at];
                $time[$to] = $time[$at];
                $calleesWt[$to] = $calleesWt[$at];
                $size[$to] = $size[$at];
                ++$to;
            }
        }
        // Not `array_splice()`: it copies the rest, and a function may make a million calls
        for (; $to < $count; ++$to) {
            \array_pop($ended);
            \array_pop($time);
            \array_pop($calleesWt);
            \array_pop($size);
        }
        $this->ended[$stack] = $ended;
        $this->time[$stack] = $time;
        $this->calleesWt[$stack] = $calleesWt;
        $this->size[$stack] = $size;
    }

    /**
     * Moves the waiting calls of a stack from the position up onto the top of another stack.
     */
    private function move(int $from, int $at, int $to): void
    {
        \array_push($this->ended[$to], ...\array_splice($this->ended[$from], $at));
        \array_push($this->time[$to], ...\array_splice($this->time[$from], $at));
        \array_push($this->calleesWt[$to], ...\array_splice($this->calleesWt[$from], $at));
        \array_push($this->size[$to], ...\array_splice($this->size[$from], $at));
    }

    /**
     * @param int $caller Function ID of the caller; -1 for a root.
     * @param int $time Inclusive time of the call, in the profile units.
     * @param int $calleesWt Microseconds of its callees.
     */
    private function round(int $node, int $caller, int $time, int $calleesWt): void
    {
        $callee = $this->function[$node];
        $time += $this->pairTime[$caller][$callee] ?? 0;
        $this->pairTime[$caller][$callee] = $time;
        $given = $this->pairWt[$caller][$callee] ?? 0;
        $value = \max($calleesWt, Cost::saturate(\round((float) $time / $this->timeDivisor)) - $given);
        $this->pairWt[$caller][$callee] = $given + $value;
        $this->wt[$node] = $value;
    }
}
