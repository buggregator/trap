<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\XDebugTrace\Mcp;

use Buggregator\Trap\Module\Mcp\Tool;

/**
 * @internal
 */
final class TraceCall implements Tool
{
    public function __construct(
        private readonly Traces $traces,
    ) {}

    #[\Override]
    public function name(): string
    {
        return 'xdebug_trace_call';
    }

    #[\Override]
    public function title(): string
    {
        return 'Xdebug trace call';
    }

    #[\Override]
    public function description(): string
    {
        return 'One call of an Xdebug trace by its `offset`: function, file and line, arguments, return value, '
            . '`incl` and `self` time in seconds, memory change, and its first 50 direct callees. A non-null `from` '
            . 'means more callees: call `xdebug_trace_calls` with this `parent` and `from`.';
    }

    #[\Override]
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'uuid' => ['type' => 'string', 'description' => 'UUID of the `xdebug-trace` event.'],
                'offset' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'description' => '`offset` of the call, from `xdebug_trace_calls` or the `slowest` calls of '
                        . '`xdebug_trace_summary`.',
                ],
            ],
            'required' => ['uuid', 'offset'],
            'additionalProperties' => false,
        ];
    }

    #[\Override]
    public function outputSchema(): ?array
    {
        return null;
    }

    #[\Override]
    public function annotations(): array
    {
        return ['readOnlyHint' => true, 'openWorldHint' => false];
    }

    #[\Override]
    public function call(array $arguments): array
    {
        $tree = $this->traces->tree($arguments);
        $offset = Traces::int($arguments, 'offset') ?? throw new \InvalidArgumentException('`offset` is required.');
        $page = $tree->children($offset, -1, Traces::DEFAULT_LIMIT);

        return Traces::object([
            'call' => $page->parent,
            'callees' => $page->nodes,
            'parent' => $offset,
            'from' => $page->next,
            'truncated' => $page->truncated,
        ]);
    }
}
