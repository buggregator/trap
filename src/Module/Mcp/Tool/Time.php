<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Mcp\Tool;

/**
 * Times in the results of the tools.
 *
 * @internal
 */
final class Time
{
    /**
     * ISO 8601 in UTC with milliseconds.
     *
     * @param float $timestamp Unix time in seconds.
     */
    public static function format(float $timestamp): string
    {
        $time = \DateTimeImmutable::createFromFormat('U.u', \sprintf('%.6F', $timestamp));

        return $time === false ? '' : $time->format('Y-m-d\TH:i:s.vP');
    }
}
