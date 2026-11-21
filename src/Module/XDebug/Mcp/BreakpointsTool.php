<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\XDebug\Mcp;

use Buggregator\Trap\Module\XDebug\DebuggerError;

/**
 * Saved breakpoints: listed, set or removed by `action`.
 *
 * @internal
 */
final class BreakpointsTool extends DebugTool
{
    #[\Override]
    public function name(): string
    {
        return 'xdebug_breakpoints';
    }

    #[\Override]
    public function title(): string
    {
        return 'Xdebug breakpoints';
    }

    #[\Override]
    public function description(): string
    {
        return 'Saved breakpoints, sent to every session Trap drives. `action: list` (default); `action: set` with '
            . '`file` (absolute path as PHP sees it) and `line`, optional `condition` (PHP, needs eval allowed), or '
            . 'with `exception` (a class name, parents match, `*` for any); `action: remove` with `id`. '
            . 'An IDE keeps breakpoints of its own for the sessions it drives.';
    }

    #[\Override]
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => ['list', 'set', 'remove']],
                'file' => ['type' => 'string'],
                'line' => ['type' => 'integer', 'minimum' => 1],
                'condition' => ['type' => 'string'],
                'exception' => ['type' => 'string'],
                'id' => ['type' => 'integer', 'minimum' => 1],
            ],
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
        $this->debugger->isEnabled() or throw new \RuntimeException(DebuggerError::off()->getMessage());
        $breakpoints = $this->debugger->breakpoints;
        /** @var mixed $action */
        $action = $arguments['action'] ?? 'list';

        try {
            switch ($action) {
                case 'list':
                    return ['breakpoints' => $breakpoints->all()];
                case 'set':
                    $this->assertControl();
                    isset($arguments['condition']) && !$this->debugger->allowEval()
                        and throw DebuggerError::evalDisabled('A breakpoint condition');
                    $fields = \array_intersect_key($arguments, ['file' => 1, 'line' => 1, 'condition' => 1, 'exception' => 1]);
                    $bp = $breakpoints->add($fields, 'mcp');
                    $this->debugger->breakpointsChanged();
                    return ['breakpoint' => $bp];
                case 'remove':
                    $this->assertControl();
                    $breakpoints->delete(self::int($arguments, 'id', 0));
                    $this->debugger->breakpointsChanged();
                    return ['breakpoints' => $breakpoints->all()];
                default:
                    throw new \InvalidArgumentException('`action` must be list, set or remove.');
            }
        } catch (DebuggerError $e) {
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }
    }
}
