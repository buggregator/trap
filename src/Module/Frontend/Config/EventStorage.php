<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Frontend\Config;

use Buggregator\Trap\Server\Application\Config\Internal\Attribute\InflectableConfig;
use Buggregator\Trap\Server\Application\Config\Internal\Attribute\XPath;

/**
 * Configuration for the frontend events buffer.
 *
 * @internal
 */
#[InflectableConfig]
final class EventStorage
{
    /**
     * The maximum number of events that can be stored in the buffer.
     * @var int<1, max>
     */
    #[XPath('/trap/frontend/EventStorage/@maxEvents')]
    public int $maxEvents = 200;
}
