<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Console;

use Buggregator\Trap\Module\Console\Renderer;
use Buggregator\Trap\Module\HttpDump\Console\Renderer as HttpDumpRenderer;
use Buggregator\Trap\Module\HttpDump\Frame as HttpFrame;
use Buggregator\Trap\Module\Mailer\Console\Renderer as MailerRenderer;
use Buggregator\Trap\Module\Mailer\Frame as MailerFrame;
use Buggregator\Trap\Module\Mailer\Message as MailerMessage;
use Buggregator\Trap\Module\Monolog\Console\Renderer as MonologRenderer;
use Buggregator\Trap\Module\Monolog\Frame as MonologFrame;
use Buggregator\Trap\Module\Profiler\Console\Renderer as ProfilerRenderer;
use Buggregator\Trap\Module\Profiler\Frame as ProfilerFrame;
use Buggregator\Trap\Module\Profiler\Frame\Payload;
use Buggregator\Trap\Module\Profiler\Frame\Type as PayloadType;
use Buggregator\Trap\Module\Profiler\Struct\Profile;
use Buggregator\Trap\Module\Ray\Console\Renderer as RayRenderer;
use Buggregator\Trap\Module\Ray\Frame as RayFrame;
use Buggregator\Trap\Module\Sentry\Console\SentryEnvelope as SentryEnvelopeRenderer;
use Buggregator\Trap\Module\Sentry\Console\SentryStore as SentryStoreRenderer;
use Buggregator\Trap\Module\Sentry\Frame\SentryEnvelope;
use Buggregator\Trap\Module\Sentry\Frame\SentryStore;
use Buggregator\Trap\Module\Tracing\Console\Renderer as TracingRenderer;
use Buggregator\Trap\Module\Tracing\Frame\OtlpTraces;
use Buggregator\Trap\Module\Tracing\SpanStore;
use Buggregator\Trap\Module\VarDumper\Console\Renderer as VarDumperRenderer;
use Buggregator\Trap\Module\VarDumper\Frame as VarDumperFrame;
use Buggregator\Trap\Server\Proto\Frame;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Testo\Assert;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * Every console renderer picks the frames of its own kind and nothing else.
 */
#[Test]
final class RendererSupportTest
{
    public static function renderersDataProvider(): iterable
    {
        yield 'var-dumper' => [new VarDumperRenderer(), ['var-dumper']];
        yield 'sentry store' => [new SentryStoreRenderer(), ['sentry store']];
        yield 'sentry envelope' => [new SentryEnvelopeRenderer(), ['sentry envelope']];
        yield 'otlp' => [new TracingRenderer(new SpanStore()), ['otlp']];
        yield 'monolog' => [new MonologRenderer(), ['monolog']];
        yield 'smtp' => [new MailerRenderer(), ['smtp']];
        yield 'ray' => [new RayRenderer(), ['ray']];
        yield 'http' => [new HttpDumpRenderer(), ['http']];
        yield 'profiler' => [new ProfilerRenderer(), ['profiler']];
        yield 'binary' => [new Renderer\Binary(), ['binary']];
    }

    /**
     * @param list<non-empty-string> $expected Keys of {@see self::frames()} the renderer supports.
     */
    #[DataProvider('renderersDataProvider')]
    public function testSupportsOnlyItsFrames(Renderer $renderer, array $expected): void
    {
        $supported = \array_keys(\array_filter(
            self::frames(),
            static fn(Frame $frame): bool => $renderer->isSupport($frame),
        ));

        Assert::same($supported, $expected);
    }

    public function testPlainSupportsEveryFrame(): void
    {
        foreach (self::frames() as $frame) {
            Assert::true((new Renderer\Plain())->isSupport($frame));
        }
    }

    /**
     * @return array<non-empty-string, Frame>
     */
    private static function frames(): array
    {
        return [
            'var-dumper' => new VarDumperFrame('dump'),
            'sentry store' => new SentryStore(['type' => 'store', 'event_id' => 'abc', 'timestamp' => 1700000000]),
            'sentry envelope' => new SentryEnvelope([], []),
            'otlp' => new OtlpTraces([]),
            'monolog' => new MonologFrame(['message' => 'hello']),
            'smtp' => new MailerFrame(MailerMessage::create(['FROM' => ['<a@b.c>']], ['Subject' => 'Hi'])),
            'ray' => new RayFrame('a1', []),
            'http' => new HttpFrame(new ServerRequest('GET', 'http://localhost/')),
            'profiler' => new ProfilerFrame(Payload::new(PayloadType::XHProf, static fn(): Profile => new Profile())),
            'binary' => new Frame\Binary(Stream::create('binary')),
        ];
    }
}
