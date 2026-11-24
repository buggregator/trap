<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Sentry\Queue;

use Buggregator\Trap\Module\Sentry\Frontend\Api\Scope;
use Buggregator\Trap\Module\Sentry\Queue\AttemptErrors;
use Buggregator\Trap\Module\Sentry\Queue\Job;
use Buggregator\Trap\Module\Sentry\Queue\QueueJobs;
use Buggregator\Trap\Server\Event\Event;
use Buggregator\Trap\Server\Event\EventStore;
use Buggregator\Trap\Server\Telemetry\Batch;
use Buggregator\Trap\Server\Telemetry\Source;
use Buggregator\Trap\Server\Telemetry\Span;
use Buggregator\Trap\Server\Telemetry\TelemetryStore;
use Buggregator\Trap\Server\Telemetry\Transaction;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Covers(QueueJobs::class)]
#[Covers(AttemptErrors::class)]
#[Covers(Job::class)]
#[Test]
final class QueueJobsTest
{
    private const TRACE = '11111111111111111111111111111111';

    private TelemetryStore $store;
    private EventStore $events;

    #[BeforeTest]
    public function createStores(): void
    {
        $this->store = new TelemetryStore();
        $this->events = new EventStore();
    }

    /**
     * One request queues two jobs in its trace; sentry-laravel reports each job's exception after the attempt
     * ends, with a span ID of no span and the `queue.publish` span of its message as the parent.
     */
    public function testMapsLaravelWorkerErrorsByParentSpanAndSdkTime(): void
    {
        $this->add('shop', [
            new Transaction('req', 'shop', self::TRACE, 'r', null, 'POST /send', 'http.server', 'ok', 100.0, 100.2, Source::Sentry, 'api'),
            $this->attempt('a1', 'invoice', 'pubA', 101.0, 101.5, 0, 'internal_error'),
            $this->attempt('a2', 'invoice', 'pubA', 110.0, 110.5, 1),
            $this->attempt('b1', 'report', 'pubB', 101.0, 101.6, 0, 'internal_error'),
        ], [
            new Span('shop', self::TRACE, 'pubA', 'r', 'req', 'queue.publish', 'App\Jobs\SendInvoice', 'ok', 100.1, 100.11, Source::Sentry, 'api', attributes: ['messaging.message.id' => 'invoice']),
            new Span('shop', self::TRACE, 'pubB', 'r', 'req', 'queue.publish', 'App\Jobs\Report', 'ok', 100.1, 100.11, Source::Sentry, 'api', attributes: ['messaging.message.id' => 'report']),
        ]);
        // Received long after it was captured: the SDK time places it.
        $this->error('invoice-error', 'shop', 'random1', 'pubA', 101.52, received: 500.0);
        $this->error('report-error', 'shop', 'random2', 'pubB', 101.55);
        $this->error('other-project', 'billing', 'random3', 'pubA', 101.52);
        $this->error('request-error', 'shop', 'r', null, 101.2);
        $this->error('between-attempts', 'shop', 'random4', 'pubA', 105.0);

        Assert::same($this->errorIds('shop/invoice'), ['a1' => ['invoice-error']]);
        Assert::same($this->errorIds('shop/report'), ['b1' => ['report-error']]);
    }

    public function testMapsAnErrorOfAStoredSpanToTheAttemptItIsIn(): void
    {
        $this->add('shop', [
            $this->attempt('a1', 'm', 'pub', 101.0, 101.5, 0, 'internal_error'),
            $this->attempt('a2', 'm', 'pub', 110.0, 110.5, 1),
        ], [
            new Span('shop', self::TRACE, 'db', 'a2-span', 'a2', 'db', 'SELECT', 'ok', 110.1, 110.2, Source::Sentry, 'worker'),
        ]);
        // Its time is that of the first attempt, but its span is in the second.
        $this->error('in-db-span', 'shop', 'db', 'a2-span', 101.2);
        $this->error('own-span', 'shop', 'a1-span', 'pub', 101.2);

        Assert::same($this->errorIds('shop/m'), ['a2' => ['in-db-span'], 'a1' => ['own-span']]);
    }

    public function testMapsAnErrorOfARootAttemptByTime(): void
    {
        $this->add('shop', [$this->attempt('a1', 'm', null, 101.0, 101.5, 2, 'internal_error')]);
        $this->error('worker-error', 'shop', 'random', null, 101.51);
        $this->error('late', 'shop', 'random', null, 103.0);

        Assert::same($this->errorIds('shop/m'), ['a1' => ['worker-error']]);
    }

    public function testOrdersAttemptsByTheirNumberWhateverTheClocks(): void
    {
        // The second worker's clock runs 30 s behind.
        $this->add('shop', [
            $this->attempt('a1', 'm', null, 100.0, 101.0, 0, 'internal_error'),
            $this->attempt('a2', 'm', null, 80.0, 81.0, 1),
        ]);

        $job = $this->jobs()->find('shop/m');

        Assert::same(\array_map(static fn($a): string => $a->id, (array) $job?->attempts), ['a1', 'a2']);
        Assert::false((bool) $job?->failed());
    }

    public function testOrdersAttemptsWithoutNumbersByTime(): void
    {
        $this->add('shop', [
            $this->attempt('a1', 'm', null, 100.0, 101.0, null),
            $this->attempt('a2', 'm', null, 80.0, 81.0, 1, 'internal_error'),
        ]);

        Assert::same(\array_map(static fn($a): string => $a->id, (array) $this->jobs()->find('shop/m')?->attempts), ['a2', 'a1']);
    }

    public function testNumbersOrderAttemptsAndTimesPlaceThoseWithout(): void
    {
        // The worker of #2 runs behind, the attempt without a number came first.
        $this->add('shop', [
            $this->attempt('n2', 'm', null, 90.0, 91.0, 1),
            $this->attempt('n1', 'm', null, 100.0, 101.0, 0, 'internal_error'),
            $this->attempt('u', 'm', null, 95.0, 96.0, null, 'internal_error'),
        ]);
        $this->add('shop', [
            $this->attempt('d1', 'twice', null, 100.0, 101.0, 0, 'internal_error'),
            $this->attempt('d2', 'twice', null, 110.0, 111.0, 0),
        ]);

        $job = $this->jobs()->find('shop/m');
        Assert::notNull($job);
        Assert::same(\array_map(static fn($a): string => $a->id, $job->attempts), ['u', 'n1', 'n2']);
        Assert::same(\array_map($job->number(...), $job->attempts), [1, 2, 3]);
        Assert::same([$job->failed(), $job->attemptCount()], [false, 3]);
        $twice = $this->jobs()->find('shop/twice');
        Assert::same(\array_map($twice->number(...), $twice->attempts), [1, 2]);
    }

    /**
     * sentry-python 2 sends a task as a `queue.task.celery` transaction around a `queue.process` span, and
     * captures its exception outside that span: the error is of the transaction.
     */
    public function testMapsACeleryErrorOfTheSegmentAroundTheAttempt(): void
    {
        $this->add('shop', [
            new Transaction('celery-txn', 'shop', self::TRACE, 'ct', null, 'tasks.mail', 'queue.task.celery', 'internal_error', 100.0, 101.0, Source::Sentry, 'worker'),
        ], [
            new Span('shop', self::TRACE, 'cs', 'ct', 'celery-txn', 'queue.process', 'tasks.mail', 'internal_error', 100.1, 100.8, Source::Sentry, 'worker', attributes: ['messaging.message.id' => 'c1']),
        ]);
        $this->error('celery-error', 'shop', 'ct', null, 100.85);

        Assert::same($this->errorIds('shop/c1'), [self::TRACE . '-cs' => ['celery-error']]);
    }

    /**
     * sentry-python 3: the segment around the attempt is a `queue.process` span too, left out as an attempt.
     */
    public function testMapsACelery3ErrorOfTheWrapperLeftOut(): void
    {
        $this->add('shop', [
            new Transaction(self::TRACE . '-seg', 'shop', self::TRACE, 'seg', null, 'tasks.mail', 'queue.process', 'error', 100.0, 101.0, Source::Sentry, 'worker'),
        ], [
            new Span('shop', self::TRACE, 'task', 'seg', null, 'queue.process', 'tasks.mail', 'error', 100.1, 100.8, Source::Sentry, 'worker', attributes: ['messaging.message.id' => 'c3']),
        ]);
        $this->error('celery3-error', 'shop', 'seg', null, 100.85);

        Assert::same($this->errorIds('shop/c3'), [self::TRACE . '-task' => ['celery3-error']]);
    }

    /**
     * The sync driver of sentry-laravel runs the job as a span of the `queue.publish` span it never ends, so never
     * sends, and reports the job's exception with that span, the parent of the attempt.
     */
    public function testMapsASyncDriverErrorByTheUnsentPublishSpan(): void
    {
        $this->add('shop', [
            new Transaction('req', 'shop', self::TRACE, 'r', null, 'POST /send', 'http.server', 'internal_error', 100.0, 102.0, Source::Sentry, 'api'),
        ], [
            new Span('shop', self::TRACE, 'mw', 'r', 'req', 'middleware.handle', 'App', 'ok', 100.05, 101.95, Source::Sentry, 'api'),
            new Span('shop', self::TRACE, 'job', 'pub', 'req', 'queue.process', '', 'internal_error', 100.2, 100.4, Source::Sentry, 'api', attributes: ['messaging.message.id' => 's1']),
            new Span('shop', self::TRACE, 'job2', 'pub2', 'req', 'queue.process', '', 'ok', 100.5, 100.9, Source::Sentry, 'api', attributes: ['messaging.message.id' => 's2']),
        ]);
        $this->error('job-error', 'shop', 'pub', 'mw', 100.45);
        $this->error('after-ok-job', 'shop', 'pub2', 'mw', 100.95);
        $this->error('request-error', 'shop', 'pub2', 'mw', 101.0);

        Assert::same($this->errorIds('shop/s1'), [self::TRACE . '-job' => ['job-error']]);
        Assert::same($this->errorIds('shop/s2'), []);
    }

    /**
     * A Celery task queues another one: the child task's exception, captured in its own segment, stays off the
     * parent task the trace climbs to.
     */
    public function testMapsAChainedCeleryTaskErrorToItsOwnTask(): void
    {
        $this->chain('shop');

        Assert::same($this->errorIds('shop/parent'), []);
        Assert::same($this->errorIds('shop/child'), [self::TRACE . '-ccs' => ['child-error']]);
    }

    /**
     * The chained task runs in another project: the job shows its error with the project filter and without.
     */
    public function testMapsAChainedTaskErrorOfAnotherProjectWhateverTheFilter(): void
    {
        $this->chain('billing');

        Assert::same($this->errorIds('billing/child'), [self::TRACE . '-ccs' => ['child-error']]);
        Assert::same($this->errorIds('billing/child', 'billing'), [self::TRACE . '-ccs' => ['child-error']]);
        Assert::same($this->errorIds('shop/parent'), []);
    }

    /**
     * sentry-python 2: an error in the segment after the `queue.process` span ended `ok` is not the task's.
     */
    public function testAnErrorAfterAnOkAttemptIsNotItsOwn(): void
    {
        $this->add('shop', [
            new Transaction('celery-txn', 'shop', self::TRACE, 'ct', null, 'tasks.mail', 'queue.task.celery', 'ok', 100.0, 101.0, Source::Sentry, 'worker'),
        ], [
            new Span('shop', self::TRACE, 'cs', 'ct', 'celery-txn', 'queue.process', 'tasks.mail', 'ok', 100.1, 100.8, Source::Sentry, 'worker', attributes: ['messaging.message.id' => 'c1']),
        ]);
        $this->error('postrun-error', 'shop', 'ct', null, 100.85);

        Assert::same($this->errorIds('shop/c1'), []);
    }

    /**
     * Both sibling jobs fail and overlap: each exception follows the end of its own attempt.
     */
    public function testAnErrorGoesToTheFailedAttemptThatEndedNearestBeforeIt(): void
    {
        $this->add('shop', [
            $this->attempt('x', 'both-x', 'ctx', 100.0, 101.0, 0, 'internal_error'),
            $this->attempt('y', 'both-y', 'ctx', 100.5, 101.2, 0, 'internal_error'),
        ]);
        $this->error('x-failed', 'shop', 'random1', 'ctx', 101.01);
        $this->error('y-failed', 'shop', 'random2', 'ctx', 101.21);

        Assert::same($this->errorIds('shop/both-x'), ['x' => ['x-failed']]);
        Assert::same($this->errorIds('shop/both-y'), ['y' => ['y-failed']]);
    }

    /**
     * Jobs queued by a command without a sampled transaction share its trace and parent span, and run at once.
     */
    public function testAnErrorGoesToOneAttemptOfSiblingJobs(): void
    {
        $this->add('shop', [
            $this->attempt('sib-a', 'a', 'ctx', 100.0, 101.0, 0, 'internal_error'),
            $this->attempt('sib-b', 'b', 'ctx', 100.2, 100.9, 0),
        ]);
        $this->error('a-error', 'shop', 'random', 'ctx', 100.95);

        Assert::same($this->errorIds('shop/a'), ['sib-a' => ['a-error']]);
        Assert::same($this->errorIds('shop/b'), []);
    }

    /**
     * sentry-python 3 wraps the `queue.process` span with the message in a `queue.process` segment without it.
     */
    public function testCountsACelery3TaskOnce(): void
    {
        $this->add('shop', [
            new Transaction(self::TRACE . '-seg', 'shop', self::TRACE, 'seg', null, 'tasks.send_mail', 'queue.process', 'ok', 100.0, 101.0, Source::Sentry, 'worker'),
        ], [
            new Span('shop', self::TRACE, 'task', 'seg', null, 'queue.process', 'tasks.send_mail', 'ok', 100.1, 100.9, Source::Sentry, 'worker', attributes: [
                'messaging.system' => 'redis',
                'messaging.message.id' => 'celery-1',
                'messaging.message.retry.count' => 0,
                'messaging.message.receive.latency' => 12.5,
            ]),
        ]);

        $jobs = $this->jobs()->list(new Scope());

        Assert::same(\array_map(static fn(Job $job): string => $job->id, $jobs), ['shop/celery-1']);
        Assert::same([\count($jobs[0]->attempts), $jobs[0]->last->latencyMs], [1, 13]);
    }

    public function testKeepsANestedAttemptOfAnotherMessage(): void
    {
        $this->add('shop', [$this->attempt('outer', 'm1', null, 100.0, 102.0, 0)], [
            new Span('shop', self::TRACE, 'inner', 'outer-span', 'outer', 'queue.process', 'Sync', 'ok', 100.5, 101.0, Source::Sentry, 'worker', attributes: ['messaging.message.id' => 'm2']),
        ]);

        Assert::count($this->jobs()->list(new Scope()), 2);
    }

    public function testAMessageOfSeveralProjectsOrConsumersMakesAJobForEach(): void
    {
        $this->add('shop', [$this->attempt('a1', 'shared', null, 100.0, 101.0, 0)]);
        $this->add('billing', [$this->attempt('b1', 'shared', null, 100.0, 101.0, 0, project: 'billing')]);
        $otel = static fn(string $id, string $service, array $attributes = []): Transaction => new Transaction(
            $id,
            'shop',
            self::TRACE,
            "$id-span",
            null,
            'process orders',
            'queue.process',
            'ok',
            100.0,
            101.0,
            Source::Otel,
            $service,
            $attributes + ['messaging.system' => 'kafka', 'messaging.message.id' => 'order-1'],
        );
        $this->add('shop', [
            $otel('o1', 'mailer'),
            $otel('o2', 'invoicer'),
            $otel('o3', 'invoicer', ['messaging.consumer.group.name' => 'invoices/eu']),
        ]);

        $ids = \array_map(static fn(Job $job): string => $job->id, $this->jobs()->list(new Scope()));
        \sort($ids);

        Assert::same($ids, ['billing/shared', 'shop/invoicer/order-1', 'shop/invoices%2Feu/order-1', 'shop/mailer/order-1', 'shop/shared']);
        Assert::same((int) $this->jobs()->find('shop/shared')?->attemptCount(), 1);
        Assert::null(QueueJobs::read($this->store, 'billing')->find('shop/shared'));
    }

    /**
     * @param list<Transaction> $transactions
     * @param list<Span> $spans
     */
    private function add(string $project, array $transactions, array $spans = []): void
    {
        $batch = new Batch($project);
        $batch->transactions = $transactions;
        $batch->spans = $spans;
        $this->store->add($batch);
    }

    /**
     * A sentry-laravel job transaction.
     *
     * @param non-empty-string $id
     */
    private function attempt(
        string $id,
        string $messageId,
        ?string $parent,
        float $start,
        float $end,
        ?int $retries,
        string $status = 'ok',
        string $project = 'shop',
    ): Transaction {
        return new Transaction(
            $id,
            $project,
            self::TRACE,
            "$id-span",
            $parent,
            'App\Jobs\Job',
            'queue.process',
            $status,
            $start,
            $end,
            Source::Sentry,
            'worker',
            [
                'messaging.system' => 'laravel',
                'messaging.message.id' => $messageId,
                'messaging.message.retry.count' => $retries,
            ],
        );
    }

    /**
     * @param non-empty-string $uuid
     */
    private function error(
        string $uuid,
        string $project,
        string $spanId,
        ?string $parentSpanId,
        float $timestamp,
        ?float $received = null,
    ): void {
        $this->events->add(new Event($uuid, 'sentry', [
            'timestamp' => $timestamp,
            'exception' => ['values' => [['type' => 'RuntimeException', 'value' => $uuid]]],
            'contexts' => ['trace' => ['trace_id' => self::TRACE, 'span_id' => $spanId]
                + ($parentSpanId === null ? [] : ['parent_span_id' => $parentSpanId])],
        ], $received ?? $timestamp + 0.5, $project));
    }

    /**
     * sentry-python 2: task `parent` in `shop` queues task `child` from inside its `queue.process` span; `child`
     * fails, and its exception is captured in its segment after the `queue.process` span ended.
     */
    private function chain(string $childProject): void
    {
        $this->add('shop', [
            new Transaction('parent-txn', 'shop', self::TRACE, 'pct', null, 'tasks.parent', 'queue.task.celery', 'ok', 100.0, 103.0, Source::Sentry, 'worker'),
        ], [
            new Span('shop', self::TRACE, 'pcs', 'pct', 'parent-txn', 'queue.process', 'tasks.parent', 'ok', 100.1, 102.9, Source::Sentry, 'worker', attributes: ['messaging.message.id' => 'parent']),
            new Span('shop', self::TRACE, 'sub', 'pcs', 'parent-txn', 'queue.submit.celery', 'tasks.child', 'ok', 100.2, 100.3, Source::Sentry, 'worker'),
        ]);
        $this->add($childProject, [
            new Transaction('child-txn', $childProject, self::TRACE, 'cct', 'sub', 'tasks.child', 'queue.task.celery', 'internal_error', 100.5, 101.5, Source::Sentry, 'worker'),
        ], [
            new Span($childProject, self::TRACE, 'ccs', 'cct', 'child-txn', 'queue.process', 'tasks.child', 'internal_error', 100.6, 101.4, Source::Sentry, 'worker', attributes: ['messaging.message.id' => 'child']),
        ]);
        $this->error('child-error', $childProject, 'cct', 'sub', 101.45);
    }

    private function jobs(?string $project = null): QueueJobs
    {
        return QueueJobs::read($this->store, $project);
    }

    /**
     * @return array<string, list<string>>
     */
    private function errorIds(string $id, ?string $project = null): array
    {
        $jobs = $this->jobs($project);
        $job = $jobs->find($id);
        Assert::notNull($job);

        $ids = \array_map(static fn($attempt): string => $attempt->id, $job->attempts);
        $errors = \array_intersect_key($jobs->errors([$job], QueueJobs::errorsByTrace($this->events)), \array_flip($ids));

        return \array_map(
            static fn(array $errors): array => \array_map(static fn($error): string => $error->uuid, $errors),
            $errors,
        );
    }
}
