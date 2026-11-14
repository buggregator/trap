<?php

declare(strict_types=1);

namespace Buggregator\Trap\Client\Profiler\Internal\Model;

/**
 * Writes a {@see CallTree} of a sampled profile as a pprof `perftools.profiles.Profile` protobuf message
 * (google/pprof `proto/profile.proto`), gzip-compressed as pprof tools expect when ext-zlib is available.
 *
 * Every sample has two values: `samples/count` (or `calls/count`) and the sampled time in `wall/nanoseconds` or `cpu/nanoseconds`,
 * the default one. A sample is made per distinct stack; a location is made per function, without lines.
 * The stacks are rooted at `main()`, as in the XHProf conversion. All locations belong to one mapping
 * named by the file of `main()`.
 *
 * @internal
 */
final class PprofWriter
{
    private const VARINT = 0;
    private const LENGTH_DELIMITED = 2;
    private const MAPPING = 1;

    /**
     * @param float $period Sampling period in seconds.
     * @param bool $cpu Whether the samples were taken by CPU time: the time values are the CPU time, not the wall time.
     * @param int<0, max> $timeNanos Profile start as Unix time in nanoseconds; 0 when unknown.
     * @param int<0, max> $durationNanos 0 when unknown.
     * @param bool $gzip Takes effect only when ext-zlib is available.
     * @param non-empty-string $countType What the counts of the tree are: `samples`, or `calls` of a profiler
     *        that records them.
     */
    public static function encode(
        CallTree $tree,
        float $period,
        bool $cpu,
        int $timeNanos = 0,
        int $durationNanos = 0,
        bool $gzip = true,
        string $countType = 'samples',
    ): string {
        /** @var array<array-key, int> $strings Indexes by string; numeric strings turn into integer keys. */
        $strings = ['' => 0];
        $string = static function (string $value) use (&$strings): int {
            return $strings[$value] ??= \count($strings);
        };

        /** @var array<array-key, array<array-key, int>> $functionIds By file, then by name. */
        $functionIds = [];
        $lastFunction = 0;
        $functions = '';
        $locations = '';
        $mainFile = 0;
        /** @var array<int, string> $pathOf Packed leaf-first location ids of the path to a node, by node id. */
        $pathOf = [];
        /** @var array<string, array{int, int}> $stacks Samples and nanoseconds by packed leaf-first location ids. */
        $stacks = [];

        $tree->walk(static function (
            int $id,
            ?int $parent,
            string $name,
            ?string $file,
            int $wt,
            int $cpuTime,
            int $samples,
        ) use ($string, $cpu, &$functionIds, &$lastFunction, &$functions, &$locations, &$mainFile, &$pathOf, &$stacks): void {
            $file ??= '';
            $function = $functionIds[$file][$name] ?? null;
            if ($function === null) {
                $function = ++$lastFunction;
                $functionIds[$file][$name] = $function;
                $nameIndex = $string($name);
                $fileIndex = $string($file);
                $functions .= self::message(5, self::uint(1, $function)
                    . self::uint(2, $nameIndex)
                    . self::uint(3, $nameIndex)
                    . self::uint(4, $fileIndex));
                // One location per function: the location id is the function id.
                $locations .= self::message(4, self::uint(1, $function)
                    . self::uint(2, self::MAPPING)
                    . self::message(4, self::uint(1, $function)));
                $parent === null and $mainFile = $fileIndex;
            }

            // A parent comes before its children, so its path is ready.
            $path = $pathOf[$id] = self::varint($function) . ($parent === null ? '' : $pathOf[$parent]);
            $time = $cpu ? $cpuTime : $wt;
            if ($samples === 0 && $time === 0) {
                return;
            }

            $stack = $stacks[$path] ?? [0, 0];
            $stacks[$path] = [$stack[0] + $samples, $stack[1] + $time];
        });
        unset($pathOf);

        $samples = '';
        foreach ($stacks as $path => [$count, $nanoseconds]) {
            $samples .= self::message(2, self::bytes(1, (string) $path)
                . self::bytes(2, self::varint($count) . self::varint($nanoseconds)));
        }
        unset($stacks);

        // pprof tools warn that the main binary filename is not available without a mapping.
        $mapping = self::message(3, self::uint(1, self::MAPPING)
            . self::uint(5, $mainFile)
            . self::uint(7, 1)
            . self::uint(8, 1));

        $countType = self::message(1, self::uint(1, $string($countType)) . self::uint(2, $string('count')));
        $timeType = self::uint(1, $timeIndex = $string($cpu ? 'cpu' : 'wall')) . self::uint(2, $string('nanoseconds'));

        $table = '';
        foreach ($strings as $value => $_) {
            $table .= self::bytes(6, self::utf8((string) $value));
        }

        $profile = $countType
            . self::message(1, $timeType)
            . $samples
            . $mapping
            . $locations
            . $functions
            . $table
            . self::uint(9, $timeNanos)
            . self::uint(10, $durationNanos)
            . self::message(11, $timeType)
            . self::uint(12, \max(0, (int) \round($period * 1e9)))
            . self::uint(14, $timeIndex);

        $compressed = $gzip && \function_exists('gzencode') ? \gzencode($profile) : false;

        return $compressed === false ? $profile : $compressed;
    }

    /**
     * A varint field; proto3 leaves out a zero.
     */
    private static function uint(int $field, int $value): string
    {
        return $value === 0 ? '' : self::varint($field << 3 | self::VARINT) . self::varint($value);
    }

    /**
     * A length-delimited field, written even when empty: a repeated string keeps its index.
     */
    private static function bytes(int $field, string $value): string
    {
        return self::varint($field << 3 | self::LENGTH_DELIMITED) . self::varint(\strlen($value)) . $value;
    }

    /**
     * Replaces invalid UTF-8 with U+FFFD: protobuf parsers reject it in a `string` field,
     * and Excimer copies names and paths byte for byte.
     */
    private static function utf8(string $value): string
    {
        if (\preg_match('//u', $value) === 1) {
            return $value;
        }

        return (string) \json_decode(\json_encode($value, \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE));
    }

    private static function message(int $field, string $body): string
    {
        return self::bytes($field, $body);
    }

    /**
     * A base-128 varint, least significant group first; a negative number is its 64-bit two's complement,
     * 10 bytes long.
     */
    private static function varint(int $value): string
    {
        $bytes = '';
        while ($value < 0 || $value > 0x7F) {
            $bytes .= \chr($value & 0x7F | 0x80);
            // A logical shift: an arithmetic one would keep a negative number negative forever.
            $value = ($value >> 7) & (\PHP_INT_MAX >> 6);
        }

        return $bytes . \chr($value);
    }
}
