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
    private string $clientName = '';

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

    /**
     * The server is stateless, so the name is of the client that connected last: one agent at a time as a rule.
     */
    public function clientNamed(string $name): void
    {
        $this->clientName = \substr(\trim($name), 0, 100);
    }

    /**
     * The name the last MCP client gave in `initialize`, as `claude-code`; empty before one.
     */
    public function clientName(): string
    {
        return $this->clientName;
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
