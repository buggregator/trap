<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Sender\Console\Renderer;

use Buggregator\Trap\Proto\Frame\Monolog;
use Buggregator\Trap\Proto\Frame\Sentry\EnvelopeItem;
use Buggregator\Trap\Proto\Frame\Sentry\SentryEnvelope as SentryEnvelopeFrame;
use Buggregator\Trap\Sender\Console\Renderer\SentryEnvelope;
use Nyholm\Psr7\Stream;
use Symfony\Component\Console\Output\BufferedOutput;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Covers(SentryEnvelope::class)]
#[Test]
final class SentryEnvelopeTest
{
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
