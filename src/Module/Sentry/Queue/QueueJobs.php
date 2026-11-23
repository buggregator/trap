<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Sentry\Queue;

use Buggregator\Trap\Module\Sentry\Frontend\Api\ErrorEvent;
use Buggregator\Trap\Module\Sentry\Frontend\Api\Scope;
use Buggregator\Trap\Server\Event\Event;
use Buggregator\Trap\Server\Telemetry\Span;
use Buggregator\Trap\Server\Telemetry\TelemetryStore;
use Buggregator\Trap\Server\Telemetry\Trace;
use Buggregator\Trap\Server\Telemetry\Transaction;

/**
 * The queue jobs of the {@see TelemetryStore}, grouped on every read in one pass over it: no index to keep,
 * so the store's limits and clearing bound them too.
 *
 * A job is the attempts of one message in one project taken by one consumer, so a message delivered to several
 * consumers makes a job for each.
 *
 * @internal
 */
final class QueueJobs
{
    public const PUBLISH_OP = 'queue.publish';

    /** How many parents up a span is followed. */
    private const MAX_DEPTH = 256;

    /**
     * @param array<non-empty-string, Job> $jobs By {@see Job::$id}.
     * @param array<non-empty-string, Span|Transaction> $publishers The last `queue.publish` span of a message ID.
     * @param array<string, list<Attempt>> $attemptsByTrace The attempts of the jobs, by trace ID.
     */
    private function __construct(
        private readonly TelemetryStore $store,
        private readonly array $jobs,
        private readonly array $publishers,
        private readonly array $attemptsByTrace,
    ) {}

    /**
     * @param non-empty-string|null $project Only the attempts of this project; publishing spans of any.
     */
    public static function read(TelemetryStore $store, ?string $project = null): self
    {
        $groups = [];
        $publishers = [];
        $byTrace = [];
        foreach ($store->traces() as $trace) {
            $attempts = [];
            foreach ($trace->transactions as $transaction) {
                $attempt = Attempt::fromTransaction($transaction);
                $attempt === null ? self::publisher($transaction, $publishers) : $attempts[] = $attempt;
            }
            foreach ($trace->spans as $span) {
                $attempt = Attempt::fromSpan($span);
                $attempt === null ? self::publisher($span, $publishers) : $attempts[] = $attempt;
            }

            foreach (self::withoutWrappers($trace, $attempts) as $attempt) {
                if ($project === null || $attempt->project === $project) {
                    $groups[self::key($attempt)][] = $attempt;
                    $byTrace[$trace->id][] = $attempt;
                }
            }
        }

        $jobs = [];
        foreach ($groups as $id => $attempts) {
            $messageId = $attempts[0]->messageId;
            $publisher = $messageId === null ? null : $publishers[$messageId] ?? null;
            $jobs[$id] = new Job(
                $id,
                $attempts,
                $publisher instanceof Span ? $publisher->description : (string) $publisher?->name,
            );
        }

        return new self($store, $jobs, $publishers, $byTrace);
    }

    /**
     * @param list<Job> $jobs
     * @return array{
     *     jobs: int<0, max>,
     *     failed: int<0, max>,
     *     retried: int<0, max>,
     *     attempts: int<0, max>,
     *     avg_duration_ms: float|null,
     *     queues: list<array{queue: string, jobs: int<1, max>, failed: int<0, max>}>
     * } The average is of the durations of the last attempts, the Duration column of the list.
     */
    public static function summary(array $jobs): array
    {
        $failed = $retried = $attempts = $timed = $total = 0;
        $queues = [];
        foreach ($jobs as $job) {
            $attempts += $job->attemptCount();
            $job->retried() and ++$retried;
            $queue = $queues[$job->last->queue] ?? ['queue' => $job->last->queue, 'jobs' => 0, 'failed' => 0];
            ++$queue['jobs'];
            if ($job->failed()) {
                ++$failed;
                ++$queue['failed'];
            }
            $queues[$job->last->queue] = $queue;

            $duration = $job->last->durationMs();
            if ($duration !== null) {
                ++$timed;
                $total += $duration;
            }
        }

        $queues = \array_values($queues);
        \usort($queues, static fn(array $a, array $b): int => [$b['jobs'], $a['queue']] <=> [$a['jobs'], $b['queue']]);

        return [
            'jobs' => \count($jobs),
            'failed' => $failed,
            'retried' => $retried,
            'attempts' => $attempts,
            'avg_duration_ms' => $timed === 0 ? null : \round($total / $timed, 1),
            'queues' => $queues,
        ];
    }

    /**
     * The Sentry errors of the events, to pass to {@see errors()}: read once for any number of jobs.
     *
     * @param iterable<Event> $events
     * @return array<string, non-empty-list<ErrorEvent>> By trace ID.
     */
    public static function errorsByTrace(iterable $events): array
    {
        $result = [];
        foreach ($events as $event) {
            $error = ErrorEvent::fromEvent($event);
            $error?->traceId === null or $result[$error->traceId][] = $error;
        }

        return $result;
    }

    /**
     * @param string $queue Only the jobs whose last attempt took the message from this queue.
     * @param string $name Only the jobs of this name.
     * @param ''|'ok'|'failed'|'retried' $status `ok` and `failed` by the last attempt; `retried` for jobs
     *        run more than once.
     * @return list<Job> The jobs whose last attempt ended in the period, the latest first.
     */
    public function list(Scope $scope, string $queue = '', string $name = '', string $status = ''): array
    {
        $result = [];
        foreach ($this->jobs as $job) {
            $scope->contains($job->last->project, $job->finishedAt())
            && ($queue === '' || $job->last->queue === $queue)
            && ($name === '' || $job->name() === $name)
            && match ($status) {
                'ok' => !$job->failed(),
                'failed' => $job->failed(),
                'retried' => $job->retried(),
                default => true,
            } and $result[] = $job;
        }

        \usort($result, static fn(Job $a, Job $b): int => [$b->finishedAt() ?? -\INF, $a->id] <=> [$a->finishedAt() ?? -\INF, $b->id]);

        return $result;
    }

    public function find(string $id): ?Job
    {
        return $this->jobs[$id] ?? null;
    }

    /**
     * The transaction that published the message: the one with its `queue.publish` span, else the one with
     * the parent span of an attempt that continued the trace of its producer.
     */
    public function dispatcher(Job $job): ?Transaction
    {
        $messageId = $job->messageId();
        $publisher = $messageId === null ? null : $this->publishers[$messageId] ?? null;
        if ($publisher instanceof Transaction) {
            return $publisher;
        }

        if ($publisher !== null) {
            return $this->owner($publisher->traceId, $publisher->spanId);
        }

        // A span attempt's parent is its worker's own transaction, not the producer.
        foreach ($job->attempts as $attempt) {
            $owner = $attempt->isTransaction && $attempt->parentSpanId !== null
                ? $this->owner($attempt->traceId, $attempt->parentSpanId)
                : null;
            if ($owner !== null && $owner->id !== $attempt->id) {
                return $owner;
            }
        }

        return null;
    }

    /**
     * The errors captured in the attempts of the jobs, each on one attempt of its trace, of any job, as
     * {@see AttemptErrors} places it.
     *
     * @param list<Job> $jobs
     * @param array<array-key, list<ErrorEvent>> $errorsByTrace Of {@see errorsByTrace()}.
     * @return array<non-empty-string, list<ErrorEvent>> By attempt ID, the oldest first.
     */
    public function errors(array $jobs, array $errorsByTrace): array
    {
        $traces = [];
        foreach ($jobs as $job) {
            foreach ($job->attempts as $attempt) {
                $traces[$attempt->traceId] = true;
            }
        }

        $result = [];
        foreach (\array_keys($traces) as $traceId) {
            $traceId = (string) $traceId;
            $errors = $errorsByTrace[$traceId] ?? [];
            $trace = $this->store->trace($traceId);
            if ($errors === [] || $trace === null) {
                continue;
            }

            $placer = new AttemptErrors($trace, $this->attemptsByTrace[$traceId] ?? []);
            foreach ($errors as $error) {
                $attempt = $placer->attemptOf($error);
                $attempt === null or $result[$attempt->id][] = $error;
            }
        }

        foreach ($result as &$errors) {
            \usort($errors, static fn(ErrorEvent $a, ErrorEvent $b): int => $a->capturedTime() <=> $b->capturedTime());
        }

        return $result;
    }

    /**
     * @param array<non-empty-string, Span|Transaction> $publishers
     */
    private static function publisher(Span|Transaction $item, array &$publishers): void
    {
        $messageId = $item->op === self::PUBLISH_OP ? Attempt::messageId($item->attributes) : null;
        $messageId === null or $publishers[$messageId] = $item;
    }

    /**
     * The job ID: the project, the consumer when known and the message ID, each URL-encoded, joined by `/`;
     * the attempt ID for an attempt without a message ID.
     *
     * @return non-empty-string
     */
    private static function key(Attempt $attempt): string
    {
        if ($attempt->messageId === null) {
            return $attempt->id;
        }

        $parts = [$attempt->project, ...($attempt->consumer === '' ? [] : [$attempt->consumer]), $attempt->messageId];
        $key = \implode('/', \array_map(\rawurlencode(...), $parts));

        return $key === '' ? $attempt->id : $key;
    }

    /**
     * The attempts but those wrapping another attempt of the same message: sentry-python 3 sends a Celery task
     * as a `queue.process` segment without `messaging.*` around a `queue.process` span with them.
     *
     * @param list<Attempt> $attempts Of the trace.
     * @return list<Attempt>
     */
    private static function withoutWrappers(Trace $trace, array $attempts): array
    {
        $bySpan = [];
        foreach ($attempts as $attempt) {
            $attempt->spanId === null or $bySpan[$attempt->spanId] = $attempt;
        }

        $wrappers = [];
        $parents = AttemptErrors::parents($trace);
        foreach ($attempts as $attempt) {
            if ($attempt->messageId === null) {
                continue;
            }

            $spanId = $attempt->parentSpanId;
            for ($depth = 0; $spanId !== null && $depth < self::MAX_DEPTH; ++$depth) {
                $outer = $bySpan[$spanId] ?? null;
                $outer === null || ($outer->messageId !== null && $outer->messageId !== $attempt->messageId)
                    or $wrappers[$outer->id] = true;
                $spanId = $parents[$spanId] ?? null;
            }
        }

        return $wrappers === []
            ? $attempts
            : \array_values(\array_filter($attempts, static fn(Attempt $a): bool => !isset($wrappers[$a->id])));
    }

    /**
     * The transaction a span belongs to: the span of a transaction, else up its parents.
     */
    private function owner(string $traceId, ?string $spanId): ?Transaction
    {
        $trace = $this->store->trace($traceId);
        if ($trace === null) {
            return null;
        }

        $bySpan = [];
        foreach ($trace->transactions as $transaction) {
            $transaction->spanId === null or $bySpan[$transaction->spanId] = $transaction;
        }

        for ($depth = 0; $spanId !== null && $depth < self::MAX_DEPTH; ++$depth) {
            if (isset($bySpan[$spanId])) {
                return $bySpan[$spanId];
            }

            $span = $trace->spans[$spanId] ?? null;
            if ($span === null) {
                return null;
            }

            if ($span->transactionId !== null) {
                return $trace->transactions[$span->transactionId] ?? null;
            }

            $spanId = $span->parentSpanId;
        }

        return null;
    }
}
