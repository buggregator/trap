<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Sentry\Frontend;

use Buggregator\Trap\Module\Frontend\Message\Error;
use Buggregator\Trap\Module\Sentry\Frontend\Api\ErrorEvent;
use Buggregator\Trap\Module\Sentry\Frontend\Api\Json;
use Buggregator\Trap\Module\Sentry\Frontend\Api\Scope;
use Buggregator\Trap\Module\Sentry\Queue\Job;
use Buggregator\Trap\Module\Sentry\Queue\QueueJobs;
use Buggregator\Trap\Server\Event\EventStore;
use Buggregator\Trap\Server\Handler\Router\Attribute\AssertRouteFail as AssertFail;
use Buggregator\Trap\Server\Handler\Router\Attribute\AssertRouteSuccess as AssertSuccess;
use Buggregator\Trap\Server\Handler\Router\Attribute\QueryParam;
use Buggregator\Trap\Server\Handler\Router\Attribute\RegexpRoute;
use Buggregator\Trap\Server\Handler\Router\Attribute\StaticRoute;
use Buggregator\Trap\Server\Handler\Router\Method;
use Buggregator\Trap\Server\Telemetry\TelemetryStore;

/**
 * The `/api/sentry/queues/*` endpoints of the Queues tab: the {@see QueueJobs} of the telemetry store.
 *
 * The summary and the list take the same project, period and filters, so the numbers of one are those of the other.
 *
 * @internal
 */
final class QueueController
{
    private const STATUSES = ['', 'ok', 'failed', 'retried'];

    public function __construct(
        private readonly EventStore $events,
        private readonly TelemetryStore $telemetry,
    ) {}

    /**
     * Its `jobs` is the count of the Queues tab, which shows when it is above 0.
     */
    #[StaticRoute(Method::Get, 'api/sentry/queues/summary')]
    #[AssertSuccess(Method::Get, 'api/sentry/queues/summary?project=default&window=1h&queue=emails'),
        AssertFail(Method::Post, 'api/sentry/queues/summary'),]
    public function summary(
        #[QueryParam] ?string $project = null,
        #[QueryParam] ?string $window = null,
        #[QueryParam] ?string $from = null,
        #[QueryParam] ?string $to = null,
        #[QueryParam] ?string $queue = null,
        #[QueryParam] ?string $job = null,
        #[QueryParam] ?string $status = null,
    ): array|Error {
        $jobs = $this->jobs($project, $window, $from, $to, $queue, $job, $status);

        return $jobs instanceof Error ? $jobs : QueueJobs::summary($jobs);
    }

    /**
     * Jobs whose last attempt ended in the period, the latest first.
     *
     * @param string|null $job The job name: the class of a Laravel job.
     * @param string|null $status `ok` or `failed` by the last attempt, `retried` for jobs run more than once.
     */
    #[StaticRoute(Method::Get, 'api/sentry/queues/jobs')]
    #[AssertSuccess(Method::Get, 'api/sentry/queues/jobs?project=default&window=1h&status=failed&page=1&limit=50')]
    public function list(
        #[QueryParam] ?string $project = null,
        #[QueryParam] ?string $window = null,
        #[QueryParam] ?string $from = null,
        #[QueryParam] ?string $to = null,
        #[QueryParam] ?string $queue = null,
        #[QueryParam] ?string $job = null,
        #[QueryParam] ?string $status = null,
        #[QueryParam] int $page = 1,
        #[QueryParam] int $limit = 50,
    ): array|Error {
        $jobs = $this->jobs($project, $window, $from, $to, $queue, $job, $status);
        if ($jobs instanceof Error) {
            return $jobs;
        }

        $result = Json::page($jobs, Json::pageNumber($page), Json::limit($limit, 50));
        $result['data'] = \array_map(self::job(...), $result['data']);

        return $result;
    }

    /**
     * One job with its attempts, the errors of each and the transaction that queued it; 404 for an unknown one.
     * Of any project and period: the UI opens it by its ID alone.
     *
     * @param string $id The job ID of the list, URL-encoded once more in the path.
     * @param string|null $project Only the attempts of this project.
     */
    #[RegexpRoute(Method::Get, '#^api/sentry/queues/jobs/(?<id>[^/]++)$#')]
    #[AssertSuccess(Method::Get, 'api/sentry/queues/jobs/m%2F1', ['id' => 'm%2F1', 'project' => null]),
        AssertFail(Method::Get, 'api/sentry/queues/jobs/'),]
    public function detail(string $id, #[QueryParam] ?string $project = null): array|Error
    {
        $queueJobs = QueueJobs::read($this->telemetry, self::project($project));
        $job = $queueJobs->find(\rawurldecode($id));
        if ($job === null) {
            return Error::notFound('queue job not found');
        }

        $errors = $queueJobs->errors([$job], QueueJobs::errorsByTrace($this->events));
        $list = [];
        $previous = null;
        foreach ($job->attempts as $attempt) {
            $gap = $previous?->end === null || $attempt->start === null
                ? null
                : \max(0, (int) \round(($attempt->start - $previous->end) * 1000.0));
            $list[] = [
                'transaction_id' => $attempt->transactionId ?? $attempt->id,
                'trace_id' => $attempt->traceId,
                'attempt' => $job->number($attempt),
                'status' => $attempt->status,
                'failed' => $attempt->failed(),
                'duration_ms' => $attempt->durationMs(),
                'latency_ms' => $attempt->latencyMs,
                'started_at' => Json::time($attempt->start),
                'finished_at' => Json::time($attempt->end),
                'gap_ms' => $gap,
                'errors' => \array_map(static fn(ErrorEvent $error): array => [
                    'id' => $error->uuid,
                    'exception_type' => $error->type === '' ? null : $error->type,
                    'exception_value' => $error->value === '' ? null : $error->value,
                    'event_ts' => Json::time($error->time),
                ], $errors[$attempt->id] ?? []),
            ];
            $previous = $attempt;
        }

        $dispatcher = $queueJobs->dispatcher($job);

        return self::job($job) + [
            'attempt_list' => $list,
            'dispatched_by' => $dispatcher === null ? null : [
                'transaction_id' => $dispatcher->id,
                'trace_id' => $dispatcher->traceId,
                'transaction_name' => $dispatcher->name,
                'start_ts' => Json::time($dispatcher->start),
            ],
        ];
    }

    /**
     * @return array<non-empty-string, mixed>
     */
    private static function job(Job $job): array
    {
        $last = $job->last;

        return [
            'id' => $job->id,
            'message_id' => $job->messageId(),
            'job' => $job->name(),
            'queue' => $last->queue,
            'connection' => $last->connection,
            'system' => $last->system,
            'status' => $last->status,
            'failed' => $job->failed(),
            'attempts' => $job->attemptCount(),
            'failed_attempts' => $job->failedAttempts(),
            'retried' => $job->retried(),
            'duration_ms' => $last->durationMs(),
            'latency_ms' => $last->latencyMs,
            'started_at' => Json::time($job->startedAt()),
            'finished_at' => Json::time($job->finishedAt()),
            'trace_id' => $last->traceId,
            'transaction_id' => $last->transactionId ?? $last->id,
            'project' => $last->project,
        ];
    }

    /**
     * @return non-empty-string|null
     */
    private static function project(?string $project): ?string
    {
        $project = \trim((string) $project);
        return $project === '' ? null : $project;
    }

    /**
     * @return list<Job>|Error
     */
    private function jobs(
        ?string $project,
        ?string $window,
        ?string $from,
        ?string $to,
        ?string $queue,
        ?string $job,
        ?string $status,
    ): array|Error {
        $status = \trim((string) $status);
        if (!\in_array($status, self::STATUSES, true)) {
            return new Error('status must be ok, failed or retried', 400);
        }

        $scope = Scope::fromQuery($project, $window, $from, $to);

        return QueueJobs::read($this->telemetry, $scope->project)
            ->list($scope, \trim((string) $queue), \trim((string) $job), $status);
    }
}
