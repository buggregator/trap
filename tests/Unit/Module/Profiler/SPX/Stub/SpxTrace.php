<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Profiler\SPX\Stub;

/**
 * Generates large SPX reports in code, streamed: the trace is never held whole.
 *
 * Calls of a framework-like app: random functions out of a few thousand, nested up to 40 deep, with the wall time,
 * CPU time and memory as SPX writes them. The random generator is seeded, so a report is the same on every run.
 */
final class SpxTrace
{
    public const FUNCTIONS = 3000;

    /**
     * @param positive-int $calls
     */
    public static function metadata(int $calls): string
    {
        return \json_encode([
            'key' => 'spx-full-generated',
            'exec_ts' => 1747569600,
            'host_name' => 'bench',
            'cli' => 1,
            'cli_command_line' => 'bin/console app:sync',
            'called_function_count' => self::FUNCTIONS,
            'call_count' => $calls,
            'recorded_call_count' => $calls,
            'enabled_metrics' => ['wt', 'ct', 'zm'],
        ], \JSON_THROW_ON_ERROR);
    }

    /**
     * @param positive-int $calls
     *
     * @return \Generator<int, string> Gzipped chunks.
     */
    public static function chunks(int $calls): \Generator
    {
        \mt_srand(42);
        $deflate = \deflate_init(\ZLIB_ENCODING_GZIP, ['level' => 1]);
        $lines = "[events]\n0 1 0 0 0\n";
        $stack = [0];
        $time = 0;
        $memory = 0;
        for ($made = 1; $made < $calls || \count($stack) > 1;) {
            $time += \mt_rand(100, 5000);
            $memory += \mt_rand(-200, 300);
            $values = $time . ' ' . \intdiv($time * 9, 10) . ' ' . $memory;
            if ($made < $calls && \count($stack) < 40 && \mt_rand(0, 9) < 5) {
                $function = \mt_rand(1, self::FUNCTIONS - 1);
                $stack[] = $function;
                $lines .= "$function 1 $values\n";
                ++$made;
            } elseif (\count($stack) > 1) {
                $lines .= \array_pop($stack) . " 0 $values\n";
            }

            if (\strlen($lines) > 65536) {
                yield (string) \deflate_add($deflate, $lines, \ZLIB_NO_FLUSH);
                $lines = '';
            }
        }

        $lines .= '0 0 ' . ($time + 1) . ' ' . ($time + 1) . " 0\n[functions]\n/app/bin/console\n";
        for ($i = 1; $i < self::FUNCTIONS; ++$i) {
            $lines .= 'App\Module' . ($i % 40) . "\\Service$i::handle\n";
        }
        yield (string) \deflate_add($deflate, $lines, \ZLIB_FINISH);
    }
}
