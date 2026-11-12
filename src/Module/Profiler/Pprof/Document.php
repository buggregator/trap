<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler\Pprof;

use Buggregator\Trap\Module\Profiler\Compaction\StackTree;

/**
 * A decoded pprof profile (`profile.proto` of google/pprof): its tables read, its samples read on demand,
 * so that they are never held all at once.
 *
 * Frames are told apart by name, as in the other profiles, which have nothing else to tell functions by;
 * when functions of the same name come from different files, they are told apart by the file: `render (html.go)`.
 * A function with no name takes its system name. The lines of a location are inlined calls, the last one
 * the outermost: each is a frame. A location with no lines is named by its mapping file and address.
 * Labels, line numbers and the frames to drop or keep are not read.
 *
 * @link https://github.com/google/pprof/blob/main/proto/profile.proto
 *
 * @internal
 */
final class Document
{
    /** Frames a sample may have, inlined ones included. */
    public const MAX_STACK_DEPTH = 16_384;

    /**
     * Lines of a location: the calls inlined into each other at an address. Compilers inline a few levels
     * (Go profiles have up to 5); each line costs a tree step in every sample of the location.
     */
    public const MAX_INLINED_LINES = 256;

    /**
     * Frames all the samples may have together, each a step of the build: the build holds a lock, and a few bytes
     * of a sample may refer to thousands of frames. Go profiles have under a frame per byte, about 8 million
     * at {@see ProfileBuilder::MAX_INFLATED_SIZE}; the limit holds the build to a few seconds.
     */
    public const MAX_FRAMES = 20_000_000;

    /** Estimated bytes a string of the table takes besides its content. */
    private const STRING_BYTES = 64;

    /** Estimated bytes an entry of a small table takes: a sample type or a mapping. */
    private const ENTRY_BYTES = 160;

    /** Estimated bytes a function takes: its frame, its name and the file it is told apart by. */
    private const FUNCTION_BYTES = 250;

    /** Estimated bytes a location of one frame takes. */
    private const LOCATION_BYTES = 48;

    /** Estimated bytes the list of the frames of a location of several lines takes, and a frame in it. */
    private const FRAME_LIST_BYTES = 256;

    private const FRAME_BYTES = 32;

    /**
     * @param list<array{string, string}> $sampleTypes Types and units of the sample values.
     * @param list<non-empty-string> $names Frame names by IDs.
     * @param array<non-empty-string, true> $fileScope Names of the frames of file-scope code: named by their file.
     * @param array<int, int<0, max>|list<int<0, max>>> $locations Frame IDs by location IDs, the outermost first;
     *        the frame ID itself for a location of one frame.
     * @param array{string, string}|null $periodType
     * @param positive-int $maxFrames
     */
    private function __construct(
        private readonly string $data,
        public readonly array $sampleTypes,
        public readonly string $defaultSampleType,
        public readonly array $names,
        public readonly array $fileScope,
        private readonly array $locations,
        public readonly int $period,
        public readonly ?array $periodType,
        public readonly int $timeNanos,
        public readonly int $durationNanos,
        private readonly int $maxFrames,
    ) {}

    /**
     * Reads the tables of a serialized profile; the samples are only checked when they are read.
     *
     * @param positive-int $maxDecodedSize Limit of the estimated memory the tables take decoded, in bytes:
     *        an entry of a few bytes takes a hundred of them decoded.
     * @param positive-int $maxFrames Limit of the frames of all the samples, inlined ones included.
     *
     * @throws \InvalidArgumentException When the data is not a valid profile or is over the limit.
     */
    public static function read(string $data, int $maxDecodedSize, int $maxFrames = self::MAX_FRAMES): self
    {
        $end = \strlen($data);
        $offset = 0;
        $estimate = 0;
        /** @var list<string> $strings */
        $strings = [];
        /** @var list<array{int, int}> $sampleTypes String indexes of the types and units */
        $sampleTypes = [];
        $periodType = null;
        $period = 0;
        $time = 0;
        $duration = 0;
        $default = 0;
        /** @var array<int, int> $mappings File name indexes by IDs */
        $mappings = [];

        // Functions and locations are only counted here: they are read into frames once the strings,
        // which go after them, are known, so that only the frames are held.
        while ($offset < $end) {
            [$field, $type] = Wire::tag($data, $offset, $end);
            switch ($field) {
                case 1:
                    $sampleTypes[] = self::valueType($data, $offset, Wire::message($data, $offset, $end, $type));
                    $estimate += self::ENTRY_BYTES;
                    break;
                case 3:
                    [$id, $file] = self::mapping($data, $offset, Wire::message($data, $offset, $end, $type));
                    $mappings[$id] = $file;
                    $estimate += self::ENTRY_BYTES;
                    break;
                case 4:
                    $lines = \count(self::location($data, $offset, Wire::message($data, $offset, $end, $type))[1]);
                    $estimate += self::LOCATION_BYTES
                        + ($lines > 1 ? self::FRAME_LIST_BYTES + self::FRAME_BYTES * $lines : 0);
                    break;
                case 5:
                    $offset = Wire::message($data, $offset, $end, $type);
                    $estimate += self::FUNCTION_BYTES;
                    break;
                case 6:
                    $to = Wire::message($data, $offset, $end, $type);
                    $strings[] = \substr($data, $offset, $to - $offset);
                    $estimate += self::STRING_BYTES + $to - $offset;
                    $offset = $to;
                    break;
                case 9:
                    $time = Wire::int($data, $offset, $end, $type);
                    break;
                case 10:
                    $duration = Wire::int($data, $offset, $end, $type);
                    break;
                case 11:
                    $periodType = self::valueType($data, $offset, Wire::message($data, $offset, $end, $type));
                    break;
                case 12:
                    $period = Wire::int($data, $offset, $end, $type);
                    break;
                case 14:
                    $default = Wire::int($data, $offset, $end, $type);
                    break;
                default:
                    // Samples are read later; the rest is not used
                    Wire::skip($data, $offset, $end, $type);
            }
            $estimate > $maxDecodedSize and throw new \InvalidArgumentException(
                'The profile would take more than ' . (int) \ceil($maxDecodedSize / 1048576) . ' MB decoded.',
            );
        }

        $string = static fn(int $index): string => $strings[$index] ?? ($index === 0 ? '' : throw new \InvalidArgumentException(
            "Unknown string index $index.",
        ));
        /** @param array{int, int} $pair */
        $valueType = static fn(array $pair): array => [$string($pair[0]), $string($pair[1])];

        [$names, $fileScope, $frames] = self::frames($data, $mappings, $string);

        return new self(
            data: $data,
            sampleTypes: \array_map($valueType, $sampleTypes),
            defaultSampleType: $string($default),
            names: $names,
            fileScope: $fileScope,
            locations: $frames,
            period: $period,
            periodType: $periodType === null ? null : $valueType($periodType),
            timeNanos: $time,
            durationNanos: $duration,
            maxFrames: $maxFrames,
        );
    }

    /**
     * @return \Generator<int, array{list<int<0, max>>, list<int>}> Frame IDs from the root, and the values,
     *         by sample indexes.
     *
     * @throws \InvalidArgumentException When a sample is malformed, is deeper than {@see self::MAX_STACK_DEPTH},
     *         or the samples have more frames than the limit.
     */
    public function samples(): \Generator
    {
        $data = $this->data;
        $locations = $this->locations;
        $valueCount = \count($this->sampleTypes);
        $end = \strlen($data);
        $offset = 0;
        $index = 0;
        $total = 0;
        while ($offset < $end) {
            [$field, $type] = Wire::tag($data, $offset, $end);
            if ($field !== 2) {
                Wire::skip($data, $offset, $end, $type);
                continue;
            }

            $to = Wire::message($data, $offset, $end, $type);
            /** @var list<int> $ids */
            $ids = [];
            /** @var list<int> $values */
            $values = [];
            while ($offset < $to) {
                [$field, $type] = Wire::tag($data, $offset, $to);
                match ($field) {
                    1 => Wire::repeated($data, $offset, $to, $type, $ids, self::MAX_STACK_DEPTH),
                    2 => Wire::repeated($data, $offset, $to, $type, $values, \max(1, $valueCount)),
                    default => Wire::skip($data, $offset, $to, $type),
                };
            }
            \count($values) === $valueCount or throw new \InvalidArgumentException(
                "Sample $index has " . \count($values) . " values for $valueCount sample types.",
            );

            // Locations go from the leaf
            $path = [];
            for ($i = \count($ids) - 1; $i >= 0; --$i) {
                $frames = $locations[$ids[$i]] ?? throw new \InvalidArgumentException(
                    "Sample $index refers to unknown location {$ids[$i]}.",
                );
                if (\is_int($frames)) {
                    $path[] = $frames;
                    continue;
                }
                \count($path) + \count($frames) <= self::MAX_STACK_DEPTH or throw new \InvalidArgumentException(
                    "Sample $index is deeper than " . self::MAX_STACK_DEPTH . ' frames.',
                );
                \array_push($path, ...$frames);
            }
            $total += \count($path);
            $total <= $this->maxFrames or throw new \InvalidArgumentException(
                "The samples have more than {$this->maxFrames} frames together, inlined ones included.",
            );

            yield $index++ => [$path, $values];
        }
    }

    /**
     * @return array{int, int}
     */
    private static function valueType(string $data, int &$offset, int $end): array
    {
        $type = 0;
        $unit = 0;
        while ($offset < $end) {
            [$field, $wire] = Wire::tag($data, $offset, $end);
            match ($field) {
                1 => $type = Wire::int($data, $offset, $end, $wire),
                2 => $unit = Wire::int($data, $offset, $end, $wire),
                default => Wire::skip($data, $offset, $end, $wire),
            };
        }

        return [$type, $unit];
    }

    /**
     * @return array{int, int} The ID and the file name index.
     */
    private static function mapping(string $data, int &$offset, int $end): array
    {
        $id = 0;
        $file = 0;
        while ($offset < $end) {
            [$field, $wire] = Wire::tag($data, $offset, $end);
            match ($field) {
                1 => $id = Wire::int($data, $offset, $end, $wire),
                5 => $file = Wire::int($data, $offset, $end, $wire),
                default => Wire::skip($data, $offset, $end, $wire),
            };
        }

        return [$id, $file];
    }

    /**
     * @return array{int, list<int>, int, int} The ID, the function IDs of the lines, the mapping ID and the address.
     *
     * @throws \InvalidArgumentException When the location is malformed or has more than
     *         {@see self::MAX_INLINED_LINES} lines.
     */
    private static function location(string $data, int &$offset, int $end): array
    {
        $id = 0;
        $mapping = 0;
        $address = 0;
        $lines = [];
        while ($offset < $end) {
            [$field, $wire] = Wire::tag($data, $offset, $end);
            switch ($field) {
                case 1:
                    $id = Wire::int($data, $offset, $end, $wire);
                    break;
                case 2:
                    $mapping = Wire::int($data, $offset, $end, $wire);
                    break;
                case 3:
                    $address = Wire::int($data, $offset, $end, $wire);
                    break;
                case 4:
                    \count($lines) < self::MAX_INLINED_LINES or throw new \InvalidArgumentException(
                        "Location $id has more than " . self::MAX_INLINED_LINES . ' lines of inlined calls.',
                    );
                    $to = Wire::message($data, $offset, $end, $wire);
                    $function = 0;
                    while ($offset < $to) {
                        [$lineField, $lineWire] = Wire::tag($data, $offset, $to);
                        $lineField === 1
                            ? $function = Wire::int($data, $offset, $to, $lineWire)
                            : Wire::skip($data, $offset, $to, $lineWire);
                    }
                    $lines[] = $function;
                    break;
                default:
                    Wire::skip($data, $offset, $end, $wire);
            }
        }

        return [$id, $lines, $mapping, $address];
    }

    /**
     * @return array{int, int, int, int} The ID; the name, system name and file name indexes.
     */
    private static function function(string $data, int &$offset, int $end): array
    {
        $id = 0;
        $name = 0;
        $systemName = 0;
        $file = 0;
        while ($offset < $end) {
            [$field, $wire] = Wire::tag($data, $offset, $end);
            match ($field) {
                1 => $id = Wire::int($data, $offset, $end, $wire),
                2 => $name = Wire::int($data, $offset, $end, $wire),
                3 => $systemName = Wire::int($data, $offset, $end, $wire),
                4 => $file = Wire::int($data, $offset, $end, $wire),
                default => Wire::skip($data, $offset, $end, $wire),
            };
        }

        return [$id, $name, $systemName, $file];
    }

    /**
     * @return \Generator<int, int> Ends of the messages of a top-level field by their starts.
     */
    private static function messages(string $data, int $field): \Generator
    {
        $end = \strlen($data);
        $offset = 0;
        while ($offset < $end) {
            [$tagField, $type] = Wire::tag($data, $offset, $end);
            if ($tagField !== $field) {
                Wire::skip($data, $offset, $end, $type);
                continue;
            }

            $to = Wire::message($data, $offset, $end, $type);
            yield $offset => $to;
            $offset = $to;
        }
    }

    /**
     * Reads the functions and the locations into frames.
     *
     * @param array<int, int> $mappings
     * @param \Closure(int): string $string
     *
     * @return array{list<non-empty-string>, array<non-empty-string, true>, array<int, int<0, max>|list<int<0, max>>>}
     *         The frame names, the names of file-scope code and the frames of the locations.
     */
    private static function frames(string $data, array $mappings, \Closure $string): array
    {
        /**
         * @return array{int, non-empty-string, string} The ID, the name and the file.
         */
        $readFunction = static function (int $at, int $to) use ($data, $string): array {
            [$id, $name, $systemName, $file] = self::function($data, $at, $to);
            $name = $string($name);
            $name === '' and $name = $string($systemName);
            $name === '' and $name = StackTree::UNKNOWN;

            return [$id, $name, $string($file)];
        };

        // The file of a name, false for a name of several files
        /** @var array<non-empty-string, string|false> $fileOf */
        $fileOf = [];
        foreach (self::messages($data, 5) as $at => $to) {
            [, $name, $file] = $readFunction($at, $to);
            $known = $fileOf[$name] ?? $file;
            $fileOf[$name] = $known === $file ? $file : false;
        }

        $names = [];
        $byName = [];
        $fileScope = [];
        /**
         * @param non-empty-string $name
         * @return int<0, max>
         */
        $frameOf = static function (string $name) use (&$names, &$byName): int {
            /** @var int<0, max> */
            return $byName[$name] ??= \array_push($names, $name) - 1;
        };

        /** @var array<int, int<0, max>> $functionFrames */
        $functionFrames = [];
        foreach (self::messages($data, 5) as $at => $to) {
            [$id, $name, $file] = $readFunction($at, $to);
            $file !== '' && $name === $file and $fileScope[$name] = true;
            $functionFrames[$id] = $frameOf($fileOf[$name] === false && $file !== '' ? "$name ($file)" : $name);
        }
        unset($fileOf);

        /** @var array<int, int<0, max>|list<int<0, max>>> $frames */
        $frames = [];
        foreach (self::messages($data, 4) as $at => $to) {
            [$id, $lines, $mapping, $address] = self::location($data, $at, $to);
            if ($lines === []) {
                $file = $mapping === 0 ? '' : $string($mappings[$mapping] ?? throw new \InvalidArgumentException(
                    "Location $id refers to unknown mapping $mapping.",
                ));
                $hex = \sprintf('0x%x', $address);
                $frames[$id] = $frameOf(match (true) {
                    $file !== '' => "$file+$hex",
                    $address !== 0 => $hex,
                    default => StackTree::UNKNOWN,
                });
                continue;
            }

            /** @var list<int<0, max>> $list */
            $list = [];
            for ($i = \count($lines) - 1; $i >= 0; --$i) {
                $function = $lines[$i];
                $list[] = $function === 0
                    ? $frameOf(StackTree::UNKNOWN)
                    : $functionFrames[$function] ?? throw new \InvalidArgumentException(
                        "Location $id refers to unknown function $function.",
                    );
            }
            $frames[$id] = \count($list) === 1 ? $list[0] : $list;
        }

        return [$names, $fileScope, $frames];
    }
}
