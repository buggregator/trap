<?php

declare(strict_types=1);

namespace Buggregator\Trap\Client\Profiler\Internal\Model;

/**
 * Writes a {@see CallGraph} of a sampled profile as XHProf JSON.
 *
 * Every entry has all of `ct`, `wt`, `cpu`, `mu` and `pmu`, since Buggregator Server expects them all:
 * calls are not measured by sampling, so `ct` holds the samples; memory is not measured, so `mu` and `pmu` are 0.
 *
 * @internal
 */
final class XHProfWriter
{
    /**
     * Encodes the JSON object piece by piece, without building the array of a large profile.
     * Invalid UTF-8 in function names is replaced with U+FFFD.
     */
    public static function json(CallGraph $graph): string
    {
        [$wt, $cpu, $samples] = $graph->costs();

        $flags = \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE;
        $parts = [];
        foreach ($graph->keys() as $key => $id) {
            $parts[] = \json_encode((string) $key, $flags)
                . ":{\"ct\":{$samples[$id]},\"wt\":{$wt[$id]},\"cpu\":{$cpu[$id]},\"mu\":0,\"pmu\":0}";
        }

        return '{' . \implode(',', $parts) . '}';
    }
}
