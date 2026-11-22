<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\XDebug\Mcp;

use Buggregator\Trap\Module\Mcp\WaitingTool;
use Buggregator\Trap\Module\XDebug\DebuggerError;
use Buggregator\Trap\Support\Timer;

/**
 * @internal
 */
final class Wait extends DebugTool implements WaitingTool
{
    #[\Override]
    public function name(): string
    {
        return 'xdebug_wait';
    }

    #[\Override]
    public function title(): string
    {
        return 'Xdebug wait';
    }

    #[\Override]
    public function description(): string
    {
        return 'Waits up to `timeout` seconds until a session is paused, as after a request hits a breakpoint: set '
            . 'the breakpoint, start the request, then wait. Returns the paused session, with the `exception` (`class`, '
            . '`message`) at an exception break, or `timed_out`.';
    }

    #[\Override]
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'timeout' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 50, 'description' => 'Seconds, 30 by default.'],
            ],
            'additionalProperties' => false,
        ];
    }

    #[\Override]
    public function call(array $arguments): array
    {
        $this->debugger->isEnabled() or throw new \RuntimeException(DebuggerError::off()->getMessage());
        $timer = new Timer((float) \min(50, self::int($arguments, 'timeout', 30)));
        while (true) {
            foreach ($this->debugger->sessions() as $session) {
                if ($session->isPaused()) {
                    return ['timed_out' => false, 'session' => self::info($session)];
                }
            }
            if ($timer->isReady() || \Fiber::getCurrent() === null || $this->caller->isDisconnected()) {
                return ['timed_out' => true];
            }
            \Fiber::suspend();
        }
    }
}
