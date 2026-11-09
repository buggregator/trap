<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Proto;

use Buggregator\Trap\Module\Mailer\Frame as MailerFrame;
use Buggregator\Trap\Module\Mailer\Message as MailerMessage;
use Buggregator\Trap\Module\Monolog\Frame as MonologFrame;
use Buggregator\Trap\Module\Profiler\Frame as ProfilerFrame;
use Buggregator\Trap\Module\Profiler\Frame\Payload;
use Buggregator\Trap\Module\Profiler\Frame\Type as PayloadType;
use Buggregator\Trap\Module\Profiler\Struct\Profile;
use Buggregator\Trap\Module\Sentry\Frame as SentryFrame;
use Buggregator\Trap\Module\Sentry\Frame\SentryEnvelope;
use Buggregator\Trap\Module\Sentry\Frame\SentryStore;
use Buggregator\Trap\Module\Tracing\Frame as TracingFrame;
use Buggregator\Trap\Module\Tracing\Frame\OtlpTraces;
use Buggregator\Trap\Module\VarDumper\Frame as VarDumperFrame;
use Buggregator\Trap\Proto\Frame;
use Buggregator\Trap\Proto\FrameTypes;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(FrameTypes::class)]
final class FrameTypesTest
{
    /**
     * The type strings are part of the wire and JSON formats, so they are spelled out here.
     */
    public static function framesDataProvider(): iterable
    {
        yield [new VarDumperFrame('dump'), 'var-dumper'];
        yield [new Frame\Http(new ServerRequest('GET', 'http://localhost/')), 'http'];
        yield [new MailerFrame(MailerMessage::create([], [])), 'smtp'];
        yield [new MonologFrame([]), 'monolog'];
        yield [new Frame\Binary(Stream::create('binary')), 'binary'];
        yield [new SentryStore(['type' => 'store', 'event_id' => 'abc', 'timestamp' => 1700000000]), 'sentry'];
        yield [new SentryEnvelope([], []), 'sentry'];
        yield [new ProfilerFrame(Payload::new(PayloadType::XHProf, static fn(): Profile => new Profile())), 'profiler'];
        yield [new OtlpTraces([]), 'otlp'];
    }

    #[DataProvider('framesDataProvider')]
    public function testFrameKeepsItsType(Frame $frame, string $type): void
    {
        Assert::same($frame->type, $type);
        Assert::same($frame::TYPE, $type);
        Assert::same(\json_decode(\json_encode($frame, \JSON_THROW_ON_ERROR), true)['type'], $type);
    }

    public function testMapsDecodableTypesToFrameClasses(): void
    {
        $types = new FrameTypes();

        Assert::same($types->classOf('smtp'), MailerFrame::class);
        Assert::same($types->classOf('monolog'), MonologFrame::class);
        Assert::same($types->classOf('var-dumper'), VarDumperFrame::class);
        Assert::same($types->classOf('http'), Frame\Http::class);
        Assert::same($types->classOf('sentry'), SentryFrame::class);
        Assert::same($types->classOf('profiler'), ProfilerFrame::class);
        Assert::same($types->classOf('otlp'), TracingFrame::class);
    }

    public function testDoesNotDecodeBinaryFrames(): void
    {
        Assert::null((new FrameTypes())->classOf('binary'));
    }

    public function testReturnsNullForUnknownType(): void
    {
        Assert::null((new FrameTypes())->classOf('unknown'));
    }

    public function testUsesGivenMap(): void
    {
        $types = new FrameTypes(['dump' => VarDumperFrame::class]);

        Assert::same($types->classOf('dump'), VarDumperFrame::class);
        Assert::null($types->classOf('var-dumper'));
    }
}
