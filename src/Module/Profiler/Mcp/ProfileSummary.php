<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler\Mcp;

use Buggregator\Trap\Module\Mcp\Tool;
use Buggregator\Trap\Module\Profiler\Compaction\Report;
use Buggregator\Trap\Module\Profiler\Struct\Metric;

/**
 * The overview of a profile: its metrics, the heaviest functions and the hot path, as the console shows them.
 *
 * @internal
 */
final class ProfileSummary implements Tool
{
    private const TOP_LIMIT = 10;

    /** Longer hot paths keep their start and their end: the end is where the time goes. */
    private const PATH_LIMIT = 30;

    private const PATH_HEAD = 5;

    public function __construct(
        private readonly ProfileAnalyses $analyses,
    ) {}

    #[\Override]
    public function name(): string
    {
        return 'profile_summary';
    }

    #[\Override]
    public function title(): string
    {
        return 'Profile summary';
    }

    #[\Override]
    public function description(): string
    {
        return 'Starts the analysis of a performance profile (a `profiler` event): its format, the metrics it '
            . 'measures with their peak values, the number of functions and calls, the 10 functions with the '
            . 'highest exclusive cost and the hot path, the heaviest chain of calls from the root. Ranks by '
            . 'wall time, else by the first metric the profile has. Go on with `profile_top` and `profile_calls`.';
    }

    #[\Override]
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['id' => ProfileAnalyses::idSchema()],
            'required' => ['id'],
            'additionalProperties' => false,
        ];
    }

    #[\Override]
    public function outputSchema(): ?array
    {
        $metrics = \array_map(static fn(Metric $metric): string => $metric->value, Metric::cases());
        $values = [
            'type' => 'object',
            'properties' => \array_fill_keys($metrics, ['type' => 'integer']),
            'additionalProperties' => false,
        ];

        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'string'],
                'name' => ['type' => 'string', 'description' => 'The application name, else the file name; may be empty.'],
                'format' => ['type' => 'string', 'enum' => ['XHProf', 'XDebug', 'SPX', 'Speedscope', 'Pprof']],
                'date' => ['type' => 'string', 'description' => 'When the profile was taken, ISO 8601.'],
                'hostname' => ['type' => 'string'],
                'tags' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
                'details' => [
                    'type' => 'object',
                    'description' => 'What the profiler tells besides the costs, such as the request of an SPX report.',
                    'additionalProperties' => ['type' => 'string'],
                ],
                'compacted' => [
                    'type' => 'string',
                    'description' => 'How a profile too large for the memory was compacted.',
                ],
                'count_type' => [
                    'type' => 'string',
                    'description' => 'What `ct` counts: `calls`, `samples`, `sampled calls`, or a pprof sample type.',
                ],
                'metrics' => [
                    'type' => 'array',
                    'items' => ['type' => 'string', 'enum' => $metrics],
                    'description' => 'The metrics the profile measures. ' . ProfileSchema::METRICS,
                ],
                'not_measured' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => $metrics]],
                'peaks' => [
                    ...$values,
                    'description' => 'The largest value of each measured metric of one caller and callee pair, as '
                        . 'the console shows: the root\'s for the times and memory; for `ct`, the most calls or '
                        . 'samples of one pair. The percentages are of them; none for `ct`.',
                ],
                'functions' => ['type' => 'integer', 'description' => 'Distinct functions.'],
                'edges' => ['type' => 'integer', 'description' => 'Distinct caller-callee pairs.'],
                'metric' => [
                    'type' => ['string', 'null'],
                    'description' => 'The metric of `top` and `hot_path`; null when the profile has no calls.',
                ],
                'unit' => ['type' => ['string', 'null']],
                'top' => ['type' => 'array', 'items' => ProfileSchema::row()],
                'hot_path' => [
                    'type' => 'array',
                    'description' => 'From the root down; a long path keeps its start and its end, see `depth`.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'depth' => ['type' => 'integer', 'description' => 'The root is 0.'],
                            'function' => ['type' => 'string'],
                            'calls' => ['type' => 'integer'],
                            'value' => ['type' => 'integer', 'description' => 'The metric of these calls.'],
                            'percent' => ProfileSchema::percent(),
                        ],
                        'required' => ['depth', 'function', 'calls', 'value', 'percent'],
                        'additionalProperties' => false,
                    ],
                ],
                'hot_path_length' => ['type' => 'integer'],
            ],
            'required' => [
                'id',
                'name',
                'format',
                'date',
                'count_type',
                'metrics',
                'not_measured',
                'peaks',
                'functions',
                'edges',
                'metric',
                'unit',
                'top',
                'hot_path',
                'hot_path_length',
            ],
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
        $profile = $analysis->profile;
        $metadata = $profile->metadata;

        $peaks = [];
        foreach ($analysis->metrics as $metric) {
            $peaks[$metric->value] = $analysis->peak($metric);
        }

        $result = [
            'id' => $analysis->id,
            'name' => ProfileView::name($analysis->payload),
            'format' => $analysis->payload->type->value,
            'date' => $profile->date->format(\DATE_ATOM),
        ];
        \is_string($host = $metadata['hostname'] ?? null) && $host !== '' and $result['hostname'] = $host;
        $profile->tags === [] or $result['tags'] = $profile->tags;
        /** @var mixed $raw */
        $raw = $metadata['details'] ?? null;
        $details = [];
        /** @var mixed $value */
        foreach (\is_array($raw) ? $raw : [] as $label => $value) {
            \is_string($label) && \is_scalar($value) and $details[$label] = (string) $value;
        }
        $details === [] or $result['details'] = $details;
        /** @var mixed $raw */
        $raw = $metadata['compacted'] ?? null;
        \is_array($raw) && ($compacted = Report::summarize($raw)) !== '' and $result['compacted'] = $compacted;

        $metric = Metric::rank($profile->peaks);

        return $result + [
            'count_type' => $analysis->countType,
            'metrics' => $analysis->metricNames(),
            'not_measured' => \array_values(\array_diff(
                \array_map(static fn(Metric $metric): string => $metric->value, Metric::cases()),
                $analysis->metricNames(),
            )),
            'peaks' => $peaks === [] ? new \stdClass() : $peaks,
            'functions' => \count($analysis->functions()),
            'edges' => $profile->calls->count(),
            'metric' => $metric?->value,
            'unit' => $metric === null ? null : $analysis->unit($metric),
        ] + ($metric === null ? ['top' => [], 'hot_path' => [], 'hot_path_length' => 0] : self::ranking($analysis, $metric));
    }

    /**
     * @return array{top: list<array<non-empty-string, mixed>>, hot_path: list<array<non-empty-string, mixed>>, hot_path_length: int}
     */
    private static function ranking(ProfileAnalysis $analysis, Metric $metric): array
    {
        $top = \array_map(
            static fn($func): array => $analysis->row($func, $metric),
            \array_slice($analysis->ranked($metric), 0, self::TOP_LIMIT),
        );

        $path = [];
        foreach ($analysis->profile->calls->hotPath($metric) as $depth => $edge) {
            $value = $metric->of($edge->cost);
            $path[] = [
                'depth' => $depth,
                'function' => $edge->callee,
                'calls' => $edge->cost->ct,
                'value' => $value,
                'percent' => $analysis->percent($metric, $value),
            ];
        }
        $length = \count($path);
        $length > self::PATH_LIMIT and $path = [
            ...\array_slice($path, 0, self::PATH_HEAD),
            ...\array_slice($path, -(self::PATH_LIMIT - self::PATH_HEAD)),
        ];

        return ['top' => $top, 'hot_path' => $path, 'hot_path_length' => $length];
    }
}
