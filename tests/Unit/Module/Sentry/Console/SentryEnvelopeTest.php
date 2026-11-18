<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Sentry\Console;

use Buggregator\Trap\Module\Monolog\Frame as Monolog;
use Buggregator\Trap\Module\Sentry\Console\SentryEnvelope;
use Buggregator\Trap\Module\Sentry\Frame\EnvelopeItem;
use Buggregator\Trap\Module\Sentry\Frame\SentryEnvelope as SentryEnvelopeFrame;
use Nyholm\Psr7\Stream;
use Symfony\Component\Console\Output\BufferedOutput;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

#[Covers(SentryEnvelope::class)]
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

    private function render(SentryEnvelopeFrame $frame): string
    {
        $output = new BufferedOutput();
        (new SentryEnvelope())->render($output, $frame);

        return \str_replace("\r\n", "\n", $output->fetch());
    }
}
