<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Sentry\Mcp;

use Buggregator\Trap\Module\Mcp\Tool;
use Buggregator\Trap\Module\Mcp\Tool\Arguments;
use Buggregator\Trap\Module\Mcp\Tool\Time;
use Buggregator\Trap\Module\Sentry\Frontend\Api\ErrorEvent;
use Buggregator\Trap\Module\Sentry\Frontend\Api\Scope;
use Buggregator\Trap\Module\Sentry\Queue\Job;
use Buggregator\Trap\Module\Sentry\Queue\QueueJobs;
use Buggregator\Trap\Server\Event\Event;
use Buggregator\Trap\Server\Event\EventStore;
use Buggregator\Trap\Server\Telemetry\TelemetryStore;

/**
 * The {@see QueueJobs} of the Queues tab: the attempts of a message grouped into its job, which `list_traces`
 * shows only as unrelated traces.
 *
 * @internal
 */
final class ListQueueJobs implements Tool
{
    public const DEFAULT_LIMIT = 20;
    public const MAX_LIMIT = 100;

    /** Attempts listed per job, the latest. */
    public const MAX_ATTEMPTS = 10;

    public function __construct(
        private readonly TelemetryStore $store,
        private readonly EventStore $events,
    ) {}

    #[\Override]
    public function name(): string
    {
        return 'list_queue_jobs';
    }

    #[\Override]
    public function title(): string
    {
        return 'List queue jobs';
    }

    #[\Override]
    public function description(): string
    {
        return 'Lists the jobs queue workers ran, from Sentry `queue.process` transactions and spans (sentry-laravel, '
            . 'sentry-python Celery) and OpenTelemetry messaging spans that process a message, the latest '
            . 'finished first. A job is the attempts of one `messaging.message.id` in one project and consumer; '
            . 'each has its status, attempts, failures, the time the message waited in the queue (`latency_ms`) and the duration of its '
            . 'last attempt, the transaction that queued it, and its latest attempts with their trace IDs for '
            . '`get_trace` and their errors with IDs for `get_event`. A job fails when its last attempt fails: '
            . 'whether the worker retries it is not sent. Narrow by `project`, `queue`, `job`, `status`, the end '
            . 'of the last attempt (`from`, `to`) or one job `id`.';
    }

    #[\Override]
    public function inputSchema(): array
    {
        $string = ['type' => 'string'];

        return [
            'type' => 'object',
            'properties' => [
                'project' => $string + [
                    'description' => \sprintf(
                        'Only the attempts of this project; data sent without one belongs to `%s`.',
                        Event::DEFAULT_PROJECT,
                    ),
                ],
                'id' => $string + [
                    'description' => 'Only the job of this `id` of a previous call.',
                ],
                'queue' => $string + ['description' => 'Only the jobs whose last attempt took the message from this queue.'],
                'job' => $string + ['description' => 'Only the jobs of this name: the class of a Laravel job.'],
                'status' => [
                    'type' => 'string',
                    'enum' => ['ok', 'failed', 'retried'],
                    'description' => '`ok` or `failed` by the last attempt; `retried` for jobs run more than once.',
                ],
                'from' => Time::schema('Only the jobs whose last attempt ended at or after this time.'),
                'to' => Time::schema('Only the jobs whose last attempt ended at or before this time.'),
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => self::MAX_LIMIT,
                    'default' => self::DEFAULT_LIMIT,
                    'description' => 'The most jobs to return.',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    #[\Override]
    public function outputSchema(): ?array
    {
        $string = ['type' => 'string'];
        $nullableString = ['type' => ['string', 'null']];
        $ms = ['type' => ['integer', 'null'], 'minimum' => 0];
        $count = ['type' => 'integer', 'minimum' => 0];

        return [
            'type' => 'object',
            'properties' => [
                'jobs' => [
                    'type' => 'array',
                    'description' => 'The latest finished first.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => $string + [
                                'description' => 'The project, the consumer when known and the message ID, '
                                    . 'URL-encoded and joined by `/`; the attempt ID without a message ID.',
                            ],
                            'message_id' => $nullableString,
                            'job' => $string + ['description' => 'The job name; empty when no attempt has one.'],
                            'queue' => $string,
                            'connection' => $string + ['description' => 'The Laravel queue connection; empty for others.'],
                            'system' => $string + ['description' => '`messaging.system`: `laravel`, `celery`, `kafka`.'],
                            'project' => $string,
                            'status' => $string + ['description' => 'Of the last attempt: `ok` or a failure status.'],
                            'failed' => ['type' => 'boolean'],
                            'attempts' => $count + [
                                'description' => 'Runs of the message: the stored attempts, or more when an attempt '
                                    . 'reports retries Trap did not get.',
                            ],
                            'failed_attempts' => $count + ['description' => 'Of the stored attempts.'],
                            'duration_ms' => $ms + ['description' => 'Of the last attempt.'],
                            'latency_ms' => $ms + [
                                'description' => 'How long the message waited in the queue before the last attempt.',
                            ],
                            'started' => $nullableString + ['description' => 'The start of the first attempt, ISO 8601.'],
                            'finished' => $nullableString + ['description' => 'The end of the last attempt, ISO 8601.'],
                            'dispatched_by' => [
                                'type' => ['object', 'null'],
                                'description' => 'The transaction that queued the job, when Trap has it.',
                                'properties' => ['trace_id' => $string, 'name' => $string],
                                'required' => ['trace_id', 'name'],
                                'additionalProperties' => false,
                            ],
                            'attempt_list' => [
                                'type' => 'array',
                                'description' => \sprintf('The latest %d stored attempts, oldest first.', self::MAX_ATTEMPTS),
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'attempt' => ['type' => 'integer', 'minimum' => 1],
                                        'status' => $string,
                                        'failed' => ['type' => 'boolean'],
                                        'duration_ms' => $ms,
                                        'latency_ms' => $ms,
                                        'started' => $nullableString,
                                        'trace_id' => $string,
                                        'errors' => [
                                            'type' => 'array',
                                            'items' => [
                                                'type' => 'object',
                                                'properties' => [
                                                    'uuid' => $string + ['description' => 'The ID for `get_event`.'],
                                                    'type' => $string,
                                                    'value' => $string,
                                                ],
                                                'required' => ['uuid', 'type', 'value'],
                                                'additionalProperties' => false,
                                            ],
                                        ],
                                    ],
                                    'required' => [
                                        'attempt', 'status', 'failed', 'duration_ms', 'latency_ms', 'started', 'trace_id',
                                        'errors',
                                    ],
                                    'additionalProperties' => false,
                                ],
                            ],
                        ],
                        'required' => [
                            'id', 'message_id', 'job', 'queue', 'connection', 'system', 'project', 'status', 'failed',
                            'attempts', 'failed_attempts', 'duration_ms', 'latency_ms', 'started', 'finished',
                            'dispatched_by', 'attempt_list',
                        ],
                        'additionalProperties' => false,
                    ],
                ],
                'total' => $count + ['description' => 'All the matching jobs.'],
            ],
            'required' => ['jobs', 'total'],
            'additionalProperties' => false,
        ];
    }

    #[\Override]
    public function annotations(): array
    {
        return ['readOnlyHint' => true, 'openWorldHint' => false];
    }

    /**
     * @return array{jobs: list<array<non-empty-string, mixed>>, total: int<0, max>}
     */
    #[\Override]
    public function call(array $arguments): array
    {
        $project = \trim(self::string($arguments, 'project'));
        $id = \trim(self::string($arguments, 'id'));
        /** @var ''|'ok'|'failed'|'retried' $status */
        $status = isset($arguments['status']) ? Arguments::choice($arguments, 'status', ['ok', 'failed', 'retried']) : '';
        $limit = Arguments::integer($arguments, 'limit', 1, self::MAX_LIMIT) ?? self::DEFAULT_LIMIT;
        $range = Time::range($arguments, \microtime(true));

        $queueJobs = QueueJobs::read($this->store, $project === '' ? null : $project);
        $jobs = \array_values(\array_filter(
            $queueJobs->list(
                new Scope(
                    $project === '' ? null : $project,
                    $range->from === null ? null : (float) $range->from / 1e6,
                    $range->to === null ? null : (float) $range->to / 1e6,
                ),
                \trim(self::string($arguments, 'queue')),
                \trim(self::string($arguments, 'job')),
                $status,
            ),
            static fn(Job $job): bool => $id === '' || $job->id === $id,
        ));

        $page = \array_slice($jobs, 0, $limit);
        $errors = $queueJobs->errors($page, QueueJobs::errorsByTrace($this->events));

        return [
            'jobs' => \array_map(
                static fn(Job $job): array => self::job($queueJobs, $job, $errors),
                $page,
            ),
            'total' => \count($jobs),
        ];
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

    private static function time(?float $time): ?string
    {
        return $time === null ? null : Time::format($time);
    }

    /**
     * @param array<array-key, list<ErrorEvent>> $errors By attempt ID.
     * @return array<non-empty-string, mixed>
     */
    private static function job(QueueJobs $queueJobs, Job $job, array $errors): array
    {
        $dispatcher = $queueJobs->dispatcher($job);
        $first = \max(0, \count($job->attempts) - self::MAX_ATTEMPTS);
        $attempts = [];
        foreach (\array_slice($job->attempts, $first) as $attempt) {
            $attempts[] = [
                'attempt' => $job->number($attempt),
                'status' => $attempt->status,
                'failed' => $attempt->failed(),
                'duration_ms' => $attempt->durationMs(),
                'latency_ms' => $attempt->latencyMs,
                'started' => self::time($attempt->start),
                'trace_id' => $attempt->traceId,
                'errors' => \array_map(static fn(ErrorEvent $error): array => [
                    'uuid' => $error->uuid,
                    'type' => $error->type,
                    'value' => $error->value,
                ], $errors[$attempt->id] ?? []),
            ];
        }

        return [
            'id' => $job->id,
            'message_id' => $job->messageId(),
            'job' => $job->name(),
            'queue' => $job->last->queue,
            'connection' => $job->last->connection,
            'system' => $job->last->system,
            'project' => $job->last->project,
            'status' => $job->last->status,
            'failed' => $job->failed(),
            'attempts' => $job->attemptCount(),
            'failed_attempts' => $job->failedAttempts(),
            'duration_ms' => $job->last->durationMs(),
            'latency_ms' => $job->last->latencyMs,
            'started' => self::time($job->startedAt()),
            'finished' => self::time($job->finishedAt()),
            'dispatched_by' => $dispatcher === null ? null : [
                'trace_id' => $dispatcher->traceId,
                'name' => $dispatcher->name,
            ],
            'attempt_list' => $attempts,
        ];
    }
}
