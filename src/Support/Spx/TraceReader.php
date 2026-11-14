<?php

declare(strict_types=1);

namespace Buggregator\Trap\Support\Spx;

use Buggregator\Trap\Support\TimeSlice;

/**
 * Streams the trace of an SPX full report, the `<key>.txt.gz` file, without holding it in memory.
 *
 * The trace is an `[events]` line, an event per line, then a `[functions]` line and a function name per line:
 *
 * ```
 * [events]
 * 0 1 0 0
 * 1 1 1520.5 512
 * 1 0 9310 2048
 * 0 0 12000 1024
 * [functions]
 * /app/index.php
 * App\Kernel::handle
 * ```
 *
 * An event is the function index, `1` when a call starts or `0` when it ends, and the value of each enabled
 * metric at that moment. The values are running totals since the profiling started, not costs of the call:
 * the cost of a call is the value at its end less the value at its start. The calls nest properly, as SPX
 * ends the open calls when it stops. The names come last, so the events refer to functions by index only.
 *
 * @link https://github.com/NoiseByNorthwest/php-spx/blob/master/src/spx_reporter_full.c
 *
 * @internal
 */
final class TraceReader
{
    /** Limit of the decompressed trace in bytes: it is streamed, so the limit bounds the time, not the memory. */
    public const MAX_SIZE = 256 * 1024 * 1024;

    /** Limit of the functions a trace may name. */
    public const MAX_FUNCTIONS = 262_144;

    /** Limit of the names of the functions together, in bytes: they stay in memory until the trace is read. */
    public const MAX_NAMES_SIZE = 32 * 1024 * 1024;

    /** Limit of the calls open at once: their values stay in memory. SPX itself stops at 2048. */
    public const MAX_DEPTH = 65_536;

    /** Compressed bytes inflated at a time: a crafted stream expands about a thousand times. */
    private const INFLATE_CHUNK = 8192;

    /** Limit of a line in bytes: a function name is far shorter. */
    private const MAX_LINE = 65536;

    /**
     * @param iterable<string> $chunks The gzipped trace, as SPX writes it, in pieces of any size.
     * @param int<0, max> $metricCount Values in an event, see {@see Metadata::$metrics}.
     * @param int<0, max> $functionCount Functions the trace names, see {@see Metadata::$functionCount}:
     *        an event of a larger index is an error, so the indexes may key arrays sized by it.
     * @param \Closure(int, bool, list<float>): void $onEvent Takes the function index, whether a call
     *        starts, and the metric values in the column order.
     * @param positive-int $maxSize Limit of the decompressed trace in bytes.
     * @param positive-int $maxNamesSize Limit of the function names together in bytes.
     *
     * @return list<non-empty-string> Function names by index.
     *
     * @throws \InvalidArgumentException When the trace is malformed, truncated or too large.
     */
    public static function read(
        iterable $chunks,
        int $metricCount,
        int $functionCount,
        \Closure $onEvent,
        int $maxSize = self::MAX_SIZE,
        ?TimeSlice $timeSlice = null,
        int $maxNamesSize = self::MAX_NAMES_SIZE,
    ): array {
        // SPX writes `.5` for 0.5, and nothing for a value that rounds to 0 at 4 decimals
        $event = '/^(\d{1,9}) ([01])' . ($metricCount === 0 ? '' : "((?: -?\\d*(?:\\.\\d+)?){{$metricCount}})") . '$/';
        $buffer = '';
        $size = 0;
        $line = 0;
        $section = null;
        /** @var list<int> $stack */
        $stack = [];
        $names = [];
        $namesSize = 0;

        $parse = static function (string $data, bool $last) use (
            &$buffer,
            &$line,
            &$section,
            &$stack,
            &$names,
            &$namesSize,
            $maxNamesSize,
            $event,
            $metricCount,
            $functionCount,
            $onEvent,
            $timeSlice,
        ): void {
            $buffer .= $data;
            $end = $last ? \strlen($buffer) : \strrpos($buffer, "\n");
            if ($end === false) {
                \strlen($buffer) > self::MAX_LINE and throw new \InvalidArgumentException(
                    'Line ' . ($line + 1) . ' is longer than ' . self::MAX_LINE . ' bytes.',
                );
                return;
            }

            $lines = \explode("\n", \substr($buffer, 0, $end));
            $buffer = (string) \substr($buffer, $end + 1);
            foreach ($lines as $text) {
                // What follows the last line break
                if ($last && $text === '') {
                    break;
                }
                ++$line;
                if ($section === 'events') {
                    if (\preg_match($event, $text, $m) === 1) {
                        $function = (int) $m[1];
                        $function < $functionCount or throw new \InvalidArgumentException(
                            "Line $line refers to function $function, but the trace names $functionCount.",
                        );
                        if ($m[2] === '1') {
                            $stack[] = $function;
                            \count($stack) > self::MAX_DEPTH and throw new \InvalidArgumentException(
                                "Line $line opens a call deeper than " . self::MAX_DEPTH . ' calls.',
                            );
                        } elseif (\array_pop($stack) !== $function) {
                            throw new \InvalidArgumentException("Line $line ends a call that is not the innermost open one.");
                        }
                        $onEvent(
                            $function,
                            $m[2] === '1',
                            $metricCount === 0 ? [] : \array_map(\floatval(...), \explode(' ', \substr($m[3], 1))),
                        );
                        $timeSlice?->tick();
                        continue;
                    }

                    $text === '[functions]' or throw new \InvalidArgumentException(
                        "Line $line is not an event of $metricCount metric values.",
                    );
                    $stack === [] or throw new \InvalidArgumentException('The trace ends with calls still open.');
                    $section = 'functions';
                } elseif ($section === 'functions') {
                    \count($names) < $functionCount or throw new \InvalidArgumentException(
                        "The trace names more than the $functionCount functions of the metadata.",
                    );
                    $namesSize += \strlen($text);
                    $namesSize > $maxNamesSize and throw new \InvalidArgumentException(
                        "The function names take more than $maxNamesSize bytes.",
                    );
                    $names[] = $text === '' ? '(unknown)' : $text;
                } else {
                    $text === '[events]' or throw new \InvalidArgumentException('Not an SPX trace: no `[events]` line.');
                    $section = 'events';
                }
            }
        };

        \function_exists('inflate_init') or throw new \RuntimeException('Reading an SPX trace takes ext-zlib.');
        $context = \inflate_init(\ZLIB_ENCODING_GZIP) ?: throw new \RuntimeException('Unable to initialize inflating.');
        foreach ($chunks as $chunk) {
            for ($offset = 0, $length = \strlen($chunk); $offset < $length; $offset += self::INFLATE_CHUNK) {
                if (\inflate_get_status($context) === \ZLIB_STREAM_END) {
                    break 2;
                }
                $data = @\inflate_add($context, \substr($chunk, $offset, self::INFLATE_CHUNK), \ZLIB_SYNC_FLUSH);
                $data === false and throw new \InvalidArgumentException('The trace is not gzip data, as SPX writes it.');

                $size += \strlen($data);
                $size > $maxSize and throw new \InvalidArgumentException(
                    "The trace is larger than $maxSize bytes decompressed.",
                );
                $parse($data, false);
            }
        }

        \inflate_get_status($context) === \ZLIB_STREAM_END
            or throw new \InvalidArgumentException('The trace is truncated: the gzip stream does not end.');
        $parse('', true);

        $section === 'functions' or throw new \InvalidArgumentException(
            $section === null ? 'The trace is empty.' : 'The trace is truncated: no `[functions]` section.',
        );
        \count($names) === $functionCount or throw new \InvalidArgumentException(
            \sprintf('The trace names %d functions, the metadata tells %d.', \count($names), $functionCount),
        );

        return $names;
    }

    /**
     * Reads a stream in chunks, from its current position to the end.
     *
     * @param resource $handle
     *
     * @return \Generator<int, string>
     */
    public static function chunks($handle, int $size = 65536): \Generator
    {
        while (!\feof($handle)) {
            $chunk = \fread($handle, $size);
            $chunk === false and throw new \RuntimeException('Unable to read the trace.');
            $chunk === '' or yield $chunk;
        }
    }
}
