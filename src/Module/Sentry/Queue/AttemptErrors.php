<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Sentry\Queue;

use Buggregator\Trap\Module\Sentry\Frontend\Api\ErrorEvent;
use Buggregator\Trap\Server\Telemetry\Trace;

/**
 * Places the error events of one trace on the attempts of its jobs, each on one attempt of its project:
 * 1. the attempt its span is in, within the segment of its service: the attempt's own span or a span inside it;
 * 2. else an attempt inside its span in the same service: sentry-python captures a Celery task's exception in
 *    the segment around the `queue.process` span;
 * 3. else, for a span Trap has not, a failed attempt whose parent it is: sentry-laravel's sync driver runs the job
 *    in a `queue.publish` span it never ends, so never sends, and reports the job's exception with it; any error
 *    of the transaction that ran the job within the slack goes there too;
 * 4. else, for a span Trap has not, an attempt with the same parent: sentry-laravel reports a queued job's
 *    exception after it ends the attempt, with a span of no transaction and the `queue.publish` span of the
 *    message as the parent.
 *
 * Out of several attempts, see {@see self::ranAt()}. The indexes are built once, so placing stays linear.
 *
 * @internal
 */
final class AttemptErrors
{
    /** How many parents up a span is followed. */
    private const MAX_DEPTH = 256;

    /** Seconds after the end of a failed attempt its exception may be captured: the SDKs report it then. */
    private const SLACK = 1.0;

    /** @var array<array-key, string|null> */
    private readonly array $parents;

    /** @var array<array-key, true> The span IDs of the transactions: a segment is one service */
    private array $segments = [];

    /** @var array<array-key, Attempt> By span ID */
    private array $bySpan = [];

    /** @var array<string, list<Attempt>> By project and parent span ID */
    private array $byParent = [];

    /** @var array<array-key, list<Attempt>> The span attempts inside a span of their service, by its ID */
    private array $inside = [];

    /**
     * @param list<Attempt> $attempts Of the trace, of any job.
     */
    public function __construct(Trace $trace, array $attempts)
    {
        $this->parents = self::parents($trace);
        foreach ($trace->transactions as $transaction) {
            $transaction->spanId === null or $this->segments[$transaction->spanId] = true;
        }

        foreach ($attempts as $attempt) {
            $attempt->spanId === null or $this->bySpan[$attempt->spanId] = $attempt;
            $this->byParent[self::parentKey($attempt->project, $attempt->parentSpanId)][] = $attempt;

            // A transaction attempt runs in a service of its own, whatever span queued its message.
            $spanId = $attempt->isTransaction ? null : $attempt->parentSpanId;
            for ($depth = 0; $spanId !== null && $depth < self::MAX_DEPTH; ++$depth) {
                $this->inside[$spanId][] = $attempt;
                if (isset($this->segments[$spanId])) {
                    break;
                }
                $spanId = $this->parents[$spanId] ?? null;
            }
        }
    }

    /**
     * @return array<array-key, string|null> The parent span ID of every stored span and transaction of the trace.
     */
    public static function parents(Trace $trace): array
    {
        $parents = [];
        foreach ($trace->transactions as $transaction) {
            $transaction->spanId === null or $parents[$transaction->spanId] = $transaction->parentSpanId;
        }
        foreach ($trace->spans as $span) {
            $parents[$span->spanId] = $span->parentSpanId;
        }

        return $parents;
    }

    public function attemptOf(ErrorEvent $error): ?Attempt
    {
        $project = $error->project;
        $spanId = $error->spanId;
        if ($spanId !== null && \array_key_exists($spanId, $this->parents)) {
            for ($id = $spanId, $depth = 0; $id !== null && $depth < self::MAX_DEPTH; ++$depth) {
                $attempt = $this->bySpan[$id] ?? null;
                if ($attempt !== null) {
                    return $attempt->project === $project ? $attempt : null;
                }
                if (isset($this->segments[$id])) {
                    break;
                }
                $id = $this->parents[$id] ?? null;
            }

            return self::ranAt($error, self::ofProject($this->inside[$spanId] ?? [], $project));
        }

        $children = $spanId === null ? [] : \array_filter(
            $this->byParent[self::parentKey($project, $spanId)] ?? [],
            static fn(Attempt $a): bool => $a->failed(),
        );

        return self::ranAt($error, $children)
            ?? self::ranAt($error, $this->byParent[self::parentKey($project, $error->parentSpanId)] ?? []);
    }

    /**
     * The attempt that failed nearest before the error, within a moment: every SDK captures a job's exception right
     * after it ends the attempt, by the same clock. Else the attempt that ran at the error's time, a failed one
     * first, then the latest started.
     *
     * @param array<array-key, Attempt> $attempts
     */
    private static function ranAt(ErrorEvent $error, array $attempts): ?Attempt
    {
        $time = $error->capturedTime();
        $ended = $running = null;
        foreach ($attempts as $attempt) {
            $end = $attempt->end ?? $attempt->start;
            if ($attempt->start === null || $end === null || $time < $attempt->start) {
                continue;
            }

            if ($time <= $end) {
                $running === null
                || [$attempt->failed(), $attempt->start] > [$running->failed(), (float) $running->start]
                    and $running = $attempt;
            } elseif ($attempt->failed() && $time <= $end + self::SLACK) {
                $ended === null || $end > (float) ($ended->end ?? $ended->start)
                    and $ended = $attempt;
            }
        }

        return $ended ?? $running;
    }

    /**
     * @param list<Attempt> $attempts
     * @return list<Attempt>
     */
    private static function ofProject(array $attempts, string $project): array
    {
        return \array_values(\array_filter($attempts, static fn(Attempt $a): bool => $a->project === $project));
    }

    private static function parentKey(string $project, ?string $parentSpanId): string
    {
        return $parentSpanId === null ? "$project\0" : "$project\0$parentSpanId\0";
    }
}
