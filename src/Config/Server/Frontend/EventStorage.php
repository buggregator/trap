<?php

declare(strict_types=1);

namespace Buggregator\Trap\Config\Server\Frontend;

use Buggregator\Trap\Service\Config\XPath;

/**
 * Configuration for the frontend events buffer.
 *
 * @internal
 */
final class EventStorage
{
    /**
     * The maximum number of events that can be stored in the buffer.
     * @var int<1, max>
     */
    #[XPath('/trap/frontend/EventStorage@maxEvents')]
    public int $maxEvents = 200;
}
