<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Event;

use Buggregator\Trap\Server\Proto\Frame;

/**
 * Turns a frame into an event of the Buggregator UI.
 *
 * Mappers are container singletons. A mapper gets only frames of the classes it is registered for
 * in {@see ProvidesEvents::eventMappers()}.
 *
 * @internal
 */
interface EventMapper
{
    /**
     * @return Event|null Null when the frame produces no new event. An event with the UUID of a stored one
     *         replaces it, and the frame joins the frames of the stored one.
     */
    public function map(Frame $frame): ?Event;
}
