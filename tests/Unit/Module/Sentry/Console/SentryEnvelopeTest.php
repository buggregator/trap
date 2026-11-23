<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Sentry\Console;

use Buggregator\Trap\Module\Monolog\Frame as Monolog;
use Buggregator\Trap\Module\Sentry\Console\SentryEnvelope;
use Buggregator\Trap\Module\Sentry\Console\TraceLine;
use Buggregator\Trap\Module\Sentry\Frame\EnvelopeItem;
use Buggregator\Trap\Module\Sentry\Frame\SentryEnvelope as SentryEnvelopeFrame;
use Nyholm\Psr7\Stream;
use Symfony\Component\Console\Output\BufferedOutput;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

#[Covers(SentryEnvelope::class)]
#[Covers(TraceLine::class)]
#[Test]
final class SentryEnvelopeTest
{
    private const EXCEPTION = [
        'type' => 'RuntimeException',
        'value' => 'Boom',
        'stacktrace' => ['frames' => [['filename' => 'src/App.php', 'lineno' => 42, 'function' => 'run']]],
    ];

    public function testSupportsOnlySentryEnvelopes(): void
    {
        $renderer = new SentryEnvelope();

        Assert::true($renderer->isSupport(new SentryEnvelopeFrame([], [])));
        Assert::false($renderer->isSupport(new Monolog([])));
    }

    public function testRendersEventItem(): void
    {
        $output = $this->render(new SentryEnvelopeFrame(['event_id' => 'abc'], [
            new EnvelopeItem(['type' => 'event'], [
                'event_id' => 'abc',
                'server_name' => 'web-1',
                'level' => 'error',
                'sdk' => ['name' => 'sentry.php', 'version' => '4.0.0'],
            ]),
        ]));

        Assert::string($output)->contains('Item 1')->contains('event')->contains('web-1')->contains('sentry.php 4.0.0')->contains('There is no renderer for this item type.')->notContains('Render error');
    }

    #[DataSet([['values' => [self::EXCEPTION]]], 'values')]
    #[DataSet([[self::EXCEPTION]], 'list')]
    #[DataSet([self::EXCEPTION], 'single object')]
    public function testRendersEventException(array $exception): void
    {
        $output = $this->render(new SentryEnvelopeFrame(['event_id' => 'abc'], [
            new EnvelopeItem(['type' => 'event'], [
                'event_id' => 'abc',
                'level' => 'error',
                'exception' => $exception,
            ]),
        ]));

        Assert::string($output)->contains('Exceptions')->contains('RuntimeException')->contains('Boom')->contains('src/App.php')->contains(':42')->contains('run()')->notContains('There is no renderer for this item type.')->notContains('Render error');
    }

    #[DataSet([['type' => 'RuntimeException'], 'RuntimeException'], 'type only')]
    #[DataSet([['value' => 'Boom'], 'Boom'], 'value only')]
    #[DataSet([['value' => 'Boom', 'mechanism' => ['type' => 'generic', 'handled' => false]], 'Boom'], 'value with mechanism')]
    #[DataSet([['stacktrace' => ['frames' => [['filename' => 'src/App.php', 'lineno' => 42, 'function' => 'run']]]], 'src/App.php'], 'stacktrace only')]
    public function testRendersPartialSingleException(array $exception, string $expected): void
    {
        $output = $this->render(new SentryEnvelopeFrame(['event_id' => 'abc'], [
            new EnvelopeItem(['type' => 'event'], [
                'event_id' => 'abc',
                'exception' => $exception,
            ]),
        ]));

        Assert::string($output)->contains('Exceptions')->contains($expected)->notContains('generic')->notContains('There is no renderer for this item type.')->notContains('Render error');
    }

    #[DataSet([['values' => null]], 'null values')]
    #[DataSet([['values' => 'x']], 'string values')]
    #[DataSet([['values' => [1, 2]]], 'scalar values')]
    #[DataSet([['foo' => ['bar' => 1]]], 'unrelated object')]
    public function testIgnoresMalformedEventException(array $exception): void
    {
        $output = $this->render(new SentryEnvelopeFrame(['event_id' => 'abc'], [
            new EnvelopeItem(['type' => 'event'], [
                'event_id' => 'abc',
                'exception' => $exception,
            ]),
        ]));

        Assert::string($output)->contains('There is no renderer for this item type.')->notContains('Exceptions')->notContains('Render error');
    }

    public function testRendersTransactionAsTraceLine(): void
    {
        $output = $this->render(new SentryEnvelopeFrame(['event_id' => 'abc'], [
            new EnvelopeItem(['type' => 'transaction'], [
                'transaction' => 'POST /checkout',
                'contexts' => ['trace' => ['trace_id' => 'aaaabbbbccccdddd', 'span_id' => 's0', 'op' => 'http.server']],
                'start_timestamp' => 100.0,
                'timestamp' => 100.25,
                'spans' => [
                    ['span_id' => 's1', 'op' => 'db', 'start_timestamp' => 100.0, 'timestamp' => 100.1],
                    ['span_id' => 's2', 'op' => 'http.client', 'status' => 'internal_error'],
                ],
            ]),
        ]));

        Assert::string($output)
            ->contains("Trace aaaabbbbccccdddd: POST /checkout (http.server), 250 ms, 3 spans, 1 failed\n")
            ->notContains('There is no renderer for this item type.');
    }

    public function testRendersSpanItemsByTheirRootSpan(): void
    {
        $output = $this->render(new SentryEnvelopeFrame([], [
            new EnvelopeItem(['type' => 'span'], ['items' => [
                ['trace_id' => 'aaaabbbbccccdddd', 'span_id' => 's1', 'name' => 'query', 'op' => 'db', 'start_timestamp' => 10.0, 'end_timestamp' => 10.1],
                ['trace_id' => 'aaaabbbbccccdddd', 'span_id' => 's2', 'parent_span_id' => 's1', 'name' => 'fetch'],
            ]]),
        ]));

        Assert::string($output)->contains("Trace aaaabbbbccccdddd: query (db), 100 ms, 2 spans\n");
    }

    public function testRendersTransactionWithoutTraceIdAsItsLine(): void
    {
        $output = $this->render(new SentryEnvelopeFrame([], [
            new EnvelopeItem(['type' => 'transaction'], [
                'transaction' => 'POST /checkout',
                'op' => 'http.server',
                'start_timestamp' => 100.0,
                'timestamp' => 100.25,
                'spans' => [['span_id' => 's1'], ['op' => 'no ID']],
            ]),
        ]));

        Assert::string($output)
            ->contains("Transaction without a trace ID: POST /checkout (http.server), 250 ms, 2 spans\n")
            ->notContains('There is no renderer for this item type.');
    }

    public function testRendersAttachmentItemHeader(): void
    {
        $output = $this->render(new SentryEnvelopeFrame(['event_id' => 'abc'], [
            new EnvelopeItem(['type' => 'event'], ['event_id' => 'abc']),
            new EnvelopeItem(
                ['type' => 'attachment', 'length' => 11, 'filename' => 'report.txt', 'content_type' => 'text/plain'],
                Stream::create('hello world'),
            ),
        ]));

        Assert::string($output)->contains('Item 2')->contains('attachment')->contains('report.txt')->contains('text/plain')->contains('11')->notContains('Render error');
    }

    public function testRendersUserFeedback(): void
    {
        $output = $this->render(new SentryEnvelopeFrame([], [
            new EnvelopeItem(['type' => 'feedback'], ['contexts' => ['feedback' => [
                'message' => 'Checkout <broke>',
                'name' => 'Ann',
                'contact_email' => 'ann@example.com',
                'url' => 'https://shop.test/cart',
                'associated_event_id' => 'e1e1',
            ]]]),
            new EnvelopeItem(['type' => 'user_report'], ['comments' => 'Hi']),
        ]));

        Assert::string($output)
            ->contains("User feedback from Ann <ann@example.com>\nOn https://shop.test/cart\nAbout the error e1e1\n\nCheckout <broke>\n")
            ->contains("User feedback from anonymous\nSent without an error\n\nHi\n")
            ->notContains('There is no renderer for this item type.');
    }

    private function render(SentryEnvelopeFrame $frame): string
    {
        $output = new BufferedOutput();
        (new SentryEnvelope())->render($output, $frame);

        return \str_replace("\r\n", "\n", $output->fetch());
    }
}
