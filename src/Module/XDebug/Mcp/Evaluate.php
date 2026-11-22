<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\XDebug\Mcp;

use Buggregator\Trap\Module\Mcp\WaitingTool;
use Buggregator\Trap\Module\XDebug\DebuggerError;
use Buggregator\Trap\Module\XDebug\Session;

/**
 * @internal
 */
final class Evaluate extends DebugTool implements WaitingTool
{
    #[\Override]
    public function name(): string
    {
        return 'xdebug_eval';
    }

    #[\Override]
    public function title(): string
    {
        return 'Xdebug eval';
    }

    #[\Override]
    public function description(): string
    {
        return 'Evaluates a PHP expression in the current frame (level 0) of a paused session and returns its value; '
            . 'Xdebug evaluates in no other frame: read those with `xdebug_variables` and `frame`. A PHP warning or '
            . 'notice of the expression, as an undefined variable, fails the call. It runs in the debugged script and '
            . 'may change its state. Off unless Trap runs with TRAP_XDEBUG_ALLOW_EVAL=1.';
    }

    #[\Override]
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'session_id' => self::SESSION_ID,
                'expression' => ['type' => 'string', 'description' => 'A PHP expression, as `count($items)`.'],
            ],
            'required' => ['expression'],
            'additionalProperties' => false,
        ];
    }

    #[\Override]
    public function annotations(): array
    {
        return ['readOnlyHint' => false, 'destructiveHint' => true, 'openWorldHint' => false];
    }

    #[\Override]
    public function call(array $arguments): array
    {
        $this->debugger->allowEval() or throw new \RuntimeException(DebuggerError::evalDisabled()->getMessage());
        /** @var mixed $expression */
        $expression = $arguments['expression'] ?? null;
        \is_string($expression) && \trim($expression) !== ''
            or throw new \InvalidArgumentException('`expression` must be a non-empty string.');

        return $this->onSession($arguments, static function (Session $session) use ($expression): array {
            $value = $session->evaluate($expression)->properties()[0] ?? ['name' => '', 'type' => 'null'];

            return [['value' => $value], $value['type']];
        });
    }
}
