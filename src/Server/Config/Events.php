<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Config;

use Buggregator\Trap\Server\Application\Config\Internal\Attribute\InflectableConfig;
use Buggregator\Trap\Server\Application\Config\Internal\Attribute\XPath;

/**
 * The event buffer shared by the web UI and the other event consumers.
 *
 * @internal
 */
#[InflectableConfig]
final class Events
{
    /**
     * The maximum number of events in the buffer: a new event pushes out the oldest one.
     * @var int<1, max>
     */
    #[XPath('/trap/events/@maxEvents')]
    public int $maxEvents = 200;
}
