<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Event;

use Buggregator\Trap\Server\Application\Module\Module;
use Buggregator\Trap\Server\Proto\Frame;

/**
 * A module whose frames become events of the {@see EventStore}.
 *
 * @internal
 */
interface ProvidesEvents extends Module
{
    /**
     * A mapper also takes the subclasses of its frame class. A frame no mapper takes fails to map.
     *
     * @return array<class-string<Frame>, class-string<EventMapper>|null> Mappers by frame class;
     *         null for frames that make no event.
     */
    public function eventMappers(): array;

    /**
     * Event types dictated by the UI, not frame types. The UI hides the others from its menu.
     *
     * @return list<non-empty-string>
     */
    public function eventTypes(): array;
}
