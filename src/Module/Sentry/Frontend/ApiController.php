<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Sentry\Frontend;

use Buggregator\Trap\Module\Frontend\Message\Error;
use Buggregator\Trap\Module\Sentry\Frontend\Api\ErrorEvent;
use Buggregator\Trap\Module\Sentry\Frontend\Api\Json;
use Buggregator\Trap\Module\Sentry\Frontend\Api\Scope;
use Buggregator\Trap\Module\Sentry\Frontend\Api\Sequence;
use Buggregator\Trap\Module\Sentry\Frontend\Api\ServiceMap;
use Buggregator\Trap\Module\Sentry\Frontend\Api\TraceView;
use Buggregator\Trap\Server\Event\EventStore;
use Buggregator\Trap\Server\Handler\Router\Attribute\AssertRouteFail as AssertFail;
use Buggregator\Trap\Server\Handler\Router\Attribute\AssertRouteSuccess as AssertSuccess;
use Buggregator\Trap\Server\Handler\Router\Attribute\QueryParam;
use Buggregator\Trap\Server\Handler\Router\Attribute\RegexpRoute;
use Buggregator\Trap\Server\Handler\Router\Attribute\StaticRoute;
use Buggregator\Trap\Server\Handler\Router\Method;
use Buggregator\Trap\Server\Telemetry\Feedback;
use Buggregator\Trap\Server\Telemetry\Log;
use Buggregator\Trap\Server\Telemetry\Metric;
use Buggregator\Trap\Server\Telemetry\TelemetryStore;

/**
 * The `/api/sentry/*` endpoints of the Sentry pages of the UI, as buggregator/server answers them.
 *
 * Exceptions are the `sentry` events of the feed; traces, logs, metrics and user feedback come from the
 * {@see TelemetryStore}.
 * Lists take `project` and the period: `window` (`1h`, `7d`, `all`) or `from` and `to`.
 *
 * @internal
 */
final class ApiController
{
    private const SOURCES = ['', 'sentry', 'otel'];

    public function __construct(
        private readonly EventStore $events,
        private readonly TelemetryStore $telemetry,
    ) {}

    #[StaticRoute(Method::Get, 'api/sentry/counts')]
    #[AssertSuccess(Method::Get, 'api/sentry/counts?project=default&window=24h')]
    public function counts(
        #[QueryParam] ?string $project = null,
        #[QueryParam] ?string $window = null,
        #[QueryParam] ?string $from = null,
        #[QueryParam] ?string $to = null,
    ): array {
        $scope = Scope::fromQuery($project, $window, $from, $to);

        $traces = [];
        foreach ($this->telemetry->traces() as $id => $trace) {
            foreach ($trace->transactions as $transaction) {
                if ($scope->contains($transaction->project, $transaction->start)) {
                    $traces[$id] = true;
                    break;
                }
            }
        }

        return [
            'exceptions' => \count($this->scopedErrors($scope)),
            'traces' => \count($traces),
            'logs' => \count($this->scopedLogs($scope)),
            'metrics' => \count($this->scopedMetrics($scope)),
            'feedback' => \count($this->scopedFeedback($scope)),
        ];
    }

    /**
     * User feedback, the newest first.
     *
     * @param string|null $eventId Only the feedback on the error of this Sentry event ID, with or without dashes.
     */
    #[StaticRoute(Method::Get, 'api/sentry/feedback')]
    #[AssertSuccess(Method::Get, 'api/sentry/feedback?event_id=5a9d&limit=50'),
        AssertFail(Method::Post, 'api/sentry/feedback'),]
    public function feedback(
        #[QueryParam] ?string $project = null,
        #[QueryParam] ?string $window = null,
        #[QueryParam] ?string $from = null,
        #[QueryParam] ?string $to = null,
        #[QueryParam('event_id')] ?string $eventId = null,
        #[QueryParam] int $page = 1,
        #[QueryParam] int $limit = 100,
    ): array {
        $eventId = Feedback::normalizeEventId((string) $eventId);
        $items = \array_values(\array_reverse(\array_filter(
            $this->scopedFeedback(Scope::fromQuery($project, $window, $from, $to)),
            static fn(Feedback $feedback): bool => $eventId === '' || $feedback->isAbout($eventId),
        )));

        $result = Json::page($items, Json::pageNumber($page), Json::limit($limit, 100));
        $result['data'] = \array_map(static fn(Feedback $feedback): array => [
            'id' => $feedback->id,
            'project' => $feedback->project,
            'event_id' => $feedback->eventId,
            'feedback_id' => $feedback->feedbackId,
            'name' => $feedback->name,
            'email' => $feedback->email,
            'message' => $feedback->message,
            'url' => $feedback->url,
            'source' => $feedback->source,
            'received_at' => Json::time($feedback->time),
            'payload' => $feedback->payload === [] ? null : $feedback->payload,
        ], $result['data']);

        return $result;
    }

    /**
     * @param string|null $grouped `true` for one row per fingerprint, most frequent first; newest events otherwise.
     * @param string|null $handled `true` or `false`; events without the mechanism flag match neither.
     */
    #[StaticRoute(Method::Get, 'api/sentry/exceptions')]
    #[AssertSuccess(Method::Get, 'api/sentry/exceptions?grouped=true&page=1')]
    public function exceptions(
        #[QueryParam] ?string $project = null,
        #[QueryParam] ?string $window = null,
        #[QueryParam] ?string $from = null,
        #[QueryParam] ?string $to = null,
        #[QueryParam] ?string $grouped = null,
        #[QueryParam] ?string $level = null,
        #[QueryParam] ?string $handled = null,
        #[QueryParam] int $page = 1,
        #[QueryParam] int $limit = 50,
    ): array {
        $errors = \array_filter(
            $this->scopedErrors(Scope::fromQuery($project, $window, $from, $to)),
            static fn(ErrorEvent $error): bool => ($level === null || $level === '' || $error->level === $level)
                && ($handled === null || $handled === '' || $error->handled === ($handled === 'true')),
        );
        // Newest first
        \usort($errors, static fn(ErrorEvent $a, ErrorEvent $b): int => $b->time <=> $a->time);

        $page = Json::pageNumber($page);
        $limit = Json::limit($limit, 50);
        if ($grouped !== 'true') {
            $counts = [];
            foreach ($errors as $error) {
                $counts[$error->fingerprint] = ($counts[$error->fingerprint] ?? 0) + 1;
            }

            return Json::page(\array_map(static fn(ErrorEvent $error): array => [
                'id' => $error->uuid,
                'event_id' => $error->eventId,
                'fingerprint' => $error->fingerprint,
                'occurrence_count' => $counts[$error->fingerprint],
                'exception_type' => $error->type,
                'exception_value' => $error->value,
                'level' => $error->level,
                'handled' => $error->handled,
                'transaction' => $error->transaction,
                'received_at' => Json::time($error->time),
                'trace_id' => $error->traceId,
            ], $errors), $page, $limit);
        }

        /**
         * @var array<non-empty-string, array{
         *     fingerprint: string,
         *     exception_type: string,
         *     exception_value: string,
         *     count: int<1, max>,
         *     first_seen: float,
         *     last_seen: float,
         *     level: string,
         *     handled: bool|null,
         *     sample_event_id: string
         * }> $groups
         */
        $groups = [];
        foreach ($errors as $error) {
            $group = $groups[$error->fingerprint] ?? null;
            if ($group === null) {
                // The newest event of the group is its sample.
                $groups[$error->fingerprint] = [
                    'fingerprint' => $error->fingerprint,
                    'exception_type' => $error->type,
                    'exception_value' => $error->value,
                    'count' => 1,
                    'first_seen' => $error->time,
                    'last_seen' => $error->time,
                    'level' => $error->level,
                    'handled' => $error->handled,
                    'sample_event_id' => $error->uuid,
                ];
                continue;
            }

            ++$group['count'];
            $group['first_seen'] = $error->time;
            // Unhandled wins: one crash makes the group unhandled.
            $error->handled === null or $group['handled'] = $group['handled'] === null
                ? $error->handled
                : $group['handled'] && $error->handled;
            $groups[$error->fingerprint] = $group;
        }

        $groups = \array_values($groups);
        \usort($groups, static fn(array $a, array $b): int => [$b['count'], $b['last_seen']] <=> [$a['count'], $a['last_seen']]);

        return Json::page(\array_map(static function (array $group): array {
            $group['first_seen'] = Json::time($group['first_seen']);
            $group['last_seen'] = Json::time($group['last_seen']);
            return $group;
        }, $groups), $page, $limit);
    }

    /**
     * One row per trace, the newest first: its root transaction, the one without a parent span, else
     * the earliest. Traces of spans only, without any transaction, are not listed.
     *
     * @param string|null $source `sentry` or `otel`: traces with any part from it.
     * @param string|null $service Traces any part of which comes from the service.
     */
    #[StaticRoute(Method::Get, 'api/sentry/traces')]
    #[AssertSuccess(Method::Get, 'api/sentry/traces?source=otel&page=2')]
    public function traces(
        #[QueryParam] ?string $project = null,
        #[QueryParam] ?string $window = null,
        #[QueryParam] ?string $from = null,
        #[QueryParam] ?string $to = null,
        #[QueryParam] ?string $source = null,
        #[QueryParam] ?string $service = null,
        #[QueryParam] int $page = 1,
        #[QueryParam] int $limit = 50,
    ): array|Error {
        if (!\in_array($source ?? '', self::SOURCES, true)) {
            return new Error('source must be sentry or otel', 400);
        }

        $scope = Scope::fromQuery($project, $window, $from, $to);
        $service = \trim((string) $service);
        $rows = [];
        foreach ($this->telemetry->traces() as $trace) {
            $root = $trace->root($scope->project);
            if ($root === null || !$scope->contains($root->project, $root->start)) {
                continue;
            }

            $view = new TraceView($trace, $scope->project);
            if ($source !== null && $source !== '' || $service !== '') {
                [$sources, $services] = $view->sourcesAndServices();
                if (($source !== null && $source !== '' && !\in_array($source, $sources, true))
                    || ($service !== '' && !\in_array($service, $services, true))
                ) {
                    continue;
                }
            }

            $rows[] = [$root, $view];
        }

        \usort($rows, static fn(array $a, array $b): int => ($b[0]->start ?? 0) <=> ($a[0]->start ?? 0));
        $page = Json::pageNumber($page);
        $limit = Json::limit($limit, 50);
        $result = Json::page($rows, $page, $limit);
        $result['data'] = \array_map(
            static fn(array $row): array => $row[1]->row($row[0]),
            $result['data'],
        );

        return $result;
    }

    /**
     * Answers 404 for a trace without a transaction: the UI probes traces of error events by ID.
     */
    #[RegexpRoute(Method::Get, '#^api/sentry/traces/(?<traceId>[\w-]++)$#')]
    #[AssertSuccess(Method::Get, 'api/sentry/traces/5b8efff798038103d269b633813fc60c', [
        'traceId' => '5b8efff798038103d269b633813fc60c',
        'project' => null,
    ]),
        AssertFail(Method::Get, 'api/sentry/traces/'),]
    public function trace(string $traceId, #[QueryParam] ?string $project = null): array|Error
    {
        $project = \trim((string) $project);
        $project = $project === '' ? null : $project;
        $trace = $this->telemetry->trace($traceId);
        $root = $trace?->root($project);
        if ($trace === null || $root === null) {
            return Error::notFound('trace not found');
        }

        $result = (new TraceView($trace, $project))->detail($root);

        $related = [];
        foreach ($this->scopedErrors(new Scope($project)) as $error) {
            $error->traceId === $traceId and $related[] = $error;
        }
        \usort($related, static fn(ErrorEvent $a, ErrorEvent $b): int => $b->time <=> $a->time);
        $result['related_errors'] = \array_map(static fn(ErrorEvent $error): array => [
            'id' => $error->uuid,
            'exception_type' => $error->type,
            'received_at' => Json::time($error->time),
        ], $related);

        $logs = 0;
        foreach ($this->telemetry->logs() as $log) {
            $log->traceId === $traceId && ($project === null || $log->project === $project) and ++$logs;
        }
        $result['related_log_count'] = $logs;

        return $result;
    }

    /**
     * @param string|null $level One level or several, comma-separated: `warn,warning`.
     * @param string|null $source `sentry` or `otel`.
     */
    #[StaticRoute(Method::Get, 'api/sentry/logs')]
    #[AssertSuccess(Method::Get, 'api/sentry/logs?level=warn,error&trace_id=abc')]
    public function logs(
        #[QueryParam] ?string $project = null,
        #[QueryParam] ?string $window = null,
        #[QueryParam] ?string $from = null,
        #[QueryParam] ?string $to = null,
        #[QueryParam] ?string $level = null,
        #[QueryParam('trace_id')] ?string $traceId = null,
        #[QueryParam] ?string $source = null,
        #[QueryParam] int $page = 1,
        #[QueryParam] int $limit = 100,
    ): array|Error {
        if (!\in_array($source ?? '', self::SOURCES, true)) {
            return new Error('source must be sentry or otel', 400);
        }

        $levels = \array_filter(\array_map('trim', \explode(',', (string) $level)), static fn(string $l): bool => $l !== '');
        $logs = \array_filter(
            $this->scopedLogs(Scope::fromQuery($project, $window, $from, $to)),
            static fn(Log $log): bool => ($levels === [] || \in_array($log->level, $levels, true))
                && ($traceId === null || $traceId === '' || $log->traceId === $traceId)
                && ($source === null || $source === '' || $log->source->value === $source),
        );
        $logs = self::newestFirst($logs);

        $result = Json::page($logs, Json::pageNumber($page), Json::limit($limit, 100));
        $result['data'] = \array_map(fn(Log $log): array => [
            'id' => $log->id,
            'level' => $log->level,
            'severity_number' => $log->severityNumber,
            'body' => $log->body,
            'trace_id' => $log->traceId,
            'span_id' => $log->spanId,
            'log_ts' => Json::time($log->time),
            'attributes' => Json::map($log->attributes),
            'trace_exists' => $log->traceId !== null && $this->telemetry->trace($log->traceId) !== null,
            'source' => $log->source->value,
            'service_name' => $log->service,
        ], $result['data']);

        return $result;
    }

    /**
     * @param string|null $search A part of the name, case-insensitive.
     * @param string|null $type `counter`, `gauge` or `distribution`.
     */
    #[StaticRoute(Method::Get, 'api/sentry/metrics')]
    #[AssertSuccess(Method::Get, 'api/sentry/metrics?type=counter&search=http')]
    public function metrics(
        #[QueryParam] ?string $project = null,
        #[QueryParam] ?string $window = null,
        #[QueryParam] ?string $from = null,
        #[QueryParam] ?string $to = null,
        #[QueryParam] ?string $name = null,
        #[QueryParam] ?string $search = null,
        #[QueryParam] ?string $type = null,
        #[QueryParam('trace_id')] ?string $traceId = null,
        #[QueryParam] int $page = 1,
        #[QueryParam] int $limit = 100,
    ): array {
        $metrics = \array_filter(
            $this->scopedMetrics(Scope::fromQuery($project, $window, $from, $to)),
            static fn(Metric $metric): bool => ($name === null || $name === '' || $metric->name === $name)
                && ($type === null || $type === '' || $metric->type === $type)
                && ($traceId === null || $traceId === '' || $metric->traceId === $traceId)
                && ($search === null || $search === '' || \stripos($metric->name, $search) !== false),
        );
        $metrics = self::newestFirst($metrics);

        $result = Json::page($metrics, Json::pageNumber($page), Json::limit($limit, 100));
        $result['data'] = \array_map(static fn(Metric $metric): array => [
            'id' => $metric->id,
            'name' => $metric->name,
            'type' => $metric->type,
            'value' => $metric->value,
            'unit' => $metric->unit,
            'trace_id' => $metric->traceId,
            'span_id' => $metric->spanId,
            'metric_ts' => Json::time($metric->time),
            'attributes' => Json::map($metric->attributes),
            'source' => $metric->source->value,
            'service_name' => $metric->service,
        ], $result['data']);

        return $result;
    }

    /**
     * The calls of a trace in time order; 404 for an unknown trace.
     */
    #[RegexpRoute(Method::Get, '#^api/sentry/traces/(?<traceId>[\w-]++)/sequence$#')]
    #[AssertSuccess(Method::Get, 'api/sentry/traces/5b8efff798038103d269b633813fc60c/sequence', [
        'traceId' => '5b8efff798038103d269b633813fc60c',
        'project' => null,
    ])]
    public function sequence(string $traceId, #[QueryParam] ?string $project = null): array|Error
    {
        $project = \trim((string) $project);
        $trace = $this->telemetry->trace($traceId);
        $result = $trace === null ? null : Sequence::json($trace, $project === '' ? null : $project);

        return $result ?? Error::notFound('trace not found');
    }

    /**
     * Services and the calls between them over a period: the last hour by default.
     *
     * @param string|null $window `15m`, `24h`, `all`, or a number of minutes.
     */
    #[StaticRoute(Method::Get, 'api/sentry/service-map')]
    #[AssertSuccess(Method::Get, 'api/sentry/service-map?project=default&window=1h')]
    public function serviceMap(
        #[QueryParam] ?string $project = null,
        #[QueryParam] ?string $window = null,
        #[QueryParam] ?string $from = null,
        #[QueryParam] ?string $to = null,
    ): array {
        $now = \microtime(true);
        return ServiceMap::read($this->telemetry, self::project($project), $window, $from, $to, $now)->json($now);
    }

    /**
     * The traces through one edge of the service map, errors first, then the newest.
     *
     */
    #[StaticRoute(Method::Get, 'api/sentry/service-map/edge')]
    #[AssertSuccess(Method::Get, 'api/sentry/service-map/edge?source=a&target=b&limit=10&offset=20')]
    public function serviceMapEdge(
        #[QueryParam] ?string $source = null,
        #[QueryParam] ?string $target = null,
        #[QueryParam('op_type')] ?string $opType = null,
        #[QueryParam] ?string $project = null,
        #[QueryParam] ?string $window = null,
        #[QueryParam] ?string $from = null,
        #[QueryParam] ?string $to = null,
        #[QueryParam] int $limit = 50,
        #[QueryParam] int $offset = 0,
    ): array|Error {
        if ($source === null || $source === '' || $target === null || $target === '') {
            return new Error('source and target are required', 400);
        }

        $now = \microtime(true);
        $traces = ServiceMap::read($this->telemetry, self::project($project), $window, $from, $to, $now)
            ->edge($source, $target, $opType);

        return [
            'total' => \count($traces),
            'traces' => \array_slice($traces, \max(0, $offset), Json::limit($limit, 50)),
        ];
    }

    /**
     * @template T of Log|Metric
     * @param array<array-key, T> $items From the oldest received.
     * @return list<T> By their time, the newest first; the last received first among equal times.
     */
    private static function newestFirst(array $items): array
    {
        $items = \array_reverse(\array_values($items));
        // usort is stable: equal times keep the reversed order of arrival.
        \usort($items, static fn(Log|Metric $a, Log|Metric $b): int => ($b->time ?? 0) <=> ($a->time ?? 0));

        return $items;
    }

    /**
     * @return non-empty-string|null Null for any project.
     */
    private static function project(?string $project): ?string
    {
        $project = \trim((string) $project);
        return $project === '' ? null : $project;
    }

    /**
     * @return list<ErrorEvent>
     */
    private function scopedErrors(Scope $scope): array
    {
        $result = [];
        foreach ($this->events as $event) {
            $error = ErrorEvent::fromEvent($event);
            $error !== null && $scope->contains($error->project, $error->time) and $result[] = $error;
        }

        return $result;
    }

    /**
     * @return list<Log>
     */
    private function scopedLogs(Scope $scope): array
    {
        return \array_values(\array_filter(
            $this->telemetry->logs(),
            static fn(Log $log): bool => $scope->contains($log->project, $log->time),
        ));
    }

    /**
     * @return list<Feedback> From the oldest.
     */
    private function scopedFeedback(Scope $scope): array
    {
        return \array_values(\array_filter(
            $this->telemetry->feedback(),
            static fn(Feedback $feedback): bool => $scope->contains($feedback->project, $feedback->time),
        ));
    }

    /**
     * @return list<Metric>
     */
    private function scopedMetrics(Scope $scope): array
    {
        return \array_values(\array_filter(
            $this->telemetry->metrics(),
            static fn(Metric $metric): bool => $scope->contains($metric->project, $metric->time),
        ));
    }
}
