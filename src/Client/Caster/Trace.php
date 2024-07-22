<?php

declare(strict_types=1);

namespace Buggregator\Trap\Client\Caster;

/**
 * Data object representing a trace.
 *
 * @see tr()
 * @internal
 */
final class Trace implements \Stringable
{
    /**
     * @param int<0, max> $number The tick number.
     * @param float $delta The time delta between the current and previous tick.
     * @param int<0, max> $memory The memory usage.
     * @param list<array{
     *     function: non-empty-string,
     *     line?: int,
     *     file?: string,
     *     class?: class-string,
     *     type?: string,
     *     args?: list<mixed>
     * }> $stack The stack trace.
     */
    public function __construct(
        public readonly int $number,
        public readonly float $delta,
        public readonly int $memory,
        public readonly array $stack,
    ) {}

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
            $delta < 60 => \sprintf('+%.1fs', $delta),
            default => \sprintf('+%dm %ds', (int) $delta % 60, (int) $delta % 60),
        };

        return \sprintf(
            "Trace #%d  %s  %sM",
            $this->number,
            $deltaStr,
            \number_format($this->memory / 1024 / 1024, 2, '.', '_'),
        );
    }
}
