<?php

declare(strict_types=1);

namespace Buggregator\Trap\Support;

/**
 * Data object representing a tick.
 *
 * @see tr()
 * @internal
 */
final class Tick
{
    /**
     * @param int<0, max> $number The tick number.
     * @param float $delta The time delta between the current and previous tick.
     * @param int $memory The memory usage.
     * @param array{
     *     function?: string,
     *     line?: int,
     *     file?: string,
     *     class?: class-string,
     *     type?: string,
     *     args?: list<mixed>
     * } $line The stack trace line.
     */
    public function __construct(
        public readonly int $number,
        public readonly float $delta,
        public readonly int $memory,
        public readonly array $line,
    ) {
    }

    public function __toString(): string
    {
        // Format delta
        $delta = $this->delta;
        $deltaStr = match (true) {
            $delta === 0.0 => '-.---',
            $delta < 0.001 => \sprintf('+%.3fms', $delta * 1000),
            $delta < 0.01 => \sprintf('+%.2fms', $delta * 1000),
            $delta < 1 => \sprintf('+%.1fms', ($delta * 1000)),
            $delta < 10 => \sprintf('+%.2fs', $delta),
            $delta < 60 => \sprintf('+%.3fs', $delta),
            default => \sprintf('+%dm %ds', (int) $delta % 60, (int) $delta % 60),
        };

        return \sprintf(
            "Tick #%d  %s  %sM",
            $this->number,
            $deltaStr,
            \number_format(
                $this->memory / 1024 / 1024,
                2,
                '.',
                '_',
            ),
        );
    }
}
