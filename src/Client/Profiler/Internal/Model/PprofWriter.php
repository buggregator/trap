<?php

declare(strict_types=1);

namespace Buggregator\Trap\Client\Profiler\Internal\Model;

/**
 * Writes a {@see CallTree} of a sampled profile as a pprof `perftools.profiles.Profile` protobuf message
 * (google/pprof `proto/profile.proto`), gzip-compressed as pprof tools expect when ext-zlib is available.
 *
 * Every sample has two values: `samples/count` and the sampled time in `wall/nanoseconds` or `cpu/nanoseconds`,
 * the default one. A sample is made per distinct stack; a location is made per function, without lines.
 * The stacks are rooted at `main()`, as in the XHProf conversion.
 *
 * @internal
 */
final class PprofWriter
{
    private const VARINT = 0;
    private const LENGTH_DELIMITED = 2;

    /**
     * @param float $period Sampling period in seconds.
     * @param bool $cpu Whether the samples were taken by CPU time.
     * @param int<0, max> $timeNanos Profile start as Unix time in nanoseconds; 0 when unknown.
     * @param int<0, max> $durationNanos 0 when unknown.
     * @param bool|null $gzip Null compresses when ext-zlib is available.
     */
    public static function encode(
        CallTree $tree,
        float $period,
        bool $cpu,
        int $timeNanos = 0,
        int $durationNanos = 0,
        ?bool $gzip = null,
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
        /** @var array<int, int> $locationOf Location ids by node id. */
        $locationOf = [];
        /** @var array<int, int|null> $parentOf */
        $parentOf = [];
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
        ) use ($string, &$functionIds, &$lastFunction, &$functions, &$locations, &$locationOf, &$parentOf, &$stacks): void {
            $file ??= '';
            $function = $functionIds[$file][$name] ?? null;
            if ($function === null) {
                $function = ++$lastFunction;
                $functionIds[$file][$name] = $function;
                $nameIndex = $string($name);
                $functions .= self::message(5, self::uint(1, $function)
                    . self::uint(2, $nameIndex)
                    . self::uint(3, $nameIndex)
                    . self::uint(4, $string($file)));
                // One location per function: the location id is the function id.
                $locations .= self::message(4, self::uint(1, $function) . self::message(4, self::uint(1, $function)));
            }

            $locationOf[$id] = $function;
            $parentOf[$id] = $parent;
            if ($samples === 0 && $wt === 0) {
                return;
            }

            $packed = '';
            for ($node = $id; $node !== null; $node = $parentOf[$node]) {
                $packed .= self::varint($locationOf[$node]);
            }

            $stack = $stacks[$packed] ?? [0, 0];
            $stacks[$packed] = [$stack[0] + $samples, $stack[1] + $wt * 1000];
        });

        $samples = '';
        foreach ($stacks as $packed => [$count, $nanoseconds]) {
            $samples .= self::message(2, self::bytes(1, (string) $packed)
                . self::bytes(2, self::varint($count) . self::varint($nanoseconds)));
        }

        $countType = self::message(1, self::uint(1, $string('samples')) . self::uint(2, $string('count')));
        $timeType = self::uint(1, $timeIndex = $string($cpu ? 'cpu' : 'wall')) . self::uint(2, $string('nanoseconds'));

        $table = '';
        foreach ($strings as $value => $_) {
            $table .= self::bytes(6, self::utf8((string) $value));
        }

        $profile = $countType
            . self::message(1, $timeType)
            . $samples
            . $locations
            . $functions
            . $table
            . self::uint(9, $timeNanos)
            . self::uint(10, $durationNanos)
            . self::message(11, $timeType)
            . self::uint(12, \max(0, (int) \round($period * 1e9)))
            . self::uint(14, $timeIndex);

        $gzip ??= \function_exists('gzencode');
        $compressed = $gzip ? \gzencode($profile) : false;

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
