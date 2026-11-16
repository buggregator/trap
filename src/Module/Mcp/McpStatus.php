<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Mcp;

use Buggregator\Trap\Module\Mcp\Sender\McpSender;

/**
 * Whether the MCP server runs in this Trap process: the {@see McpSender} turns it on when created.
 * A module keeps data only its tools read while the server runs.
 *
 * A container singleton.
 *
 * @internal
 */
final class McpStatus
{
    private bool $on = false;

    public function turnOn(): void
    {
        $this->on = true;
    }

    public function isOn(): bool
    {
        return $this->on;
    }
}
