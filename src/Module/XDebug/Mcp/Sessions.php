<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\XDebug\Mcp;

use Buggregator\Trap\Module\XDebug\DebuggerError;
use Buggregator\Trap\Module\XDebug\Session;

/**
 * @internal
 */
final class Sessions extends DebugTool
{
    #[\Override]
    public function name(): string
    {
        return 'xdebug_sessions';
    }

    #[\Override]
    public function title(): string
    {
        return 'Xdebug sessions';
    }

    #[\Override]
    public function description(): string
    {
        return 'Lists the Xdebug debug sessions: `state` (`break` = paused at a line, `running`, `stopped`, '
            . '`detached`), `driver` (`here`: Trap drives it and the agent may step; `ide`: an IDE drives it, reads '
            . 'only), the request or command line, where it is paused and the `exception` it stopped at. Start here; '
            . 'the settings of the debugger come with the list: `allow_eval`, `allow_control`, `mode`, and '
            . '`pause_on_first_line` (new sessions stop at their first line, with no breakpoint).';
    }

    #[\Override]
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'live_only' => ['type' => 'boolean', 'description' => 'Leave out the closed sessions.'],
            ],
            'additionalProperties' => false,
        ];
    }

    #[\Override]
    public function call(array $arguments): array
    {
        $this->debugger->isEnabled() or throw new \RuntimeException(DebuggerError::off()->getMessage());
        $config = $this->debugger->config;

        $sessions = $this->debugger->sessions();
        ($arguments['live_only'] ?? false) === true
            and $sessions = \array_values(\array_filter($sessions, static fn(Session $s): bool => !$s->isClosed()));

        return [
            'sessions' => \array_map(self::info(...), $sessions),
            'allow_eval' => $config->allowEval,
            'allow_control' => $config->allowControl,
        ] + $this->debugger->settings();
    }
}
