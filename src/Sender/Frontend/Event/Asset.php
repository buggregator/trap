<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Frontend\Event;

/**
 * @internal
 */
abstract class Asset
{
    public function __construct(
        public readonly string $uuid,
    ) {
    }
}
