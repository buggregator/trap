<?php

declare(strict_types=1);

namespace Buggregator\Trap\Config\Frontend;

/**
 * Configuration for the frontend buffer.
 * @internal
 */
final class Buffer
{
    /**
     * @param int<1, max> $maxSize The maximum number of events that can be stored in the buffer.
     */
    public function __construct(
        public readonly int $maxSize = 200,
    ) {
    }
}
