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

    /** {@see \hrtime()} of the last tool call; null before the first one. */
    private ?int $lastToolCall = null;

    public function turnOn(): void
    {
        $this->on = true;
    }

    public function isOn(): bool
    {
        return $this->on;
    }

    public function toolCalled(): void
    {
        $this->lastToolCall = (int) \hrtime(true);
    }

    /**
     * Whether a tool was called in the last `$seconds` seconds.
     */
    public function toolCalledWithin(float $seconds): bool
    {
        return $this->lastToolCall !== null && \hrtime(true) - $this->lastToolCall <= (int) ($seconds * 1e9);
    }
}
