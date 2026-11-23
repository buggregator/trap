<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Sentry\Queue;

use Buggregator\Trap\Module\Sentry\Queue\Attempt;
use Buggregator\Trap\Server\Telemetry\Source;
use Buggregator\Trap\Server\Telemetry\Span;
use Buggregator\Trap\Server\Telemetry\Transaction;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

#[Covers(Attempt::class)]
#[Test]
final class AttemptTest
{
    public function testReadsALaravelJobTransaction(): void
    {
        $attempt = Attempt::fromTransaction(self::transaction([
            'messaging.system' => 'laravel',
            'messaging.message.id' => ' 9c1e-uuid ',
            'messaging.destination.name' => 'emails',
            'messaging.destination.connection' => 'redis',
            'messaging.message.retry.count' => 2,
            'messaging.message.receive.latency' => 1.2345,
        ], status: 'internal_error'));

        Assert::notNull($attempt);
        Assert::same(
            [$attempt->id, $attempt->messageId, $attempt->queue, $attempt->connection, $attempt->system, $attempt->name],
            ['tx', '9c1e-uuid', 'emails', 'redis', 'laravel', 'App\Jobs\SendInvoice'],
        );
        Assert::same([$attempt->number, $attempt->latencyMs, $attempt->durationMs()], [3, 1235, 500]);
        Assert::true($attempt->failed());
        Assert::true($attempt->isTransaction);
    }

    #[DataSet([Source::Sentry, 'laravel', 0.25, 250], 'sentry-laravel sends seconds')]
    #[DataSet([Source::Sentry, 'celery', 250, 250], 'other Sentry SDKs send milliseconds')]
    #[DataSet([Source::Sentry, '', 250.4, 250], 'no system: milliseconds of the spec')]
    #[DataSet([Source::Otel, 'laravel', 250, 250], 'OTel: milliseconds whatever the system')]
    #[DataSet([Source::Sentry, 'laravel', '0.5', 500], 'a numeric string')]
    #[DataSet([Source::Sentry, 'laravel', 1e12, Span::MAX_DURATION_MS], 'capped at a year')]
    public function testReadsTheLatencyInItsUnit(Source $source, string $system, mixed $latency, int $ms): void
    {
        Assert::same(Attempt::latencyMs([
            'messaging.system' => $system,
            'messaging.message.receive.latency' => $latency,
        ], $source), $ms);
    }

    #[DataSet([null])]
    #[DataSet([-1])]
    #[DataSet(['soon'])]
    #[DataSet([\NAN])]
    #[DataSet([\INF])]
    #[DataSet(['1e999'])]
    #[DataSet([true])]
    #[DataSet([[1]])]
    public function testMalformedLatencyIsMissing(mixed $latency): void
    {
        Assert::null(Attempt::latencyMs(['messaging.message.receive.latency' => $latency], Source::Sentry));
    }

    #[DataSet([0, 1])]
    #[DataSet([4, 5])]
    #[DataSet(['1', 2])]
    #[DataSet([2.0, 3])]
    #[DataSet([2.5, null])]
    #[DataSet([-1, null])]
    #[DataSet(['x', null])]
    #[DataSet([false, null])]
    #[DataSet([1e30, null])]
    #[DataSet([null, null])]
    public function testAttemptNumberIsTheRetriesPlusOne(mixed $retries, ?int $number): void
    {
        Assert::same(Attempt::number(['messaging.message.retry.count' => $retries]), $number);
    }

    #[DataSet(['abc', 'abc'])]
    #[DataSet([42, '42'])]
    #[DataSet(['  ', null])]
    #[DataSet([4.2, null])]
    #[DataSet([['a'], null])]
    public function testReadsTheMessageId(mixed $id, ?string $expected): void
    {
        Assert::same(Attempt::messageId(['messaging.message.id' => $id]), $expected);
    }

    public function testMissingAttributesAreEmpty(): void
    {
        $attempt = Attempt::fromTransaction(self::transaction([]));

        Assert::notNull($attempt);
        Assert::same(
            [$attempt->messageId, $attempt->queue, $attempt->connection, $attempt->number, $attempt->latencyMs],
            [null, '', '', null, null],
        );
        Assert::false($attempt->failed());
    }

    public function testMalformedAttributesAreEmpty(): void
    {
        $attempt = Attempt::fromTransaction(self::transaction([
            'messaging.message.id' => ['x'],
            'messaging.destination.name' => 5,
            'messaging.system' => null,
            'messaging.message.retry.count' => 'many',
            'messaging.message.receive.latency' => ['ms' => 3],
        ]));

        Assert::notNull($attempt);
        Assert::same([$attempt->messageId, $attempt->queue, $attempt->number, $attempt->latencyMs], [null, '', null, null]);
    }

    #[DataSet(['ok', false])]
    #[DataSet(['', false])]
    #[DataSet(['cancelled', false])]
    #[DataSet(['unknown', false])]
    #[DataSet(['unknown_error', true])]
    #[DataSet(['internal_error', true])]
    #[DataSet(['error', true])]
    #[DataSet(['deadline_exceeded', true])]
    public function testFailsAsSentryFailureRateCounts(string $status, bool $failed): void
    {
        Assert::same(Attempt::isFailedStatus($status), $failed);
    }

    public function testReadsOnlyProcessingSpans(): void
    {
        Assert::null(Attempt::fromTransaction(self::transaction([], op: 'http.server')));
        Assert::null(Attempt::fromTransaction(self::transaction([], op: 'queue.publish')));
        Assert::null(Attempt::fromTransaction(self::transaction(['messaging.operation.type' => 'receive'])));
        Assert::null(Attempt::fromTransaction(self::transaction(['messaging.operation' => 'settle'])));
        Assert::notNull(Attempt::fromTransaction(self::transaction(['messaging.operation.type' => 'process'])));
        Assert::notNull(Attempt::fromTransaction(self::transaction(['messaging.operation' => 'deliver'])));
    }

    public function testReadsASpan(): void
    {
        $attempt = Attempt::fromSpan(new Span(
            'shop',
            'trace',
            'span1',
            'parent',
            'celery-txn',
            'queue.process',
            'tasks.send_mail',
            'ok',
            10.0,
            10.25,
            Source::Sentry,
            'worker',
            attributes: ['messaging.message.id' => 'm1', 'messaging.destination' => 'celery'],
        ));

        Assert::notNull($attempt);
        Assert::same(
            [$attempt->id, $attempt->transactionId, $attempt->name, $attempt->queue, $attempt->durationMs()],
            ['trace-span1', 'celery-txn', 'tasks.send_mail', 'celery', 250],
        );
        Assert::false($attempt->isTransaction);
    }

    private static function transaction(
        array $attributes,
        string $op = 'queue.process',
        string $status = 'ok',
    ): Transaction {
        return new Transaction(
            'tx',
            'shop',
            'trace',
            'span',
            null,
            'App\Jobs\SendInvoice',
            $op,
            $status,
            100.0,
            100.5,
            Source::Sentry,
            'worker',
            $attributes,
        );
    }
}
