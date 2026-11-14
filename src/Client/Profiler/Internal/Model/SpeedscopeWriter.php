<?php

declare(strict_types=1);

namespace Buggregator\Trap\Client\Profiler\Internal\Model;

/**
 * Writes a {@see CallTree} as a sampled Speedscope document: a sample per call path with the self wall time
 * as its weight in nanoseconds, rooted at `main()` as in the XHProf conversion. Paths with no self time are left out.
 *
 * @internal
 */
final class SpeedscopeWriter
{
    public const SCHEMA = 'https://www.speedscope.app/file-format-schema.json';

    /**
     * @param non-empty-string $name
     */
    public static function json(CallTree $tree, string $name, string $exporter): string
    {
        /** @var array<string, int> $frameIds By the name and the file. */
        $frameIds = [];
        $frames = [];
        // A node keeps its frame and parent only: the stacks are made for the samples, not for every node
        /** @var array<int, array{int, int|null}> $nodes Frame and parent by node id. */
        $nodes = [];
        $samples = [];
        $weights = [];
        $total = 0;

        $tree->walk(static function (int $id, ?int $parent, string $name, ?string $file, int $wt) use (
            &$frameIds,
            &$frames,
            &$nodes,
            &$samples,
            &$weights,
            &$total,
        ): void {
            $key = "$name\0$file";
            if (!isset($frameIds[$key])) {
                $frameIds[$key] = \count($frames);
                $frames[] = $file === null ? ['name' => $name] : ['name' => $name, 'file' => $file];
            }
            $nodes[$id] = [$frameIds[$key], $parent];
            if ($wt > 0) {
                $samples[] = \array_reverse(self::path($nodes, $id));
                $weights[] = $wt;
                $total += $wt;
            }
        });

        return \json_encode([
            '$schema' => self::SCHEMA,
            'shared' => ['frames' => $frames],
            'profiles' => [[
                'type' => 'sampled',
                'name' => $name,
                'unit' => 'nanoseconds',
                'startValue' => 0,
                'endValue' => $total,
                'samples' => $samples,
                'weights' => $weights,
            ]],
            'name' => $name,
            'exporter' => $exporter,
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * Collapsed (folded) stacks: a `frame;frame;frame value` line per call path with the self wall time
     * in microseconds as the value, as flame graph tools read it.
     */
    public static function collapsed(CallTree $tree): string
    {
        /** @var array<int, array{string, int|null}> $nodes Name and parent by node id. */
        $nodes = [];
        $lines = '';
        $tree->walk(static function (int $id, ?int $parent, string $name, ?string $file, int $wt) use (&$nodes, &$lines): void {
            // The separators of the format can't be part of a name
            $nodes[$id] = [\strtr($name, [';' => '_', "\n" => ' ']), $parent];
            $microseconds = \intdiv($wt + 500, 1000);
            $microseconds > 0 and $lines .= \implode(';', \array_reverse(self::path($nodes, $id))) . " $microseconds\n";
        });

        return $lines;
    }

    /**
     * @template T
     *
     * @param array<int, array{T, int|null}> $nodes Values and parents by node ids.
     *
     * @return list<T> The values from the node up to the root.
     */
    private static function path(array $nodes, int $id): array
    {
        $path = [];
        for ($node = $id; $node !== null; $node = $nodes[$node][1]) {
            $path[] = $nodes[$node][0];
        }

        return $path;
    }
}
