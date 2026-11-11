<?php

declare(strict_types=1);

namespace Buggregator\Trap\Client\Profiler\Internal\Model;

/**
 * Writes a {@see CallGraph} as the XHProf array or its JSON.
 *
 * Every entry has all of `ct`, `wt`, `cpu`, `mu` and `pmu`, the unmeasured ones as 0, since Buggregator Server
 * expects them all. When calls were not measured (sampling), `ct` holds the samples.
 *
 * @internal
 */
final class XHProfWriter
{
    /**
     * @return array<non-empty-string, array{ct: int, wt: int, cpu: int, mu: int, pmu: int}>
     */
    public static function write(CallGraph $graph): array
    {
        [$wt, $cpu, $mu, $pmu, $ct, $samples] = $graph->costs();
        \in_array(Metric::Calls, $graph->metrics, true) or $ct = $samples;

        $result = [];
        foreach ($graph->keys() as $key => $id) {
            $result[$key] = ['ct' => $ct[$id], 'wt' => $wt[$id], 'cpu' => $cpu[$id], 'mu' => $mu[$id], 'pmu' => $pmu[$id]];
        }

        return $result;
    }

    /**
     * The same as {@see write()} encoded as a JSON object, without building the array of a large profile.
     * Invalid UTF-8 in function names is replaced with U+FFFD.
     */
    public static function json(CallGraph $graph): string
    {
        [$wt, $cpu, $mu, $pmu, $ct, $samples] = $graph->costs();
        \in_array(Metric::Calls, $graph->metrics, true) or $ct = $samples;

        $flags = \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE;
        $parts = [];
        foreach ($graph->keys() as $key => $id) {
            $parts[] = \json_encode((string) $key, $flags)
                . ":{\"ct\":{$ct[$id]},\"wt\":{$wt[$id]},\"cpu\":{$cpu[$id]},\"mu\":{$mu[$id]},\"pmu\":{$pmu[$id]}}";
        }

        return '{' . \implode(',', $parts) . '}';
    }
}
