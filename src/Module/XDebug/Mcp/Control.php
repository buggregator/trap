<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\XDebug\Mcp;

use Buggregator\Trap\Module\Mcp\WaitingTool;
use Buggregator\Trap\Module\XDebug\Session;

/**
 * @internal
 */
final class Control extends DebugTool implements WaitingTool
{
    private const COMMANDS = ['step_into', 'step_over', 'step_out', 'run', 'stop', 'detach'];

    #[\Override]
    public function name(): string
    {
        return 'xdebug_continue';
    }

    #[\Override]
    public function title(): string
    {
        return 'Xdebug continue';
    }

    #[\Override]
    public function description(): string
    {
        return 'Resumes a paused session that Trap drives (`driver: here`): `step_into`, `step_over`, `step_out`, '
            . '`run` to the next breakpoint, `stop` the script, or `detach` to let it run on without the debugger. '
            . 'Then waits up to `wait` seconds for the next pause and returns the state and location. '
            . 'Off with TRAP_XDEBUG_ALLOW_CONTROL=0.';
    }

    #[\Override]
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'session_id' => self::SESSION_ID,
                'command' => ['type' => 'string', 'enum' => self::COMMANDS],
                'wait' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 50, 'description' => 'Seconds to wait for the next pause, 10 by default.'],
            ],
            'required' => ['command'],
            'additionalProperties' => false,
        ];
    }

    #[\Override]
    public function annotations(): array
    {
        return ['readOnlyHint' => false, 'destructiveHint' => false, 'openWorldHint' => false];
    }

    #[\Override]
    public function call(array $arguments): array
    {
        $this->assertControl();
        /** @var mixed $command */
        $command = $arguments['command'] ?? null;
        \in_array($command, self::COMMANDS, true)
            or throw new \InvalidArgumentException('`command` must be one of: ' . \implode(', ', self::COMMANDS) . '.');
        $wait = \min(50, self::int($arguments, 'wait', 10));
        $agent = $this->agent();

        return $this->onSession($arguments, static function (Session $session) use ($command, $wait, $agent): array {
            $seq = $session->pauseSeq();
            /** @var 'run'|'step_into'|'step_over'|'step_out'|'stop'|'detach' $command */
            $session->continue($command, Session::BY_AGENT, $agent);
            $paused = \in_array($command, ['stop', 'detach'], true) ? false : $session->waitPause($seq, (float) $wait);
            [$file, $line] = $session->location();
            $result = ['state' => $session->state(), 'paused' => $paused]
                + ($paused ? ['file' => $file, 'line' => $line] : []);

            return [$result, $paused ? \basename($file) . ":$line" : $session->state()];
        });
    }
}
