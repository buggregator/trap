<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Mcp;

use Buggregator\Trap\Server\Application\Module\Module;

/**
 * A module with tools for the MCP clients.
 *
 * @internal
 */
interface ProvidesMcpTools extends Module
{
    /**
     * Tools are created as container singletons when the MCP server is turned on.
     *
     * @return list<class-string<Tool>>
     */
    public function mcpTools(): array;
}
