<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler\Pprof;

use Buggregator\Trap\Module\Profiler\Compaction\NodeBudget;
use Buggregator\Trap\Module\Profiler\Compaction\Nodes;
use Buggregator\Trap\Module\Profiler\Compaction\Report;
use Buggregator\Trap\Module\Profiler\Compaction\StackTree;
use Buggregator\Trap\Module\Profiler\Struct\Profile;
use Buggregator\Trap\Support\TimeSlice;

/**
 * Builds a profile from a pprof profile, such as Go's `runtime/pprof` or the Datadog profiler writes:
 * gzip-compressed or raw protobuf.
 *
 * A pprof profile is sampled: the samples with the same stack path are merged into a node, as in
 * a sampled speedscope profile, and the roots are taken as {@see StackTree} tells.
 *
 * The sample types give the costs by their units:
 * - `count` and `samples` give `ct`; with none, a sample counts once;
 * - time units give `cpu` when the type names the CPU, `wt` otherwise; `wt` repeats the CPU time
 *   when there is no other time;
 * - `bytes` give `mu`, inclusive like the memory of an Xdebug call;
 * - other types are not shown.
 * Of several types for a cost, the default sample type is taken (the last type when the profile tells none,
 * as `profile.proto` says), then a type of the same prefix (`inuse_objects` for `inuse_space`), then the first one.
 * Samples whose taken values are all zero are skipped. A taken value may not be negative, as in a diff profile:
 * the tree holds no negative costs.
 *
 * @psalm-import-type Metadata from Profile
 *
 * @internal
 */
final class ProfileBuilder
{
    /** Size limit of a body in bytes, compressed or not. */
    public const MAX_SIZE = 8 * 1024 * 1024;

    /**
     * Size limit of the decompressed protobuf in bytes. Go compresses profiles about 3 to 10 times.
     */
    public const MAX_INFLATED_SIZE = 32 * 1024 * 1024;

    /**
     * Limit of the estimated memory the tables of a profile take decoded, besides the protobuf they are read from,
     * in bytes, see {@see Document::read()}.
     */
    private const MAX_DECODED_SIZE = 32 * 1024 * 1024;

    private const GZIP_MAGIC = "\x1F\x8B";

    /**
     * Limit of the summed microseconds, bytes or counts: floats hold integers exactly up to 2^53,
     * and larger ones would overflow the integer costs.
     */
    private const MAX_VALUE = 2 ** 53;

    /** Microseconds in a time unit. */
    private const TIME_UNITS = [
        'nanoseconds' => 0.001,
        'ns' => 0.001,
        'microseconds' => 1.0,
        'us' => 1.0,
        'milliseconds' => 1000.0,
        'ms' => 1000.0,
        'seconds' => 1000000.0,
        's' => 1000000.0,
    ];

    private const COUNT_UNITS = ['count', 'samples'];

    /**
     * @param positive-int $maxSize Limit of the body size in bytes.
     * @param positive-int $maxInflatedSize Limit of the decompressed size in bytes.
     * @param positive-int|null $maxNodes Limit of the tree nodes, over which the tree is compacted;
     *        by default, what the memory left affords when the build starts, see {@see NodeBudget}.
     * @param positive-int $maxFrames Limit of the frames of all the samples, see {@see Document::MAX_FRAMES}.
     */
    public function __construct(
        public readonly int $maxSize = self::MAX_SIZE,
        public readonly int $maxInflatedSize = self::MAX_INFLATED_SIZE,
        public readonly ?int $maxNodes = null,
        public readonly int $maxFrames = Document::MAX_FRAMES,
    ) {}

    /**
     * @param string $content Gzip-compressed or raw protobuf.
     *
     * @throws \InvalidArgumentException When the content is too large or is not a valid profile.
     */
    public function decode(string $content): Document
    {
        \strlen($content) > $this->maxSize
            and throw new \InvalidArgumentException("The profile is larger than {$this->maxSize} bytes.");
        \str_starts_with($content, self::GZIP_MAGIC) and $content = $this->inflate($content);

        return Document::read($content, self::MAX_DECODED_SIZE, $this->maxFrames);
    }

    /**
     * @param Metadata $metadata Gets `profile_type`, `sample_types`, `count_type` (the sample type `ct` comes from,
     *        if any), `clock` when time is measured, and `period`,
     *        `period_type`, `time_nanos`, `duration_nanos` when the profile tells them. Gets `compacted` too
     *        when the tree was over the node limit: what {@see Report::toArray()} tells and the `limit`.
     * @param array<non-empty-string, non-empty-string> $tags
     * @param non-empty-string|null $name Profile name, goes to the `name` tag.
     * @param TimeSlice|null $timeSlice Lets other fibers run during a long build.
     *
     * @throws \InvalidArgumentException When the profile has no samples or is malformed.
     */
    public function createProfile(
        Document $document,
        \DateTimeInterface $date = new \DateTimeImmutable(),
        array $metadata = [],
        array $tags = [],
        ?string $name = null,
        ?TimeSlice $timeSlice = null,
    ): Profile {
        $columns = $this->columns($document);
        [$ct, $wt, $cpu, $mu] = [$columns['ct'], $columns['wt'], $columns['cpu'], $columns['mu']];

        $metadata['profile_type'] = 'sampled';
        $metadata['sample_types'] = \implode(', ', \array_map(
            static fn(array $type): string => "$type[0]/$type[1]",
            $document->sampleTypes,
        ));
        $ct === null or $metadata['count_type'] = $document->sampleTypes[$ct[0]][0];
        $wt === null && $cpu === null or $metadata['clock'] = $wt === null ? 'cpu' : 'wall';
        if ($document->period > 0) {
            $metadata['period'] = $document->period;
            $document->periodType === null or $metadata['period_type'] = \implode('/', $document->periodType);
        }
        $document->timeNanos > 0 and $metadata['time_nanos'] = $document->timeNanos;
        $document->durationNanos > 0 and $metadata['duration_nanos'] = $document->durationNanos;
        $name === null or $tags += ['name' => $name];

        // The time goes to the value of the tree; the CPU time to its own sum only along the wall time
        $time = $wt ?? $cpu;
        $separateCpu = $wt !== null ? $cpu : null;
        /** @param array{int, string}|null $column */
        $scale = static fn(?array $column): float => $column === null ? 0.0 : self::TIME_UNITS[$column[1]];
        $timeScale = $scale($time);
        $cpuScale = $scale($separateCpu);
        $taken = \array_filter([$ct, $time, $separateCpu, $mu]) !== [];

        $limit = $this->maxNodes ?? NodeBudget::nodes();
        $nodes = new Nodes($limit, \count($document->names), timeSlice: $timeSlice);
        $totals = [0.0, 0.0, 0.0, 0.0];
        $used = \array_column(\array_filter([$ct, $time, $separateCpu, $mu]), 0);
        foreach ($document->samples() as $index => [$path, $values]) {
            foreach ($used as $i) {
                $values[$i] >= 0 or throw new \InvalidArgumentException(\sprintf(
                    'Sample %d has a negative %s: a diff profile is not supported.',
                    $index,
                    $document->sampleTypes[$i][0],
                ));
            }
            $count = $ct === null ? 1 : $values[$ct[0]];
            $value = $time === null ? 0.0 : (float) $values[$time[0]] * $timeScale;
            $cpuValue = $separateCpu === null ? 0.0 : (float) $values[$separateCpu[0]] * $cpuScale;
            $memory = $mu === null ? 0.0 : (float) $values[$mu[0]];
            // Nothing was measured
            if ($taken && ($ct === null || $count === 0) && $value === 0.0 && $cpuValue === 0.0 && $memory === 0.0) {
                continue;
            }

            foreach ([(float) $count, $value, $cpuValue, $memory] as $i => $add) {
                $totals[$i] += $add;
                $totals[$i] <= self::MAX_VALUE or throw new \InvalidArgumentException('The sample values are too large.');
            }
            $path === [] and ++$nodes->emptyStacks;
            $nodes->sample($path, $value, $count, $memory, $cpuValue);
            $timeSlice?->tick();
        }

        $nodes->compact();
        [$root, $rootName] = StackTree::root($nodes, $document->names, $document->fileScope)
            ?? throw new \InvalidArgumentException('The profile has no samples.');
        if (!$nodes->report->isEmpty()) {
            $nodes->report->measure($nodes->tree);
            $metadata['compacted'] = $nodes->report->toArray() + ['limit' => $limit];
        }

        $metrics = [];
        $time === null or $metrics['wt'] = 'value';
        $cpu === null or $metrics['cpu'] = $separateCpu === null ? 'value' : 'cpu';
        $mu === null or $metrics['mu'] = 'memory';
        $tree = StackTree::build($nodes, $root, $rootName, $document->names, $metrics);
        unset($nodes);

        /** @var Metadata $metadata */
        return Profile::fromTree($tree, $date, $metadata, $tags, $timeSlice);
    }

    /**
     * @return array{ct: array{int, string}|null, wt: array{int, string}|null, cpu: array{int, string}|null,
     *         mu: array{int, string}|null} The value index and the unit each cost takes, if any.
     */
    private function columns(Document $document): array
    {
        /** @var array{ct: list<int>, wt: list<int>, cpu: list<int>, mu: list<int>} $candidates */
        $candidates = ['ct' => [], 'wt' => [], 'cpu' => [], 'mu' => []];
        foreach ($document->sampleTypes as $i => [$type, $unit]) {
            $cost = match (true) {
                \in_array($unit, self::COUNT_UNITS, true) => 'ct',
                isset(self::TIME_UNITS[$unit]) => \str_contains(\strtolower($type), 'cpu') ? 'cpu' : 'wt',
                $unit === 'bytes' => 'mu',
                default => null,
            };
            $cost === null or $candidates[$cost][] = $i;
        }

        $last = \array_key_last($document->sampleTypes);
        $default = $document->defaultSampleType !== '' || $last === null
            ? $document->defaultSampleType
            : $document->sampleTypes[$last][0];
        $prefix = $this->prefix($default);
        $result = [];
        foreach ($candidates as $cost => $indexes) {
            $chosen = $indexes[0] ?? null;
            $best = 2;
            foreach ($indexes as $i) {
                $type = $document->sampleTypes[$i][0];
                $rank = match (true) {
                    $default !== '' && $type === $default => 0,
                    $prefix !== null && $this->prefix($type) === $prefix => 1,
                    default => 2,
                };
                if ($rank < $best) {
                    [$best, $chosen] = [$rank, $i];
                }
            }
            $result[$cost] = $chosen === null ? null : [$chosen, $document->sampleTypes[$chosen][1]];
        }

        /** @var array{ct: array{int, string}|null, wt: array{int, string}|null, cpu: array{int, string}|null, mu: array{int, string}|null} */
        return $result;
    }

    /**
     * @return string|null The part of a type before its last `_` or `-`: `inuse` of `inuse_space`.
     */
    private function prefix(string $type): ?string
    {
        $at = \max((int) \strrpos($type, '_'), (int) \strrpos($type, '-'));

        return $at > 0 ? \substr($type, 0, $at) : null;
    }

    /**
     * @throws \InvalidArgumentException When the content is not valid gzip, decompresses past the limit,
     *         or there is no zlib to decompress it.
     */
    private function inflate(string $content): string
    {
        \function_exists('gzdecode') or throw new \InvalidArgumentException(
            'The profile is gzip-compressed, but the server has no zlib extension: send it decompressed.',
        );

        $tooLarge = "The profile decompresses to more than {$this->maxInflatedSize} bytes.";
        $error = '';
        \set_error_handler(static function (int $code, string $message) use (&$error): bool {
            $error = $message;
            return true;
        });
        try {
            $inflated = \gzdecode($content, $this->maxInflatedSize);
        } finally {
            \restore_error_handler();
        }

        if ($inflated === false) {
            throw new \InvalidArgumentException(
                \str_contains($error, 'insufficient memory') ? $tooLarge : 'Invalid gzip data.',
            );
        }
        // The limit is checked by the chunks zlib inflates
        \strlen($inflated) <= $this->maxInflatedSize or throw new \InvalidArgumentException($tooLarge);

        return $inflated;
    }
}
