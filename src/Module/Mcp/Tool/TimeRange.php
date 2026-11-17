<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Mcp\Tool;

/**
 * The `from` and `to` arguments, in whole microseconds: the tools list times in milliseconds, and a time compared
 * as a float may land a fraction off the one listed.
 *
 * @internal
 */
final class TimeRange
{
    /**
     * @param int|null $from Unix time in microseconds of the first included moment; unbounded when null.
     * @param int|null $to Unix time in microseconds of the last included moment; unbounded when null.
     */
    public function __construct(
        public readonly ?int $from = null,
        public readonly ?int $to = null,
    ) {}

    public function isUnbounded(): bool
    {
        return $this->from === null && $this->to === null;
    }

    /**
     * @param float $timestamp Unix time in seconds.
     */
    public function contains(float $timestamp): bool
    {
        $time = Time::micros($timestamp);

        return ($this->from === null || $time >= $this->from) && ($this->to === null || $time <= $this->to);
    }
}
