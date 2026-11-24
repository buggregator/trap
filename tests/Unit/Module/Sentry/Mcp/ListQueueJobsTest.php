<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Sentry\Mcp;

use Buggregator\Trap\Module\Sentry\Mcp\ListQueueJobs;
use Buggregator\Trap\Server\Event\Event;
use Buggregator\Trap\Server\Event\EventStore;
use Buggregator\Trap\Server\Telemetry\Batch;
use Buggregator\Trap\Server\Telemetry\Source;
use Buggregator\Trap\Server\Telemetry\TelemetryStore;
use Buggregator\Trap\Server\Telemetry\Transaction;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Covers(ListQueueJobs::class)]
#[Test]
final class ListQueueJobsTest
{
    private const TRACE = '11111111111111111111111111111111';

    public function testListsJobsWithTheirAttemptsAndErrors(): void
    {
        $result = self::tool()->call([]);

        Assert::same($result['total'], 2);
        Assert::same(\array_column($result['jobs'], 'id'), ['shop/m2', 'shop/m1']);
        $job = $result['jobs'][1];
        Assert::same(
            [$job['job'], $job['queue'], $job['system'], $job['status'], $job['failed'], $job['attempts'], $job['failed_attempts']],
            ['App\Jobs\SendInvoice', 'emails', 'laravel', 'ok', false, 2, 1],
        );
        Assert::same([$job['latency_ms'], $job['duration_ms'], $job['dispatched_by']], [3000, 500, null]);
        Assert::same(\array_column($job['attempt_list'], 'attempt'), [1, 2]);
        Assert::same($job['attempt_list'][0]['errors'], [['uuid' => 'err', 'type' => 'RuntimeException', 'value' => 'SMTP down']]);
        Assert::same($job['started'], '1970-01-01T00:00:10.000+00:00');
    }

    public function testNarrowsByIdStatusQueueAndLimit(): void
    {
        $tool = self::tool();

        Assert::same(\array_column($tool->call(['id' => 'shop/m1'])['jobs'], 'id'), ['shop/m1']);
        Assert::same(\array_column($tool->call(['status' => 'failed'])['jobs'], 'id'), ['shop/m2']);
        Assert::same(\array_column($tool->call(['status' => 'retried'])['jobs'], 'id'), ['shop/m1']);
        Assert::same($tool->call(['queue' => 'nope'])['total'], 0);
        Assert::same($tool->call(['project' => 'other'])['total'], 0);
        $limited = $tool->call(['limit' => 1]);
        Assert::same([\count($limited['jobs']), $limited['total']], [1, 2]);
    }

    public function testRejectsAnUnknownStatus(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('`status` must be one of');

        self::tool()->call(['status' => 'running']);
    }

    private static function tool(): ListQueueJobs
    {
        $attributes = static fn(string $id, int $retries): array => [
            'messaging.system' => 'laravel',
            'messaging.message.id' => $id,
            'messaging.destination.name' => 'emails',
            'messaging.message.retry.count' => $retries,
            'messaging.message.receive.latency' => 3,
        ];
        $attempt = static fn(string $id, string $status, float $start, array $attributes): Transaction => new Transaction(
            $id,
            'shop',
            self::TRACE,
            "s-$id",
            null,
            'App\Jobs\SendInvoice',
            'queue.process',
            $status,
            $start,
            $start + 0.5,
            Source::Sentry,
            'worker',
            $attributes,
        );

        $store = new TelemetryStore();
        $batch = new Batch('shop');
        $batch->transactions = [
            $attempt('a1', 'internal_error', 10.0, $attributes('m1', 0)),
            $attempt('a2', 'ok', 20.0, $attributes('m1', 1)),
            $attempt('b1', 'internal_error', 30.0, $attributes('m2', 0)),
        ];
        $store->add($batch);

        $events = new EventStore();
        $events->add(new Event('err', 'sentry', [
            'exception' => ['values' => [['type' => 'RuntimeException', 'value' => 'SMTP down']]],
            'contexts' => ['trace' => ['trace_id' => self::TRACE, 'span_id' => 's-a1']],
        ], 10.2, 'shop'));

        return new ListQueueJobs($store, $events);
    }
}
