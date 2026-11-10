<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Frontend;

use Buggregator\Trap\Server\Proto\Frame;

/**
 * Turns a frame into an event of the Buggregator UI.
 *
 * Mappers are container singletons. A mapper gets only frames of the classes it is registered for
 * in {@see ProvidesUi::uiEventMappers()}.
 *
 * @internal
 */
interface EventMapper
{
    /**
     * @return Event|null Null when the frame produces no new event.
     */
    public function map(Frame $frame): ?Event;
}
