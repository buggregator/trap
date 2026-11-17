<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler\Mcp;

use Buggregator\Trap\Module\Profiler\Struct\Metric;

/**
 * Parts of the schemas the profile tools share.
 *
 * @internal
 */
final class ProfileSchema
{
    /**
     * What the metrics are and which formats have them: a profiler that doesn't measure a metric leaves it out.
     */
    public const METRICS = '`wt` wall time and `cpu` CPU time in microseconds, `mu` memory and `pmu` peak memory '
        . 'in bytes, `ct` the calls, or the samples of a sampling profiler. XHProf has `wt`, `ct`, and `cpu`, `mu`, '
        . '`pmu` when enabled; Xdebug `wt`, `mu`, `ct`; SPX `wt`, `ct`, and `cpu`, `mu` when enabled; pprof `ct` '
        . 'with `wt` and `cpu` for a CPU profile or `mu` for a memory one; Speedscope (Excimer) `wt` (`cpu` too with '
        . 'the CPU clock) or `mu`, and `ct`. `profile_summary` lists the metrics of a profile.';

    /**
     * @return array<non-empty-string, mixed> The property of the input schema.
     */
    public static function metric(): array
    {
        return [
            'type' => 'string',
            'enum' => \array_map(static fn(Metric $metric): string => $metric->value, Metric::cases()),
            'description' => 'The metric: ' . self::METRICS . ' By default the first the profile has of `wt`, '
                . '`cpu`, `mu`, `ct`, as the console ranks by.',
        ];
    }

    /**
     * @return array<non-empty-string, mixed> A function summed over all its calls.
     */
    public static function row(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'function' => ['type' => 'string'],
                'calls' => ['type' => 'integer', 'description' => 'Calls, or samples with the function on the stack.'],
                'inclusive' => ['type' => 'integer', 'description' => 'The metric with the callees.'],
                'inclusive_percent' => self::percent(),
                'exclusive' => ['type' => 'integer', 'description' => 'The metric of the function itself.'],
                'exclusive_percent' => self::percent(),
            ],
            'required' => ['function', 'calls', 'inclusive', 'inclusive_percent', 'exclusive', 'exclusive_percent'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array<non-empty-string, mixed> A share of the `peak`.
     */
    public static function percent(): array
    {
        return [
            'type' => ['number', 'null'],
            'description' => 'Of `peak`, %; null for `ct`, whose peak is the most calls of one caller and callee pair.',
        ];
    }

    /**
     * @return array<non-empty-string, mixed> The schema properties of the metric a result is in.
     */
    public static function metricProperties(): array
    {
        return [
            'metric' => ['type' => 'string'],
            'unit' => [
                'type' => 'string',
                'description' => '`microseconds`, `bytes`, or what `ct` counts: `calls`, `samples`, ...',
            ],
            'peak' => [
                'type' => 'integer',
                'description' => 'The value the percentages are of: the largest value of one caller and callee '
                    . 'pair, the root\'s for the times and memory, as the console shows; the sum of the roots when '
                    . 'larger, as of a root added to a profile without `main()`.',
            ],
        ];
    }
}
