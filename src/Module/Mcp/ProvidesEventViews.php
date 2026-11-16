<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Mcp;

use Buggregator\Trap\Module\Mcp\View\EventView;
use Buggregator\Trap\Server\Application\Module\Module;

/**
 * A module that shows its events to the MCP clients in its own way. The views of the modules are asked
 * before the default ones: a summary from the payload and the text of the console renderers.
 *
 * @internal
 */
interface ProvidesEventViews extends Module
{
    /**
     * Views are created as container singletons.
     *
     * @return list<class-string<EventView>>
     */
    public function eventViews(): array;
}
