<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Frontend;

use Buggregator\Trap\Server\Application\Module\Module;

/**
 * A module with its own endpoints in the Buggregator UI. The events it shows come from
 * {@see \Buggregator\Trap\Server\Event\ProvidesEvents}.
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
}
