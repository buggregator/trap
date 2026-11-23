<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Sentry\Mcp;

use Buggregator\Trap\Module\Mcp\Tool;
use Buggregator\Trap\Module\Mcp\Tool\Arguments;
use Buggregator\Trap\Module\Mcp\Tool\Time;
use Buggregator\Trap\Server\Event\Event;
use Buggregator\Trap\Server\Telemetry\Cron\CheckIn;
use Buggregator\Trap\Server\Telemetry\Cron\CheckInMessage;
use Buggregator\Trap\Server\Telemetry\Cron\Monitor;
use Buggregator\Trap\Server\Telemetry\TelemetryStore;

/**
 * Cron monitors, of Sentry Crons check-ins and ping URLs, with their newest runs: they make no event.
 *
 * @internal
 */
final class ListCrons implements Tool
{
    public const DEFAULT_CHECK_INS = 5;
    public const MAX_CHECK_INS = 100;

    /** Statuses of a last run that need a look */
    private const ATTENTION = [CheckInMessage::ERROR, CheckIn::TIMEOUT, CheckIn::MISSED];

    public function __construct(
        private readonly TelemetryStore $store,
    ) {}

    #[\Override]
    public function name(): string
    {
        return 'list_crons';
    }

    #[\Override]
    public function title(): string
    {
        return 'List cron monitors';
    }

    #[\Override]
    public function description(): string
    {
        return 'Lists the cron jobs that sent Sentry Crons check-ins (`check_in` envelope items, the cron HTTP '
            . 'endpoint) or pinged `/ping/{slug}`, by project and slug: schedule, time zone, margin and max runtime '
            . 'in minutes, the status of the last run, the next expected run, and the newest runs. Statuses: '
            . '`in_progress`, `ok`, `error` from the job; `timeout` (no finish within the max runtime), `missed` (no '
            . 'check-in within the margin of a slot) and `unknown` (the slot fell while Trap was not running) from '
            . 'Trap. Check-ins make no event, so `list_events` does not show them. `attention: true` keeps the jobs '
            . 'whose last run failed, timed out or was missed.';
    }

    #[\Override]
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'project' => [
                    'type' => 'string',
                    'description' => \sprintf(
                        'Only the jobs of this project; check-ins sent without one belong to `%s`.',
                        Event::DEFAULT_PROJECT,
                    ),
                ],
                'slug' => ['type' => 'string', 'description' => 'Only the job of this monitor slug.'],
                'attention' => [
                    'type' => 'boolean',
                    'default' => false,
                    'description' => 'Only the jobs whose last run failed, timed out or was missed.',
                ],
                'check_ins' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'maximum' => self::MAX_CHECK_INS,
                    'default' => self::DEFAULT_CHECK_INS,
                    'description' => 'The most runs to return of each job, the newest first.',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    #[\Override]
    public function outputSchema(): ?array
    {
        $string = ['type' => 'string'];
        $time = ['type' => ['string', 'null'], 'description' => 'ISO 8601.'];
        $minutes = ['type' => 'integer', 'minimum' => 1, 'description' => 'Minutes.'];

        return [
            'type' => 'object',
            'properties' => [
                'monitors' => [
                    'type' => 'array',
                    'description' => 'By project, then slug.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'project' => $string,
                            'slug' => $string,
                            'source' => ['type' => 'string', 'enum' => ['sentry', 'ping']],
                            'schedule' => [
                                'type' => ['string', 'null'],
                                'description' => '`crontab 0 3 * * *` or `every 10 minutes`; null without one: '
                                    . 'then no run is ever missed.',
                            ],
                            'timezone' => $string,
                            'checkin_margin' => $minutes,
                            'max_runtime' => $minutes,
                            'last_status' => ['type' => ['string', 'null'], 'description' => 'Null before the first run.'],
                            'last_check_in' => $time,
                            'next_expected' => $time,
                            'total_check_ins' => ['type' => 'integer', 'minimum' => 0],
                            'check_ins' => [
                                'type' => 'array',
                                'description' => 'The newest first.',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'id' => $string,
                                        'status' => $string,
                                        'started' => $string + ['description' => 'ISO 8601; the slot of a row of Trap.'],
                                        'finished' => $time,
                                        'duration_ms' => ['type' => ['integer', 'null']],
                                        'environment' => $string,
                                        'trace_id' => $string,
                                        'output' => $string + ['description' => 'The end of the body of a ping.'],
                                        'expected' => $time + [
                                            'description' => 'The slot of a `missed` or `unknown` row of Trap; '
                                                . 'null for a run.',
                                        ],
                                    ],
                                    'required' => [
                                        'id', 'status', 'started', 'finished', 'duration_ms', 'environment',
                                        'trace_id', 'output', 'expected',
                                    ],
                                    'additionalProperties' => false,
                                ],
                            ],
                        ],
                        'required' => [
                            'project', 'slug', 'source', 'schedule', 'timezone', 'checkin_margin', 'max_runtime',
                            'last_status', 'last_check_in', 'next_expected', 'total_check_ins', 'check_ins',
                        ],
                        'additionalProperties' => false,
                    ],
                ],
                'total' => ['type' => 'integer', 'minimum' => 0, 'description' => 'All the matching jobs.'],
            ],
            'required' => ['monitors', 'total'],
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
        $project = \trim(self::string($arguments, 'project'));
        $slug = \trim(self::string($arguments, 'slug'));
        $attention = Arguments::boolean($arguments, 'attention');
        $limit = Arguments::integer($arguments, 'check_ins', 0, self::MAX_CHECK_INS) ?? self::DEFAULT_CHECK_INS;

        $monitors = \array_values(\array_filter(
            $this->store->crons->monitors($project === '' ? null : $project),
            static fn(Monitor $monitor): bool => ($slug === '' || $monitor->slug === $slug)
                && (!$attention || \in_array($monitor->lastStatus(), self::ATTENTION, true)),
        ));

        return [
            'monitors' => \array_map(static fn(Monitor $monitor): array => self::monitor($monitor, $limit), $monitors),
            'total' => \count($monitors),
        ];
    }

    private static function monitor(Monitor $monitor, int $limit): array
    {
        $history = $monitor->history();

        return [
            'project' => $monitor->project,
            'slug' => $monitor->slug,
            'source' => $monitor->source,
            'schedule' => $monitor->schedule?->describe(),
            'timezone' => $monitor->timezone,
            'checkin_margin' => $monitor->margin,
            'max_runtime' => $monitor->maxRuntime,
            'last_status' => $history[0]->status ?? null,
            'last_check_in' => self::time($monitor->lastCheckInAt),
            'next_expected' => self::time($monitor->nextExpectedAt === null ? null : (float) $monitor->nextExpectedAt),
            'total_check_ins' => \count($history),
            'check_ins' => \array_map(static fn(CheckIn $run): array => [
                'id' => $run->id,
                'status' => $run->status,
                'started' => Time::format($run->startedAt),
                'finished' => self::time($run->finishedAt),
                'duration_ms' => $run->durationMs,
                'environment' => $run->environment,
                'trace_id' => $run->traceId,
                'output' => $run->output,
                'expected' => self::time($run->expectedAt),
            ], \array_slice($history, 0, $limit)),
        ];
    }

    private static function time(?float $time): ?string
    {
        return $time === null ? null : Time::format($time);
    }

    /**
     * @throws \InvalidArgumentException
     */
    private static function string(array $arguments, string $name): string
    {
        /** @var mixed $value */
        $value = $arguments[$name] ?? '';
        \is_string($value) or throw new \InvalidArgumentException("`$name` must be a string.");

        return $value;
    }
}
