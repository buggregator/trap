<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\XDebugTrace\Mcp;

use Buggregator\Trap\Module\Mcp\Tool;

/**
 * @internal
 */
final class TraceCalls implements Tool
{
    public function __construct(
        private readonly Traces $traces,
    ) {}

    #[\Override]
    public function name(): string
    {
        return 'xdebug_trace_calls';
    }

    #[\Override]
    public function title(): string
    {
        return 'Xdebug trace calls';
    }

    #[\Override]
    public function description(): string
    {
        return 'Walks the call tree of an Xdebug trace one level at a time, or searches it. No `parent`: the '
            . 'top-level calls. `parent=<offset>`: the direct callees of that call. A non-null `next` means more: '
            . 'call again with `from=next` and the same `parent` or `query`. `query=<text>`: the calls whose '
            . 'function or file contains the text, case-insensitively, each with `path`, its callers. Each call has '
            . '`incl` and `self` time in seconds, the memory change, arguments and return value (cut to 512 bytes), '
            . 'the number of direct callees and its `offset`.';
    }

    #[\Override]
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'uuid' => ['type' => 'string', 'description' => 'UUID of the `xdebug-trace` event.'],
                'parent' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'description' => '`offset` of a call: list its direct callees. Omit for the top-level calls.',
                ],
                'from' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'description' => '`next` of a previous answer: continue the same list.',
                ],
                'query' => [
                    'type' => 'string',
                    'description' => 'Search instead: the calls whose function name or file contains this text.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => Traces::MAX_LIMIT,
                    'description' => \sprintf('Calls per answer, %d by default.', Traces::DEFAULT_LIMIT),
                ],
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
        $tree = $this->traces->tree($arguments);
        $parent = Traces::int($arguments, 'parent', -1);
        $from = Traces::int($arguments, 'from', -1);
        $limit = (int) Traces::int($arguments, 'limit', Traces::DEFAULT_LIMIT);
        $limit = $limit < 1 ? Traces::DEFAULT_LIMIT : \min($limit, Traces::MAX_LIMIT);
        /** @var mixed $query */
        $query = $arguments['query'] ?? '';
        \is_string($query) or throw new \InvalidArgumentException('`query` must be a string.');
        $query = \trim($query);

        $page = $query === ''
            ? $tree->children((int) $parent, (int) $from, $limit)
            : $tree->search($query, \max((int) $from, 0), $limit);

        return Traces::object($page);
    }
}
