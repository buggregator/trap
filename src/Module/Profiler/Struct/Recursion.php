<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler\Struct;

use Buggregator\Trap\Support\TimeSlice;

/**
 * Recursion levels of function IDs: `foo` is level 0, `foo@2` level 2, see {@see Profile::fromTree()}.
 *
 * @internal
 */
final class Recursion
{
    /**
     * @return int<0, max> The recursion level of a function ID.
     */
    public static function level(?string $id, ?string $name): int
    {
        return $id === null || $id === $name ? 0 : \max(0, (int) \substr($id, \strlen((string) $name) + 1));
    }

    /**
     * The calls taken as a part of an outer call of the same pair, as they were made deeper in both functions'
     * recursions: summed, a recursion would count many times over. A call deeper in one function only, as `g@1()`
     * calling `f@1` and `g()` calling `f@1` too, is apart from the others.
     *
     * An approximation, for the call graphs with no recorded tree to tell how the calls nest: a call may be deeper
     * because the recursion came through another function, and its cost is then left out.
     *
     * @param list<array{int<0, max>, int<0, max>}> $levels The caller and callee levels of all the calls
     *        between two functions.
     *
     * @return array<int<0, max>, true> By the indexes in the levels.
     */
    public static function nested(array $levels, ?TimeSlice $timeSlice = null): array
    {
        $callerLevels = \array_column($levels, 0);
        $calleeLevels = \array_column($levels, 1);
        $order = \array_keys($levels);
        $sorted = $callerLevels;
        \array_multisort($sorted, \SORT_ASC, \SORT_NUMERIC, $order);

        // A call is nested when a call of a smaller caller level has a smaller callee level
        $nested = [];
        $min = $levelMin = \PHP_INT_MAX;
        $level = null;
        foreach ($order as $i) {
            if ($callerLevels[$i] !== $level) {
                $min = \min($min, $levelMin);
                $levelMin = \PHP_INT_MAX;
                $level = $callerLevels[$i];
            }
            $min < $calleeLevels[$i] and $nested[$i] = true;
            $levelMin = \min($levelMin, $calleeLevels[$i]);
            $timeSlice?->tick();
        }

        return $nested;
    }
}
