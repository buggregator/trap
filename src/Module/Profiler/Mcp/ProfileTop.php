<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler\Mcp;

use Buggregator\Trap\Module\Mcp\Tool;
use Buggregator\Trap\Module\Mcp\Tool\Arguments;
use Buggregator\Trap\Module\Mcp\Tool\Wildcard;

/**
 * The heaviest functions of a profile by a metric.
 *
 * @internal
 */
final class ProfileTop implements Tool
{
    private const LIMIT = 20;
    private const MAX_LIMIT = 200;

    public function __construct(
        private readonly ProfileAnalyses $analyses,
    ) {}

    #[\Override]
    public function name(): string
    {
        return 'profile_top';
    }

    #[\Override]
    public function title(): string
    {
        return 'Top functions of a profile';
    }

    #[\Override]
    public function description(): string
    {
        return 'Lists the heaviest functions of a profile by a metric, each summed over all its calls: the calls, '
            . 'the inclusive value (with the callees) and the exclusive one (the function itself), with their '
            . 'percentages. Sort by `exclusive` to find where the time goes, by `inclusive` to find the code paths '
            . 'that cost the most. `filter` focuses on some functions, such as the application code: `App\\*`.';
    }

    #[\Override]
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ProfileAnalyses::idSchema(),
                'metric' => ProfileSchema::metric(),
                'sort' => [
                    'type' => 'string',
                    'enum' => ['exclusive', 'inclusive'],
                    'default' => 'exclusive',
                    'description' => 'Rank by the exclusive or the inclusive value of the metric.',
                ],
                'filter' => [
                    'type' => 'string',
                    'description' => 'Only the functions whose names contain this text in any case; with `*`, '
                        . 'matching the whole name, `*` standing for any characters: `App\\*`, `*Repository::find*`.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => self::MAX_LIMIT,
                    'default' => self::LIMIT,
                ],
            ],
            'required' => ['id'],
            'additionalProperties' => false,
        ];
    }

    #[\Override]
    public function outputSchema(): ?array
    {
        return [
            'type' => 'object',
            'properties' => [
                ...ProfileSchema::metricProperties(),
                'sort' => ['type' => 'string', 'enum' => ['exclusive', 'inclusive']],
                'matched' => ['type' => 'integer', 'description' => 'The functions matching `filter`; all without it.'],
                'functions' => ['type' => 'array', 'items' => ProfileSchema::row()],
                'has_more' => ['type' => 'boolean', 'description' => 'More functions matched than `limit`.'],
            ],
            'required' => ['metric', 'unit', 'peak', 'sort', 'matched', 'functions', 'has_more'],
            'additionalProperties' => false,
        ];
    }

    #[\Override]
    public function annotations(): array
    {
        return ['readOnlyHint' => true, 'openWorldHint' => false];
    }

    #[\Override]
    public function call(array $arguments): array
    {
        $analysis = $this->analyses->get($arguments);
        $metric = $analysis->metric($arguments);
        $sort = Arguments::choice($arguments, 'sort', ['exclusive', 'inclusive']);
        $limit = Arguments::integer($arguments, 'limit', 1, self::MAX_LIMIT) ?? self::LIMIT;
        /** @var mixed $filter */
        $filter = $arguments['filter'] ?? null;
        $filter === null || \is_string($filter) && $filter !== ''
            or throw new \InvalidArgumentException('`filter` must be a non-empty string.');
        $pattern = $filter === null ? null : Wildcard::compile(\str_contains($filter, '*') ? $filter : "*$filter*");

        $rows = [];
        $matched = 0;
        foreach ($analysis->ranked($metric, $sort === 'inclusive') as $func) {
            if ($pattern !== null && !$pattern->matches($func->function)) {
                continue;
            }

            ++$matched <= $limit and $rows[] = $analysis->row($func, $metric);
        }

        return [
            'metric' => $metric->value,
            'unit' => $analysis->unit($metric),
            'peak' => $analysis->peak($metric),
            'sort' => $sort,
            'matched' => $matched,
            'functions' => $rows,
            'has_more' => $matched > $limit,
        ];
    }
}
