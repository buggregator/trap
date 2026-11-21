<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Sentry\Telemetry;

use Buggregator\Trap\Module\Sentry\Telemetry\ItemReader;
use Buggregator\Trap\Server\Telemetry\Batch;
use Buggregator\Trap\Server\Telemetry\Source;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Covers(ItemReader::class)]
#[Test]
final class ItemReaderTest
{
    private const TRACE = '5b8efff798038103d269b633813fc60c';

    public function testReadsTransactionWithItsSpans(): void
    {
        $batch = new Batch('shop');

        ItemReader::read($batch, 'transaction', [
            'transaction' => 'GET /orders',
            'start_timestamp' => 100.0,
            'timestamp' => '1970-01-01T00:01:41.5Z',
            'server_name' => 'web-1',
            'environment' => 'dev',
            'contexts' => ['trace' => [
                'trace_id' => self::TRACE,
                'span_id' => 'aaaaaaaaaaaaaaaa',
                'op' => 'http.server',
                'status' => 'ok',
                'data' => ['service.name' => 'shop-api'],
            ]],
            'spans' => [
                [
                    'span_id' => 'bbbbbbbbbbbbbbbb',
                    'parent_span_id' => 'aaaaaaaaaaaaaaaa',
                    'op' => 'db.sql.query',
                    'description' => 'SELECT 1',
                    'status' => 'internal_error',
                    'start_timestamp' => 100.25,
                    'timestamp' => 100.5,
                    'data' => ['db.name' => 'shop'],
                ],
                ['op' => 'no id'],
            ],
        ], 'envelope-id');

        Assert::count($batch->transactions, 1);
        $transaction = $batch->transactions[0];
        Assert::same($transaction->id, 'envelope-id');
        Assert::same($transaction->project, 'shop');
        Assert::same($transaction->traceId, self::TRACE);
        Assert::same($transaction->spanId, 'aaaaaaaaaaaaaaaa');
        Assert::same($transaction->op, 'http.server');
        Assert::same($transaction->service, 'shop-api');
        Assert::same($transaction->environment, 'dev');
        Assert::same($transaction->durationMs(), 1500);
        Assert::same($transaction->source, Source::Sentry);

        Assert::count($batch->spans, 1);
        $span = $batch->spans[0];
        Assert::same($span->transactionId, 'envelope-id');
        Assert::same($span->service, 'shop-api');
        Assert::same($span->peer->type, 'db');
        Assert::same($span->peer->address, 'shop');
        Assert::true($span->isError());
        Assert::same($span->durationMs(), 250);
        Assert::same($batch->kinds(), ['trace']);
    }

    public function testSkipsTransactionWithoutTraceId(): void
    {
        $batch = new Batch('default');

        Assert::true(ItemReader::read($batch, 'transaction', ['transaction' => 'x', 'spans' => []]));

        Assert::same($batch->transactions, []);
    }

    public function testTransactionServiceFallsBackToSdkName(): void
    {
        $batch = new Batch('default');

        ItemReader::transaction($batch, ['contexts' => ['trace' => ['trace_id' => self::TRACE]], 'sdk' => ['name' => 'sentry.php']]);

        Assert::same($batch->transactions[0]->service, 'sentry.php');
    }

    public function testReadsV2SpansAndTurnsSegmentIntoTransaction(): void
    {
        $batch = new Batch('default');

        ItemReader::read($batch, 'span', ['items' => [
            [
                'trace_id' => self::TRACE,
                'span_id' => 'aaaaaaaaaaaaaaaa',
                'name' => 'GET /',
                'is_segment' => true,
                'start_timestamp' => 1,
                'end_timestamp' => 2,
                'attributes' => ['sentry.op' => ['type' => 'string', 'value' => 'http.server']],
            ],
            [
                'trace_id' => self::TRACE,
                'span_id' => 'bbbbbbbbbbbbbbbb',
                'parent_span_id' => 'aaaaaaaaaaaaaaaa',
                'name' => 'gen_ai.chat',
                'status' => 'error',
                'start_timestamp' => 1,
                'end_timestamp' => 1.5,
            ],
        ]]);

        Assert::count($batch->transactions, 1);
        Assert::same($batch->transactions[0]->id, self::TRACE . '-aaaaaaaaaaaaaaaa');
        Assert::same($batch->transactions[0]->name, 'GET /');
        Assert::same($batch->transactions[0]->op, 'http.server');
        Assert::count($batch->spans, 1);
        Assert::same($batch->spans[0]->description, 'gen_ai.chat');
        Assert::same($batch->spans[0]->transactionId, null);
        Assert::true($batch->spans[0]->isError());
    }

    public function testReadsSingleSpanItem(): void
    {
        $batch = new Batch('default');

        ItemReader::read($batch, 'span', ['trace_id' => self::TRACE, 'span_id' => 'aaaaaaaaaaaaaaaa', 'parent_span_id' => 'b']);

        Assert::count($batch->spans, 1);
    }

    public function testReadsLogItems(): void
    {
        $batch = new Batch('default');

        ItemReader::read($batch, 'log', ['items' => [
            [
                'timestamp' => 1795000000.25,
                'trace_id' => self::TRACE,
                'level' => 'warn',
                'body' => 'Slow',
                'severity_number' => 13,
                'attributes' => ['server.address' => ['type' => 'string', 'value' => 'web-1']],
            ],
            ['body' => ['not' => 'a string']],
            'not a record',
        ]]);

        Assert::count($batch->logs, 2);
        Assert::same($batch->logs[0]->level, 'warn');
        Assert::same($batch->logs[0]->severityNumber, 13);
        Assert::same($batch->logs[0]->service, 'web-1');
        Assert::same($batch->logs[0]->time, 1795000000.25);
        Assert::same($batch->logs[0]->attributes, ['server.address' => ['type' => 'string', 'value' => 'web-1']]);
        Assert::same($batch->logs[1]->level, 'info');
        Assert::same($batch->logs[1]->body, '{"not":"a string"}');
        Assert::same($batch->kinds(), ['log']);
    }

    public function testReadsTraceMetricItemsFromBareList(): void
    {
        $batch = new Batch('default');

        ItemReader::read($batch, 'trace_metric', [
            ['name' => 'orders', 'type' => 'counter', 'value' => 2, 'unit' => 'none', 'span_id' => 'aaaaaaaaaaaaaaaa'],
            ['name' => 'no value'],
            ['value' => 1],
        ]);

        Assert::count($batch->metrics, 1);
        Assert::same($batch->metrics[0]->name, 'orders');
        Assert::same($batch->metrics[0]->value, 2.0);
        Assert::same($batch->metrics[0]->spanId, 'aaaaaaaaaaaaaaaa');
    }

    public function testOtherItemsAreNotTelemetry(): void
    {
        $batch = new Batch('default');

        Assert::false(ItemReader::read($batch, 'event', ['message' => 'x']));
        Assert::true(ItemReader::read($batch, 'log', 'not json'));
        Assert::same($batch->kinds(), []);
    }

    public function testTellsTransactionFromError(): void
    {
        Assert::true(ItemReader::isTransaction(['type' => 'transaction']));
        Assert::true(ItemReader::isTransaction(['spans' => [], 'start_timestamp' => 1]));
        Assert::false(ItemReader::isTransaction([
            'spans' => [],
            'start_timestamp' => 1,
            'exception' => [['type' => 'E']],
        ]));
        Assert::false(ItemReader::isTransaction(['message' => 'x']));
    }

    public function testReadsTimes(): void
    {
        Assert::same(ItemReader::time(12), 12.0);
        Assert::same(ItemReader::time('12.5'), 12.5);
        Assert::same(ItemReader::time('1970-01-01T00:00:10.25Z'), 10.25);
        Assert::null(ItemReader::time('soon'));
        Assert::null(ItemReader::time(null));
    }
}
