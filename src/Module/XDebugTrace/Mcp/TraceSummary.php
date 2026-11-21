<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\XDebugTrace\Mcp;

use Buggregator\Trap\Module\Mcp\Tool;

/**
 * @internal
 */
final class TraceSummary implements Tool
{
    public function __construct(
        private readonly Traces $traces,
    ) {}

    #[\Override]
    public function name(): string
    {
        return 'xdebug_trace_summary';
    }

    #[\Override]
    public function title(): string
    {
        return 'Xdebug trace summary';
    }

    #[\Override]
    public function description(): string
    {
        return 'Overview of one Xdebug function trace: totals, the 20 functions with the most self time (time in '
            . 'the function itself), and the 20 slowest single calls by inclusive time, each with its `offset` for '
            . '`xdebug_trace_call`. Times are seconds, memory bytes. `truncated` means the trace was cut at the size '
            . 'limit or has no end. Find traces with `list_events` and the type `xdebug-trace`.';
    }

    #[\Override]
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'uuid' => ['type' => 'string', 'description' => 'UUID of the `xdebug-trace` event.'],
            ],
            'required' => ['uuid'],
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
        $payload = $this->traces->payload($arguments);

        return [
            'name' => $payload['name'],
            'url' => $payload['url'],
            'size' => $payload['size'],
            'summary' => $payload['summary'],
        ];
    }
}
