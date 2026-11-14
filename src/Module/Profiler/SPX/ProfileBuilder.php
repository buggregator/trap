<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler\SPX;

use Buggregator\Trap\Module\Profiler\Compaction\NodeBudget;
use Buggregator\Trap\Module\Profiler\Compaction\Nodes;
use Buggregator\Trap\Module\Profiler\Compaction\Report;
use Buggregator\Trap\Module\Profiler\Compaction\StackTree;
use Buggregator\Trap\Module\Profiler\Struct\Profile;
use Buggregator\Trap\Support\Measure;
use Buggregator\Trap\Support\Spx\FunctionName;
use Buggregator\Trap\Support\Spx\Metadata as SpxMetadata;
use Buggregator\Trap\Support\Spx\TraceReader;
use Buggregator\Trap\Support\TimeSlice;

/**
 * Builds a profile from an SPX full report: the metadata and the trace, see {@see TraceReader}.
 *
 * A node per call while the calls fit the node limit, merged by call paths otherwise. The metrics map so:
 * - SPX `wt` (wall time, ns) gives `wt` in microseconds, and SPX `ct` (CPU time, ns) gives `cpu`;
 * - SPX `zm` (Zend Engine memory usage) gives `mu`: what a call leaves allocated, never below 0;
 * - the count of a node, the profile's `ct`, is its calls. In sampling mode SPX replays only the changes
 *   of the stack at each tick, so a call that stays on the stack across ticks counts once: the counts are
 *   sampled calls, neither calls nor samples.
 * `pmu` stays 0: the trace has the memory at the starts and ends of the calls only, and a peak between them is lost.
 * The other metrics, such as GC runs, included files and I/O, are summed over the whole run and go to the
 * `details` of the metadata, with the request or the command, the custom metadata and the PID.
 *
 * SPX names file-scope code by its file: a single such root becomes `main()`.
 *
 * @link https://github.com/NoiseByNorthwest/php-spx
 *
 * @psalm-import-type Metadata from Profile
 *
 * @internal
 */
final class ProfileBuilder
{
    /** Size limit of a gzipped trace in bytes. */
    public const MAX_SIZE = 64 * 1024 * 1024;

    /** Labels of the metrics that don't fit the costs, and how to show them. */
    private const METRICS = [
        'it' => ['Idle time', 'time'],
        'zmac' => ['Allocations', 'count'],
        'zmab' => ['Allocated memory', 'bytes'],
        'zmfc' => ['Frees', 'count'],
        'zmfb' => ['Freed memory', 'bytes'],
        'zgr' => ['GC runs', 'count'],
        'zgb' => ['GC root buffer change', 'count'],
        'zgc' => ['GC collected cycles', 'count'],
        'zif' => ['Included files', 'count'],
        'zil' => ['Included lines', 'count'],
        'zuc' => ['User classes', 'count'],
        'zuf' => ['User functions', 'count'],
        'zuo' => ['User opcodes', 'count'],
        'zo' => ['Objects change', 'count'],
        'ze' => ['Errors', 'count'],
        'mor' => ['Own RSS change', 'bytes'],
        'io' => ['I/O', 'bytes'],
        'ior' => ['I/O read', 'bytes'],
        'iow' => ['I/O written', 'bytes'],
    ];

    /**
     * @param positive-int $maxSize Limit of the gzipped trace in bytes, checked by the callers that know the size.
     * @param positive-int $maxTraceSize Limit of the decompressed trace in bytes.
     * @param positive-int|null $maxNodes Limit of the tree nodes, over which the tree is compacted;
     *        by default, what the memory left affords when the build starts, see {@see NodeBudget}.
     */
    public function __construct(
        public readonly int $maxSize = self::MAX_SIZE,
        public readonly int $maxTraceSize = TraceReader::MAX_SIZE,
        public readonly ?int $maxNodes = null,
    ) {}

    /**
     * @param iterable<string> $trace The gzipped `.txt.gz` trace, in chunks.
     * @param \DateTimeInterface|null $date Defaults to the start of the profiled run.
     * @param Metadata $metadata Gets `details`, `hostname`, and `compacted` when the tree was over the node limit.
     * @param array<non-empty-string, non-empty-string> $tags Get `request` or `command` unless given.
     * @param positive-int|null $samplingPeriod Microseconds between the samples when SPX sampled the stack
     *        (`SPX_SAMPLING_PERIOD`): the metadata doesn't tell it. The counts are sampled calls then.
     *
     * @throws \InvalidArgumentException When the trace is malformed or too large.
     */
    public function createProfile(
        SpxMetadata $spx,
        iterable $trace,
        ?\DateTimeInterface $date = null,
        array $metadata = [],
        array $tags = [],
        ?int $samplingPeriod = null,
        ?TimeSlice $timeSlice = null,
    ): Profile {
        $wt = $spx->column('wt');
        $cpu = $spx->column('ct');
        $zm = $spx->column('zm');
        /** @var array<non-empty-string, int> $others Columns of the metrics that don't fit the costs */
        $others = [];
        foreach ($spx->metrics as $column => $key) {
            \in_array($key, ['wt', 'ct', 'zm'], true) or $others[$key] = $column;
        }
        /** @var array<non-empty-string, float> $totals */
        $totals = \array_fill_keys(\array_keys($others), 0.0);

        $limit = $this->maxNodes ?? NodeBudget::nodes();
        $nodes = new Nodes($limit, \max(1, $spx->functionCount), calls: true, timeSlice: $timeSlice);
        $tree = $nodes->tree;
        // More calls than the node limit: the calls of the same function on the same path are merged from the start
        $merge = $spx->callCount > $limit;
        /** @var list<list<float>> $starts Values at the start of the open calls */
        $starts = [];
        /** @var list<bool> $measured Whether an open call adds its costs: not inside a folded node, which has them */
        $measured = [];

        /** @param list<float> $values */
        $onEvent = static function (int $function, bool $start, array $values) use (
            $nodes,
            $tree,
            $merge,
            $wt,
            $cpu,
            $zm,
            $others,
            &$totals,
            &$starts,
            &$measured,
        ): void {
            if ($start) {
                $parent = $nodes->open === [] ? 0 : $nodes->open[\array_key_last($nodes->open)];
                $size = $tree->size();
                $inside = $nodes->isFolded($parent);
                $node = match (true) {
                    $inside => $parent,
                    $merge => $nodes->child($parent, $function),
                    default => $nodes->call($parent, $function),
                };
                $merge && !$inside && $tree->size() === $size && !$nodes->isFolded($node)
                    and ++$nodes->report->merged;
                ++$tree->count[$node];
                $nodes->open[] = $node;
                $starts[] = $values;
                $measured[] = !$inside;
                return;
            }

            $node = (int) \array_pop($nodes->open);
            $from = \array_pop($starts) ?? [];
            if (\array_pop($measured)) {
                $wt === null or $tree->value[$node] += ($values[$wt] - $from[$wt]) / 1000.0;
                $cpu === null or $tree->cpu[$node] += ($values[$cpu] - $from[$cpu]) / 1000.0;
                $zm === null or $tree->memory[$node] += \max(0.0, $values[$zm] - $from[$zm]);
            }
            if ($nodes->open === []) {
                foreach ($others as $key => $column) {
                    $totals[$key] += $values[$column] - $from[$column];
                }
            }
        };

        $names = TraceReader::read($trace, \count($spx->metrics), $spx->functionCount, $onEvent, $this->maxTraceSize, $timeSlice);
        unset($onEvent);
        $nodes->compact();

        $fileScope = [];
        foreach ($names as $name) {
            FunctionName::isFileScope($name) and $fileScope[$name] = true;
        }
        [$root, $rootName] = StackTree::root($nodes, $names, $fileScope, evented: true)
            ?? throw new \InvalidArgumentException('The trace has no calls.');
        if (!$nodes->report->isEmpty()) {
            $nodes->report->measure($nodes->tree);
            $metadata['compacted'] = $nodes->report->toArray() + ['limit' => $limit];
        }

        $metadata['details'] = self::details($spx, $totals, $samplingPeriod);
        $samplingPeriod === null or $metadata['sampling_period'] = $samplingPeriod;
        ($host = $spx->string('host_name')) === null or $metadata += ['hostname' => $host];
        $tags += self::tags($spx);
        $execTs = $spx->int('exec_ts');
        $date ??= $execTs === null ? new \DateTimeImmutable() : new \DateTimeImmutable('@' . $execTs);

        $tree = StackTree::build($nodes, $root, $rootName, $names, ['wt' => 'value', 'cpu' => 'cpu', 'mu' => 'memory']);
        unset($nodes);

        /** @var Metadata $metadata */
        return Profile::fromTree($tree, $date, $metadata, $tags, $timeSlice);
    }

    /**
     * @param array<non-empty-string, float> $totals
     *
     * @return array<non-empty-string, string>
     */
    private static function details(SpxMetadata $spx, array $totals, ?int $samplingPeriod): array
    {
        $details = [];
        if ($spx->isCli()) {
            ($command = $spx->string('cli_command_line')) === null or $details['Command'] = $command;
        } else {
            $request = \trim(($spx->string('http_method') ?? '') . ' '
                . ($spx->string('http_host') ?? '') . ($spx->string('http_request_uri') ?? ''));
            $request === '' or $details['Request'] = $request;
        }
        ($custom = $spx->string('custom_metadata_str')) === null or $details['Custom metadata'] = $custom;
        ($pid = $spx->int('process_pid')) === null or $details['PID'] = (string) $pid;
        $spx->metrics === [] or $details['Metrics'] = \implode(', ', $spx->metrics);
        $samplingPeriod === null or $details['Sampling period'] = self::time((float) $samplingPeriod * 1000.0);

        foreach ($totals as $key => $total) {
            [$label, $unit] = self::METRICS[$key] ?? [$key, 'count'];
            $bytes = (int) \abs($total);
            $details[$label] = match ($unit) {
                'time' => self::time($total),
                'bytes' => ($total < 0 ? '-' : '') . ($bytes > 0 ? Measure::memory($bytes) : '0 B'),
                default => \number_format($total, \fmod($total, 1.0) === 0.0 ? 0 : 2, '.', ''),
            };
        }

        return $details;
    }

    /**
     * @return array<non-empty-string, non-empty-string>
     */
    private static function tags(SpxMetadata $spx): array
    {
        $tag = $spx->isCli()
            ? ['command', $spx->string('cli_command_line')]
            : ['request', $spx->string('http_request_uri')];
        if ($tag[1] === null) {
            return [];
        }

        $value = \trim(($spx->isCli() ? '' : $spx->string('http_method') ?? '') . ' ' . \mb_strimwidth($tag[1], 0, 120, '...'));

        return $value === '' ? [] : [$tag[0] => $value];
    }

    /**
     * @return non-empty-string
     */
    private static function time(float $nanoseconds): string
    {
        $microseconds = $nanoseconds / 1000.0;
        return match (true) {
            \abs($microseconds) < 1_000 => \sprintf('%d µs', (int) \round($microseconds)),
            \abs($microseconds) < 1_000_000 => \sprintf('%.2f ms', $microseconds / 1e3),
            default => \sprintf('%.2f s', $microseconds / 1e6),
        };
    }
}
