<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\XDebug\Mcp;

use Buggregator\Trap\Module\Mcp\WaitingTool;
use Buggregator\Trap\Module\XDebug\Session;

/**
 * @internal
 */
final class Stack extends DebugTool implements WaitingTool
{
    #[\Override]
    public function name(): string
    {
        return 'xdebug_stack';
    }

    #[\Override]
    public function title(): string
    {
        return 'Xdebug stack';
    }

    #[\Override]
    public function description(): string
    {
        return 'The call stack of a paused session, the current frame (level 0) first: function, file and line. '
            . 'Pass a `level` as `frame` to `xdebug_variables`.';
    }

    #[\Override]
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['session_id' => self::SESSION_ID],
            'additionalProperties' => false,
        ];
    }

    #[\Override]
    public function call(array $arguments): array
    {
        return $this->onSession($arguments, static function (Session $session): array {
            $frames = $session->read('stack_get')->stack();

            return [['frames' => $frames], \count($frames) . ' frames'];
        });
    }
}
