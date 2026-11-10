<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Frontend;

use Buggregator\Trap\Application\Module\Module;
use Buggregator\Trap\Proto\Frame;

/**
 * A module shown in the Buggregator UI.
 *
 * @internal
 */
interface ProvidesUi extends Module
{
    /**
     * Controllers of the routes answered with a JSON message, over HTTP and the WebSocket RPC.
     * Controllers are container singletons.
     *
     * @return list<class-string>
     */
    public function uiApiControllers(): array;

    /**
     * Controllers of the HTTP-only routes answered with a ready response: HTML, files.
     *
     * @return list<class-string>
     */
    public function uiAssetsControllers(): array;

    /**
     * A mapper also takes the subclasses of its frame class. A frame no mapper takes fails to map.
     *
     * @return array<class-string<Frame>, class-string<EventMapper>|null> Mappers by frame class;
     *         null for frames the UI has no event type for.
     */
    public function uiEventMappers(): array;

    /**
     * Event types dictated by the UI, not frame types. The UI hides the others from its menu.
     *
     * @return list<non-empty-string>
     */
    public function uiEventTypes(): array;
}
