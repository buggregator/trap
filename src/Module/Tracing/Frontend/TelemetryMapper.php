<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Tracing\Frontend;

use Buggregator\Trap\Module\Tracing\Frame\OtlpLogs;
use Buggregator\Trap\Module\Tracing\Frame\OtlpMetrics;
use Buggregator\Trap\Module\Tracing\Frame\OtlpTraces;
use Buggregator\Trap\Module\Tracing\Frame\Trace\ResourceSpans;
use Buggregator\Trap\Module\Tracing\Frame\Trace\ScopeSpans;
use Buggregator\Trap\Module\Tracing\Frame\Trace\Span as OtlpSpan;
use Buggregator\Trap\Module\Tracing\Frame\Trace\SpanKind;
use Buggregator\Trap\Module\Tracing\Frame\Trace\StatusCode;
use Buggregator\Trap\Server\Event\Event;
use Buggregator\Trap\Server\Event\EventMapper;
use Buggregator\Trap\Server\Proto\Frame;
use Buggregator\Trap\Server\Telemetry\Batch;
use Buggregator\Trap\Server\Telemetry\Log;
use Buggregator\Trap\Server\Telemetry\Metric;
use Buggregator\Trap\Server\Telemetry\Peer;
use Buggregator\Trap\Server\Telemetry\Source;
use Buggregator\Trap\Server\Telemetry\Span;
use Buggregator\Trap\Server\Telemetry\TelemetryStore;
use Buggregator\Trap\Server\Telemetry\Transaction;
use Buggregator\Trap\Support\Uuid;

/**
 * Puts OTLP traces, logs and metrics into the {@see TelemetryStore}, as buggregator/server stores them:
 * OTLP makes no feed events.
 *
 * The entry span of each service becomes a transaction: a span without a parent, with a remote parent,
 * or a server or consumer span whose parent is not in the same resource of the request.
 * Span attributes keep the OTel parts the UI shows under `otel.*` keys.
 *
 * @internal
 */
final class TelemetryMapper implements EventMapper
{
    // OTLP SpanFlags: the parent context is known to be remote
    private const FLAG_HAS_IS_REMOTE = 0x100;
    private const FLAG_IS_REMOTE = 0x200;

    public function __construct(
        private readonly TelemetryStore $telemetry = new TelemetryStore(),
    ) {}

    /**
     * @return null Always: telemetry makes no events.
     */
    #[\Override]
    public function map(Frame $frame): ?Event
    {
        $project = match (true) {
            $frame instanceof OtlpTraces, $frame instanceof OtlpLogs, $frame instanceof OtlpMetrics => $frame->project,
            default => null,
        };
        $batch = new Batch($project === null || $project === '' ? Event::DEFAULT_PROJECT : $project);

        match (true) {
            $frame instanceof OtlpTraces => self::traces($batch, $frame),
            $frame instanceof OtlpLogs => self::logs($batch, $frame),
            $frame instanceof OtlpMetrics => self::metrics($batch, $frame),
            default => null,
        };

        $this->telemetry->add($batch);

        return null;
    }

    private static function traces(Batch $batch, OtlpTraces $frame): void
    {
        foreach ($frame->resourceSpans as $resource) {
            $local = [];
            foreach ($resource->scopeSpans as $scope) {
                foreach ($scope->spans as $span) {
                    $local[$span->spanId] = true;
                }
            }

            foreach ($resource->scopeSpans as $scope) {
                foreach ($scope->spans as $span) {
                    self::span($batch, $resource, $scope, $span, $local);
                }
            }
        }
    }

    /**
     * @param array<array-key, true> $local Span IDs of the resource.
     */
    private static function span(Batch $batch, ResourceSpans $resource, ScopeSpans $scope, OtlpSpan $span, array $local): void
    {
        $service = $resource->getServiceName();
        $op = self::op($span->kind, $span->attributes);
        $status = $span->status->code === StatusCode::Error ? 'internal_error' : 'ok';
        $start = self::seconds($span->startTimeUnixNano);
        $end = self::seconds($span->endTimeUnixNano);
        $attributes = self::spanAttributes($resource, $scope, $span);

        $parent = $span->parentSpanId;
        $remote = ($span->flags & self::FLAG_HAS_IS_REMOTE) !== 0 && ($span->flags & self::FLAG_IS_REMOTE) !== 0;
        $entry = \in_array($span->kind, [SpanKind::Server, SpanKind::Consumer], true) && !isset($local[(string) $parent]);
        if ($parent === null || $remote || $entry) {
            $environment = $resource->resourceAttributes['deployment.environment.name']
                ?? $resource->resourceAttributes['deployment.environment']
                ?? null;
            $release = $resource->resourceAttributes['service.version'] ?? null;
            $batch->transactions[] = new Transaction(
                id: "{$span->traceId}-{$span->spanId}",
                project: $batch->project,
                traceId: $span->traceId,
                spanId: $span->spanId,
                parentSpanId: $parent,
                name: $span->name,
                op: $op,
                status: $status,
                start: $start,
                end: $end,
                source: Source::Otel,
                service: $service,
                attributes: $attributes,
                environment: \is_string($environment) ? $environment : null,
                release: \is_string($release) ? $release : null,
            );
            return;
        }

        $batch->spans[] = new Span(
            project: $batch->project,
            traceId: $span->traceId,
            spanId: $span->spanId,
            parentSpanId: $parent,
            transactionId: null,
            op: $op,
            description: $span->name,
            status: $status,
            start: $start,
            end: $end,
            source: Source::Otel,
            service: $service,
            peer: Peer::classify($op, $span->attributes, $span->name),
            attributes: $attributes,
        );
    }

    /**
     * The span attributes with the OTel parts the UI shows: `otel.kind`, `otel.status_code`,
     * `otel.status_description`, `otel.events`, `otel.links`, `otel.resource`, `otel.scope`.
     */
    private static function spanAttributes(ResourceSpans $resource, ScopeSpans $scope, OtlpSpan $span): array
    {
        $attributes = $span->attributes;
        $attributes['otel.kind'] = self::kind($span->kind);
        $attributes['otel.status_code'] = match ($span->status->code) {
            StatusCode::Ok => 'ok',
            StatusCode::Error => 'error',
            default => 'unset',
        };
        $span->status->message === '' or $attributes['otel.status_description'] = $span->status->message;

        $events = [];
        foreach ($span->events as $event) {
            $events[] = ['name' => $event->name, 'time' => self::iso($event->timeUnixNano)]
                + ($event->attributes === [] ? [] : ['attributes' => $event->attributes]);
        }
        $events === [] or $attributes['otel.events'] = $events;

        $links = [];
        foreach ($span->links as $link) {
            $links[] = ['trace_id' => $link->traceId, 'span_id' => $link->spanId]
                + ($link->attributes === [] ? [] : ['attributes' => $link->attributes]);
        }
        $links === [] or $attributes['otel.links'] = $links;

        $resource->resourceAttributes === [] or $attributes['otel.resource'] = $resource->resourceAttributes;
        $scopeData = self::scope($scope->scopeName, $scope->scopeVersion);
        $scopeData === null or $attributes['otel.scope'] = $scopeData;

        return $attributes;
    }

    /**
     * A Sentry-style op from the span kind and the semantic convention attributes.
     */
    private static function op(SpanKind $kind, array $attributes): string
    {
        $has = static function (string ...$keys) use ($attributes): bool {
            foreach ($keys as $key) {
                if (\array_key_exists($key, $attributes)) {
                    return true;
                }
            }
            return false;
        };

        return match (true) {
            $has('http.request.method', 'http.method') => $kind === SpanKind::Client ? 'http.client' : 'http.server',
            $has('db.system', 'db.system.name') => 'db',
            $has('messaging.system') => match ($kind) {
                SpanKind::Producer => 'queue.publish',
                SpanKind::Consumer => 'queue.process',
                default => 'queue',
            },
            $has('rpc.system') => 'rpc',
            default => self::kind($kind),
        };
    }

    private static function kind(SpanKind $kind): string
    {
        return match ($kind) {
            SpanKind::Server => 'server',
            SpanKind::Client => 'client',
            SpanKind::Producer => 'producer',
            SpanKind::Consumer => 'consumer',
            default => 'internal',
        };
    }

    private static function logs(Batch $batch, OtlpLogs $frame): void
    {
        foreach ($frame->resourceLogs as $resourceLogs) {
            $resource = $resourceLogs['resource']['attributes'];
            $service = $resource['service.name'] ?? null;
            $scope = self::scope($resourceLogs['scope']['name'], $resourceLogs['scope']['version']);
            foreach ($resourceLogs['logRecords'] as $record) {
                $attributes = $record['attributes'];
                $resource === [] or $attributes['otel.resource'] = $resource;
                $scope === null or $attributes['otel.scope'] = $scope;
                $body = $record['body'];
                $time = $record['timeUnixNano'] ?: $record['observedTimeUnixNano'];

                $batch->logs[] = new Log(
                    id: Uuid::generate(),
                    project: $batch->project,
                    level: self::level($record['severityNumber'], $record['severityText']),
                    body: \is_string($body) ? $body : self::json($body),
                    time: self::seconds($time),
                    source: Source::Otel,
                    traceId: $record['traceId'],
                    spanId: $record['spanId'],
                    severityNumber: $record['severityNumber'] > 0 ? $record['severityNumber'] : null,
                    service: \is_string($service) ? $service : null,
                    attributes: $attributes,
                );
            }
        }
    }

    /**
     * The Sentry log level of an OTel severity: by the number when set, else by the text.
     */
    private static function level(int $number, string $text): string
    {
        $text = \strtolower(\trim($text));

        return match (true) {
            $number >= 21 => 'fatal',
            $number >= 17 => 'error',
            $number >= 13 => 'warn',
            $number >= 9 => 'info',
            $number >= 5 => 'debug',
            $number >= 1 => 'trace',
            default => match ($text) {
                '' => 'info',
                'warning' => 'warn',
                'critical', 'emergency', 'alert' => 'fatal',
                default => $text,
            },
        };
    }

    private static function metrics(Batch $batch, OtlpMetrics $frame): void
    {
        foreach ($frame->resourceMetrics as $resourceMetrics) {
            $resource = $resourceMetrics['resource']['attributes'];
            $service = $resource['service.name'] ?? null;
            $scope = self::scope($resourceMetrics['scope']['name'], $resourceMetrics['scope']['version']);
            foreach ($resourceMetrics['metrics'] as $metric) {
                if ($metric['name'] === '') {
                    continue;
                }

                $type = match ($metric['type']) {
                    'sum' => $metric['monotonic'] ? 'counter' : 'gauge',
                    'gauge' => 'gauge',
                    default => 'distribution',
                };
                foreach ($metric['dataPoints'] as $point) {
                    $attributes = $point['attributes'];
                    foreach (['count', 'sum', 'min', 'max'] as $key) {
                        $point[$key] === null or $attributes["otel.$key"] = $point[$key];
                    }
                    $resource === [] or $attributes['otel.resource'] = $resource;
                    $scope === null or $attributes['otel.scope'] = $scope;

                    $batch->metrics[] = new Metric(
                        id: Uuid::generate(),
                        project: $batch->project,
                        name: $metric['name'],
                        type: $type,
                        value: $point['value'],
                        time: self::seconds($point['timeUnixNano']),
                        source: Source::Otel,
                        unit: $metric['unit'],
                        traceId: $point['traceId'],
                        spanId: $point['spanId'],
                        service: \is_string($service) ? $service : null,
                        attributes: $attributes,
                    );
                }
            }
        }
    }

    /**
     * @return array{name: string, version?: string}|null
     */
    private static function scope(string $name, string $version): ?array
    {
        if ($name === '' && $version === '') {
            return null;
        }

        return ['name' => $name] + ($version === '' ? [] : ['version' => $version]);
    }

    /**
     * @return float|null Null for 0, an unset time in OTLP.
     */
    private static function seconds(int $nanoseconds): ?float
    {
        return $nanoseconds === 0 ? null : (float) \intdiv($nanoseconds, 1_000_000_000) + (float) ($nanoseconds % 1_000_000_000) / 1e9;
    }

    private static function iso(int $nanoseconds): string
    {
        $micro = \intdiv($nanoseconds % 1_000_000_000, 1000);
        return \gmdate('Y-m-d\TH:i:s', \intdiv($nanoseconds, 1_000_000_000)) . \sprintf('.%06dZ', $micro);
    }

    private static function json(mixed $value): string
    {
        return (string) \json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION);
    }
}
