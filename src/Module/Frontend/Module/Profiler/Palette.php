<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Frontend\Module\Profiler;

/**
 * Colors of the profiler charts by the share of the metric (0-100%).
 *
 * @internal
 */
final class Palette
{
    /**
     * From white to black through dark red: the heavier the node, the darker it is.
     */
    public static function callGraphNode(float $percent): string
    {
        return match (true) {
            $percent <= 10 => '#FFFFFF',
            $percent <= 20 => '#F19797',
            $percent <= 30 => '#D93939',
            $percent <= 40 => '#AD1E1E',
            $percent <= 50 => '#982525',
            $percent <= 60 => '#862323',
            $percent <= 70 => '#671D1D',
            $percent <= 80 => '#540D0D',
            $percent <= 90 => '#340707',
            default => '#000000',
        };
    }

    /**
     * Light blue for cheap spans, red for expensive ones.
     */
    public static function flameChartSpan(float $percent): string
    {
        return match (true) {
            $percent <= 10 => '#B3E5FC',
            $percent <= 20 => '#81D4FA',
            $percent <= 30 => '#4FC3F7',
            $percent <= 40 => '#29B6F6',
            $percent <= 50 => '#FFCDD2',
            $percent <= 60 => '#FFB2B2',
            $percent <= 70 => '#FF9E9E',
            $percent <= 80 => '#FF8989',
            $percent <= 90 => '#FF7474',
            default => '#FF5F5F',
        };
    }

    /**
     * Black or white text, whichever is readable on the given `#RRGGBB` background.
     */
    public static function textOn(string $background): string
    {
        $hex = \ltrim($background, '#');
        $r = \hexdec(\substr($hex, 0, 2));
        $g = \hexdec(\substr($hex, 2, 2));
        $b = \hexdec(\substr($hex, 4, 2));

        return ($r * 299 + $g * 587 + $b * 114) / 1000 > 125 ? '#000000' : '#FFFFFF';
    }
}
