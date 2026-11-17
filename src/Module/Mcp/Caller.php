<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Mcp;

use Buggregator\Trap\Module\Mcp\Http\Endpoint;

/**
 * Whether the client of the tool call running in the current fiber has disconnected, so a tool that waits
 * can stop: the {@see Endpoint} binds the fiber of each request to its connection. A call in an unbound fiber,
 * or outside a fiber, has its client connected.
 *
 * A container singleton.
 *
 * @internal
 */
final class Caller
{
    /** @var \WeakMap<\Fiber, \Closure(): bool> */
    private \WeakMap $connections;

    public function __construct()
    {
        /** @var \WeakMap<\Fiber, \Closure(): bool> $connections */
        $connections = new \WeakMap();
        $this->connections = $connections;
    }

    /**
     * Runs the code with the current fiber bound to the connection.
     *
     * @template T
     *
     * @param \Closure(): bool $disconnected
     * @param \Closure(): T $code
     *
     * @return T
     */
    public function connected(\Closure $disconnected, \Closure $code): mixed
    {
        $fiber = \Fiber::getCurrent();
        if ($fiber === null) {
            return $code();
        }

        $this->connections[$fiber] = $disconnected;
        try {
            return $code();
        } finally {
            unset($this->connections[$fiber]);
        }
    }

    public function isDisconnected(): bool
    {
        $fiber = \Fiber::getCurrent();
        $disconnected = $fiber === null ? null : $this->connections[$fiber] ?? null;

        return $disconnected !== null && $disconnected();
    }
}
