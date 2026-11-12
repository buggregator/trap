<?php

declare(strict_types=1);

namespace Buggregator\Trap\Client\Profiler\Internal\Model;

/**
 * Builds a {@see CallTree} from a sampled Speedscope profile, as Excimer produces it.
 *
 * Frame names are taken as they are, so the tree agrees with the Speedscope and Collapsed output.
 * Calls are not measured by sampling: the tree has wall time, samples and, for a CPU clock, CPU time.
 * Of several profiles, the active one is read, or the first. Zero-weight samples are skipped;
 * samples with an empty stack (no user code was running) count in the `main()` total only.
 *
 * @psalm-type SpeedscopeDocument = array{
 *     shared?: array{frames?: array<array-key, array{name?: string, file?: string, line?: int}>},
 *     profiles?: list<array{type?: string, unit?: string, samples?: list<list<int<0, max>>>, weights?: list<int|float>}>,
 *     activeProfileIndex?: int
 * }
 *
 * @internal
 */
final class SpeedscopeReader
{
    /** Nanoseconds in a time unit. */
    private const UNITS = [
        'nanoseconds' => 1.0,
        'microseconds' => 1_000.0,
        'milliseconds' => 1_000_000.0,
        'seconds' => 1_000_000_000.0,
    ];

    /**
     * @param SpeedscopeDocument $document
     * @param float $period Sampling period in seconds: the time of a unitless weight, the divisor of a timed one.
     * @param bool $cpu Whether the samples were taken by CPU time; it then counts as CPU time too.
     */
    public static function read(array $document, float $period, bool $cpu): CallTree
    {
        $tree = new CallTree();

        $profiles = $document['profiles'] ?? [];
        $profile = $profiles[$document['activeProfileIndex'] ?? 0] ?? $profiles[0] ?? [];
        if (($profile['type'] ?? null) !== 'sampled') {
            return $tree;
        }

        $names = [];
        $files = [];
        foreach ($document['shared']['frames'] ?? [] as $index => $frame) {
            $name = $frame['name'] ?? '';
            $names[$index] = $name === '' ? '(unknown)' : $name;
            $files[$index] = $frame['file'] ?? null;
        }

        $weights = $profile['weights'] ?? [];
        $scale = self::UNITS[$profile['unit'] ?? 'none'] ?? null;
        $periodNanoseconds = $period * 1e9;

        // Consecutive samples share most of their stack: the nodes of the common prefix are reused.
        $previous = [];
        $nodes = [];
        foreach ($profile['samples'] ?? [] as $i => $stack) {
            $weight = (float) ($weights[$i] ?? 1);
            if ($weight <= 0.0) {
                continue;
            }

            if ($scale === null) {
                // A unitless weight is an event count.
                $nanoseconds = $weight * $periodNanoseconds;
                $samples = (int) \round($weight);
            } else {
                // Excimer weighs a stack by its event count times the period, so the count is their quotient.
                $nanoseconds = $weight * $scale;
                $samples = \max(1, (int) \round($nanoseconds / $periodNanoseconds));
            }

            $time = \max(0, (int) \round($nanoseconds));

            $depth = \count($stack);
            $common = 0;
            $limit = \min($depth, \count($previous));
            while ($common < $limit && $stack[$common] === $previous[$common]) {
                ++$common;
            }

            $nodes = \array_slice($nodes, 0, $common);
            $node = $common === 0 ? CallTree::ROOT : $nodes[$common - 1];
            for ($j = $common; $j < $depth; ++$j) {
                $index = $stack[$j];
                $node = $tree->node($node, $index, $names[$index] ?? '(unknown)', $files[$index] ?? null);
                $nodes[] = $node;
            }

            $tree->add($node, $time, $cpu ? $time : 0, \max(0, $samples));
            $previous = $stack;
        }

        return $tree;
    }
}
