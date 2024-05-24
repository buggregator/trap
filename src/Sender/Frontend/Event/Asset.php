<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Frontend\Event;

/**
 * @internal
 */
abstract class Asset
{
    /**
     * @param non-empty-string $uuid
     */
    public function __construct(
        public readonly string $uuid,
    ) {}
}
