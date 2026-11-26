<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler\Stacks;

use Buggregator\Trap\Module\Profiler\Compaction\NodeBudget;
use Buggregator\Trap\Module\Profiler\Compaction\Nodes;
use Buggregator\Trap\Module\Profiler\Compaction\Report;
use Buggregator\Trap\Module\Profiler\Compaction\StackTree;
use Buggregator\Trap\Module\Profiler\Gzip;
use Buggregator\Trap\Module\Profiler\Struct\Profile;
use Buggregator\Trap\Support\TimeSlice;

/**
 * Builds a profile from stack samples written as text: phpspy traces, which reli-prof writes too, and collapsed
 * (folded) stacks.
 *
 * phpspy writes a sample as its frames from the leaf, `<depth> <function> <file>:<line>` each, and the end
 * of the sample, see {@see self::records()}. Records starting with `#` are metadata, and
 * `# trace_id = <trace>.<chunk>~` ends a chunk of a sample longer than the output buffer (`-b`): `!` in place of `~`
 * ends its last chunk. reli-prof's `phpspy_with_opcode` template adds the opcode after the line.
 *
 * A collapsed line is a stack from the root and its count: `main;foo;bar 3`.
 *
 * The samples with the same stack path are merged into a node, as in a sampled speedscope profile: `ct` counts
 * the samples a function is on the stack in. With the sampling period known, the wall time is the samples
 * times the period. Frames are told apart by name; phpspy frames of the same name in different files, as the
 * closures or the file-scope code of included files, by the file too: `{closure} (/app/routes.php)`.
 *
 * @psalm-import-type Metadata from Profile
 *
 * @internal
 */
final class ProfileBuilder
{
    /** Size limit of a body in bytes, compressed or not. */
    public const MAX_SIZE = 8 * 1024 * 1024;

    /** Size limit of the decompressed text in bytes: phpspy repeats every frame of every sample. */
    public const MAX_INFLATED_SIZE = 32 * 1024 * 1024;

    /** Name phpspy gives file-scope code. */
    private const MAIN = '<main>';

    /** Files phpspy gives frames that have none. */
    private const NO_FILE = ['', '<internal>', '<elided>'];

    /** Limit of the frames of a sample. */
    public const MAX_DEPTH = 10_000;

    /**
     * Limit of the functions, told apart by their files too. A function takes about 2 KB in a kept profile and
     * more during the build, as a node does: within the memory left, a build gets half as many functions as nodes,
     * see {@see NodeBudget}.
     */
    public const MAX_FUNCTIONS = 50_000;

    /** Limit of the samples of a phpspy trace, to bound the time a build takes on shallow samples. */
    public const MAX_SAMPLES = 500_000;

    /** Limit of the frames of all the samples, to bound the time a build holds the build lock. */
    public const MAX_FRAMES = 2_000_000;

    /**
     * Limit of the lines, or the records of a single-line trace: blank ones and metadata take time to read too.
     * Twice the frames leave room for an end and a few metadata records per sample.
     */
    public const MAX_RECORDS = 2 * self::MAX_FRAMES;

    /** Limit of the frames in the chunks of unfinished samples, in the deepest samples. */
    private const PENDING_SAMPLES = 10;

    /** Limit of the summed counts: floats hold integers exactly up to 2^53. */
    private const MAX_VALUE = 2 ** 53;

    /**
     * @param positive-int $maxSize Limit of the body size in bytes.
     * @param positive-int $maxInflatedSize Limit of the decompressed size in bytes.
     * @param positive-int|null $maxNodes Limit of the tree nodes, over which the tree is compacted;
     *        by default, what the memory left affords when the build starts, see {@see NodeBudget}.
     * @param positive-int $maxDepth Limit of the frames of a sample.
     * @param positive-int|null $maxFunctions Limit of the functions; by default, what the memory left affords
     *        when the build starts, up to {@see self::MAX_FUNCTIONS}.
     * @param positive-int $maxSamples Limit of the samples of a phpspy trace.
     * @param positive-int $maxFrames Limit of the frames of all the samples.
     * @param positive-int $maxRecords Limit of the lines, or the records of a single-line trace.
     */
    public function __construct(
        public readonly int $maxSize = self::MAX_SIZE,
        public readonly int $maxInflatedSize = self::MAX_INFLATED_SIZE,
        public readonly ?int $maxNodes = null,
        public readonly int $maxDepth = self::MAX_DEPTH,
        public readonly ?int $maxFunctions = null,
        public readonly int $maxSamples = self::MAX_SAMPLES,
        public readonly int $maxFrames = self::MAX_FRAMES,
        public readonly int $maxRecords = self::MAX_RECORDS,
    ) {}

    /**
     * @param string $content Text, gzip-compressed or not.
     *
     * @throws \InvalidArgumentException When the content is too large or is not valid gzip.
     */
    public function decode(string $content): string
    {
        \strlen($content) > $this->maxSize
            and throw new \InvalidArgumentException("The profile is larger than {$this->maxSize} bytes.");

        return Gzip::isCompressed($content) ? Gzip::inflate($content, $this->maxInflatedSize) : $content;
    }

    /**
     * @param Metadata $metadata Gets `profile_type`, and `clock` and `period` with the period;
     *        `compacted` when the tree was over the node limit, as {@see Report::toArray()} tells, with the `limit`.
     * @param array<non-empty-string, non-empty-string> $tags
     * @param non-empty-string|null $name Profile name, goes to the `name` tag.
     * @param float|null $period Sampling period in seconds.
     *
     * @throws \InvalidArgumentException When the text is not a phpspy trace or has no samples.
     */
    public function phpspy(
        string $content,
        \DateTimeInterface $date = new \DateTimeImmutable(),
        array $metadata = [],
        array $tags = [],
        ?string $name = null,
        ?float $period = null,
        ?TimeSlice $timeSlice = null,
    ): Profile {
        // The node keys take the number of functions: they are counted before the samples are read
        /** @var array<string, array<string, true>> $files Files by names */
        $files = [];
        $count = 0;
        $limit = $this->functionLimit();
        $this->traces($content, static function (array $frames) use (&$files, &$count, $limit): void {
            foreach ($frames as [$function, $file]) {
                if (!isset($files[$function][$file])) {
                    $files[$function][$file] = true;
                    ++$count <= $limit or throw self::tooManyFunctions($limit);
                }
            }
        }, $timeSlice);

        $functions = new Functions();
        /** @var array<string, array<string, int<0, max>>> $ids Function IDs by names and files */
        $ids = [];
        $fileScope = [];
        foreach ($files as $function => $byFile) {
            $function = (string) $function;
            $real = \array_diff_key($byFile, \array_flip(self::NO_FILE));
            foreach ($byFile as $file => $_) {
                $file = (string) $file;
                $label = $function === '' ? StackTree::UNKNOWN : $function;
                \count($real) > 1 && isset($real[$file]) and $label .= " ($file)";
                $ids[$function][$file] = $functions->id($label);
                $function === self::MAIN and $fileScope[$label] = true;
            }
        }
        unset($files);

        $nodes = $this->nodes($functions, $timeSlice);
        $samplePeriod = self::microseconds($period);
        $this->traces($content, static function (array $frames) use ($ids, $nodes, $samplePeriod): void {
            $path = [];
            for ($i = \count($frames) - 1; $i >= 0; --$i) {
                $path[] = $ids[$frames[$i][0]][$frames[$i][1]];
            }
            self::sample($nodes, $path, 1, $samplePeriod);
        }, $timeSlice);

        return $this->profile($nodes, $functions, $fileScope, $date, $metadata, $tags, $name, $period, false, $timeSlice);
    }

    /**
     * @param Metadata $metadata See {@see self::phpspy()}; `clock` is `cpu` on the CPU clock.
     * @param array<non-empty-string, non-empty-string> $tags
     * @param non-empty-string|null $name Profile name, goes to the `name` tag.
     * @param float|null $period Sampling period in seconds.
     * @param bool $cpuClock The samples measure CPU time: with the period, it goes to `cpu` and to `wt`.
     *
     * @throws \InvalidArgumentException When a line is not a stack and a count, or there are no samples.
     */
    public function collapsed(
        string $content,
        \DateTimeInterface $date = new \DateTimeImmutable(),
        array $metadata = [],
        array $tags = [],
        ?string $name = null,
        ?float $period = null,
        bool $cpuClock = false,
        ?TimeSlice $timeSlice = null,
    ): Profile {
        $functions = new Functions();
        $limit = $this->functionLimit();
        $this->stacks($content, static function (array $stack) use ($functions, $limit): void {
            foreach ($stack as $function) {
                $functions->id($function);
            }
            $functions->count() <= $limit or throw self::tooManyFunctions($limit);
        }, $timeSlice);

        $nodes = $this->nodes($functions, $timeSlice);
        $samplePeriod = self::microseconds($period);
        $this->stacks($content, static function (array $stack, int $count) use ($functions, $nodes, $samplePeriod): void {
            $path = [];
            foreach ($stack as $function) {
                $path[] = $functions->id($function);
            }
            self::sample($nodes, $path, $count, $samplePeriod);
        }, $timeSlice);

        $fileScope = [self::MAIN => true];
        // Excimer names file-scope code by the path of its file, which a function name can't be
        foreach ($functions->names as $function) {
            \str_ends_with($function, '.php') and $fileScope[$function] = true;
        }
        return $this->profile($nodes, $functions, $fileScope, $date, $metadata, $tags, $name, $period, $cpuClock, $timeSlice);
    }

    /**
     * phpspy ends a frame or a metadata record with the frame delimiter, and a sample with the trace delimiter:
     * a line break each by default, a tab and a line break in single-line mode (`-1`). The first line with a frame
     * tells the mode: a peeked value in a metadata record may end with a tab in the default mode too, and the
     * records of `-e` come before the frame they peek into, so a single line may start with metadata.
     *
     * @return \Generator<positive-int, string|null> Records by their line numbers, empty ones too; null ends a sample.
     */
    private static function records(string $content): \Generator
    {
        $single = null;
        // A text cut off at its end, as by `head -c`, ends in a record cut off
        foreach (self::lines($content, complete: true) as $number => $line) {
            if ($line === '') {
                yield $number => null;
                continue;
            }
            $single ??= match (true) {
                \str_ends_with($line, "\t") && \preg_match('/(?:^|\t)\d+ /', $line) === 1 => true,
                $line[0] !== '#' => false,
                default => null,
            };
            if ($single !== true) {
                yield $number => $line;
                continue;
            }

            // Walked, not exploded: a line of millions of empty records would take gigabytes as an array
            for ($offset = 0, $length = \strlen($line); $offset < $length; $offset = $end + 1) {
                $end = \strpos($line, "\t", $offset);
                $end === false and $end = $length;
                yield $number => \substr($line, $offset, $end - $offset);
            }
            yield $number => null;
        }
    }

    /**
     * @return array{string, string} The function and the file.
     */
    private static function frame(string $text, int $number): array
    {
        // The function is the second word, as phpspy's flame graph tool takes it; the file may have spaces,
        // and so may the PHP 8.4 name of a closure, which has its file
        \preg_match('/^\d+ (\S*?\{closure:.*?\}|\S*) (.*):-?\d+(?::\w+)?$/', $text, $match) === 1
            or throw new \InvalidArgumentException(\sprintf(
                'Line %d is not a phpspy frame `<depth> <function> <file>:<line>`: `%s`.',
                $number,
                self::cut($text),
            ));

        return [$match[1], $match[2]];
    }

    /**
     * @param bool $complete Skip the text after the last line break.
     *
     * @return \Generator<positive-int, string> Lines without the line break, by their numbers.
     */
    private static function lines(string $content, bool $complete = false): \Generator
    {
        $number = 0;
        $offset = 0;
        $length = \strlen($content);
        while ($offset < $length) {
            $end = \strpos($content, "\n", $offset);
            if ($end === false) {
                if ($complete) {
                    return;
                }
                $end = $length;
            }
            yield ++$number => \rtrim(\substr($content, $offset, $end - $offset), "\r");
            $offset = $end + 1;
        }
    }

    private static function cut(string $text): string
    {
        return \mb_strimwidth($text, 0, 80, '...');
    }

    private static function microseconds(?float $period): float
    {
        $period === null || ($period > 0.0 && \is_finite($period))
            or throw new \InvalidArgumentException('The sampling period must be a positive number of seconds.');

        return $period === null ? 0.0 : $period * 1e6;
    }

    /**
     * @param list<int<0, max>> $path
     * @param positive-int $count
     */
    private static function sample(Nodes $nodes, array $path, int $count, float $period): void
    {
        $nodes->sample($path, (float) $count * $period, $count);
        $nodes->timeSlice?->tick();
    }

    private static function tooManyFunctions(int $limit): \InvalidArgumentException
    {
        return new \InvalidArgumentException("The profile has more than $limit functions.");
    }

    private function tooManyRecords(): \InvalidArgumentException
    {
        return new \InvalidArgumentException("The profile has more than {$this->maxRecords} lines or records.");
    }

    /**
     * Passes each phpspy sample to the callback, with its chunks put together.
     *
     * @param \Closure(non-empty-list<array{string, string}>): void $onSample Gets the functions and files
     *        of the frames, from the leaf.
     *
     * @throws \InvalidArgumentException When a line is neither a frame nor metadata, or there are no samples.
     */
    private function traces(string $content, \Closure $onSample, ?TimeSlice $timeSlice): void
    {
        $samples = 0;
        $read = 0;
        $emit = function (array $frames) use ($onSample, &$samples, &$read): void {
            if ($frames === []) {
                return;
            }
            \count($frames) <= $this->maxDepth or throw new \InvalidArgumentException(
                "A sample has more than {$this->maxDepth} frames.",
            );
            ++$samples <= $this->maxSamples or throw new \InvalidArgumentException(
                "The trace has more than {$this->maxSamples} samples.",
            );
            $read += \count($frames);
            $read <= $this->maxFrames or throw new \InvalidArgumentException(
                "The samples have more than {$this->maxFrames} frames.",
            );
            /** @var non-empty-list<array{string, string}> $frames */
            $onSample($frames);
        };

        /** @var list<array{string, string}> $frames */
        $frames = [];
        /** @var array<string, array<int, list<array{string, string}>>> $chunks Chunks by traces and indexes */
        $chunks = [];
        // A chunk with no frames takes memory too
        $weight = static fn(array $chunk): int => \max(1, \count($chunk));
        $pending = 0;
        $maxPending = self::PENDING_SAMPLES * $this->maxDepth;
        $records = 0;
        foreach (self::records($content) as $number => $record) {
            $timeSlice?->tick();
            ++$records <= $this->maxRecords or throw $this->tooManyRecords();
            if ($record === '') {
                continue;
            }
            if ($record === null) {
                $emit($frames);
                $frames = [];
                continue;
            }

            if ($record[0] === '#') {
                if (\preg_match('/^# trace_id = (\d+)\.(\d+)([~!])$/', $record, $match) !== 1) {
                    continue;
                }
                // The chunks of a sample may have the samples of other processes between them
                [, $trace, $index] = $match;
                $index = (int) $index;
                $pending += $weight($frames) - (isset($chunks[$trace][$index]) ? $weight($chunks[$trace][$index]) : 0);
                $chunks[$trace][$index] = $frames;
                $frames = [];
                $pending <= $maxPending or throw new \InvalidArgumentException(
                    "More than $maxPending frames wait in the chunks of unfinished samples.",
                );
                if ($match[3] === '!') {
                    $parts = $chunks[$trace];
                    unset($chunks[$trace]);
                    $pending -= \array_sum(\array_map($weight, $parts));
                    \ksort($parts);
                    // A lost chunk drops the sample, as phpspy's own `stackcollapse-phpspy.pl` does
                    \count($parts) === $index + 1 && \array_key_last($parts) === $index
                        and $emit(\array_merge(...$parts));
                }
                continue;
            }

            $frames[] = self::frame($record, $number);
            \count($frames) <= $this->maxDepth or throw new \InvalidArgumentException(
                "A sample has more than {$this->maxDepth} frames.",
            );
        }

        // The frames left have no end of their sample: it was cut off
        $samples > 0 or throw new \InvalidArgumentException(
            $frames === []
                ? 'The trace has no samples.'
                : 'The trace has no complete samples: a sample ends with a blank line, or a line break in single-line mode.',
        );
    }

    /**
     * @param \Closure(non-empty-list<non-empty-string>, positive-int): void $onStack Gets the functions
     *        of the stack from the root, and its count.
     *
     * @throws \InvalidArgumentException When a line is not a stack and a count, or there are no samples.
     */
    private function stacks(string $content, \Closure $onStack, ?TimeSlice $timeSlice): void
    {
        $total = 0;
        $read = 0;
        foreach (self::lines($content) as $number => $line) {
            $number <= $this->maxRecords or throw $this->tooManyRecords();
            if (\trim($line) === '') {
                continue;
            }
            \preg_match('/^(.*\S)\s+(\d+)$/', $line, $match) === 1 or throw new \InvalidArgumentException(
                \sprintf('Line %d is not a collapsed stack `frame;frame;frame count`: `%s`.', $number, self::cut($line)),
            );
            $count = (int) $match[2];
            if ($count <= 0) {
                continue;
            }
            $depth = \substr_count($match[1], ';') + 1;
            $depth <= $this->maxDepth or throw new \InvalidArgumentException(
                "Line $number has more than {$this->maxDepth} frames.",
            );
            $read += $depth;
            $read <= $this->maxFrames or throw new \InvalidArgumentException(
                "The stacks have more than {$this->maxFrames} frames.",
            );
            $total += $count;
            $total <= self::MAX_VALUE or throw new \InvalidArgumentException('The counts are too large.');
            $onStack(
                \array_map(
                    static fn(string $frame): string => $frame === '' ? StackTree::UNKNOWN : $frame,
                    \explode(';', $match[1]),
                ),
                $count,
            );
            $timeSlice?->tick();
        }

        $total > 0 or throw new \InvalidArgumentException('The profile has no samples.');
    }

    /**
     * @return positive-int
     */
    private function functionLimit(): int
    {
        return $this->maxFunctions ?? \max(1, \min(self::MAX_FUNCTIONS, \intdiv(NodeBudget::nodes(), 2)));
    }

    private function nodes(Functions $functions, ?TimeSlice $timeSlice): Nodes
    {
        return new Nodes($this->maxNodes ?? NodeBudget::nodes(), $functions->count(), timeSlice: $timeSlice);
    }

    /**
     * @param array<non-empty-string, true> $fileScope
     * @param Metadata $metadata
     * @param array<non-empty-string, non-empty-string> $tags
     * @param non-empty-string|null $name
     */
    private function profile(
        Nodes $nodes,
        Functions $functions,
        array $fileScope,
        \DateTimeInterface $date,
        array $metadata,
        array $tags,
        ?string $name,
        ?float $period,
        bool $cpuClock,
        ?TimeSlice $timeSlice,
    ): Profile {
        $metadata['profile_type'] = 'sampled';
        if ($period !== null) {
            $metadata['clock'] = $cpuClock ? 'cpu' : 'wall';
            $metadata['period'] = $period;
        }
        $name === null or $tags += ['name' => $name];
        // Node 0 sums the samples: a period too long for them would overflow the integer costs
        $nodes->tree->value[0] <= self::MAX_VALUE
            or throw new \InvalidArgumentException('The wall time is too large: the `period` is too long.');

        $nodes->compact();
        [$root, $rootName] = StackTree::root($nodes, $functions->names, $fileScope)
            ?? throw new \InvalidArgumentException('The profile has no samples.');
        if (!$nodes->report->isEmpty()) {
            $nodes->report->measure($nodes->tree);
            $metadata['compacted'] = $nodes->report->toArray() + ['limit' => $nodes->maxNodes];
        }

        $metrics = match (true) {
            $period === null => [],
            $cpuClock => ['wt' => 'value', 'cpu' => 'value'],
            default => ['wt' => 'value'],
        };
        $tree = StackTree::build($nodes, $root, $rootName, $functions->names, $metrics);
        unset($nodes);

        /** @var Metadata $metadata */
        return Profile::fromTree($tree, $date, $metadata, $tags, $timeSlice);
    }
}
