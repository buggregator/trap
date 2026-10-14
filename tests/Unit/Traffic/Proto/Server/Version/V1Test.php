<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Traffic\Proto\Server\Version;

use Buggregator\Trap\Module\Profiler\Struct\Profile;
use Buggregator\Trap\Proto\Frame;
use Buggregator\Trap\Proto\Frame\Otlp\OtlpTraces;
use Buggregator\Trap\Proto\Frame\Profiler\Payload;
use Buggregator\Trap\Proto\Frame\Profiler\Type as PayloadType;
use Buggregator\Trap\Proto\Frame\Sentry\SentryStore;
use Buggregator\Trap\Proto\Server\Version\V1;
use Buggregator\Trap\Traffic\Message\Smtp as SmtpMessage;
use Nyholm\Psr7\ServerRequest;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(V1::class)]
final class V1Test
{
    private const UUID = '9a06581c-3bec-4cf1-8412-b2a40bb36a76';
    private const TIME = '2024-01-02 03:04:05.123456';

    public static function payloadsDataProvider(): iterable
    {
        yield ['1|0.1|9a06581c-3bec-4cf1-8412-b2a40bb36a76|[{"hello":"world"},{}]', true];
        yield ['1|0.1|9a06581c-3bec-4cf1-8412-b2a40bb36a76|[]', true];
        yield ['1|0.1|9a06581c-3bec-4cf1-8412-b2a40bb36a76|', false];
        yield ['1|0.1|9a06581c-3bec-4cf1-8412-b2a40bb36a76', false];
        yield ['abc', false];
    }

    public static function framesDataProvider(): iterable
    {
        $time = new \DateTimeImmutable(self::TIME);

        yield 'smtp' => [new Frame\Smtp(SmtpMessage::create(['FROM' => ['<a@b.c>']], ['Subject' => 'Hi']), $time)];
        yield 'monolog' => [new Frame\Monolog(['message' => 'hello', 'level' => 200], $time)];
        yield 'var-dumper' => [new Frame\VarDumper('dump', $time)];
        yield 'http' => [new Frame\Http(new ServerRequest('POST', 'http://localhost/path', ['Host' => ['localhost']], 'body'), $time)];
        yield 'sentry' => [new SentryStore(['type' => 'store', 'event_id' => 'abc', 'timestamp' => 1700000000], $time)];
        yield 'profiler' => [new Frame\Profiler(
            Payload::new(PayloadType::XHProf, static fn(): Profile => new Profile(
                date: new \DateTimeImmutable('@1700000000'),
                metadata: ['app_name' => 'app', 'hostname' => 'host', 'filename' => 'file'],
                tags: ['env' => 'test'],
            )),
            $time,
        )];
        yield 'otlp' => [new OtlpTraces([], $time)];
    }

    #[DataProvider('payloadsDataProvider')]
    public function testIsSuppoerted(string $payload, bool $isSupported): void
    {
        $decoder = new V1();

        Assert::same($decoder->isSupport($payload), $isSupported);
    }

    public function testDecode(): void
    {
        $decoder = new V1();

        $request = $decoder->decode('1|0.1|9a06581c-3bec-4cf1-8412-b2a40bb36a76|[{},{}]');

        Assert::same($request->protocol, 1);
        Assert::same($request->client, '0.1');
        Assert::same($request->uuid, '9a06581c-3bec-4cf1-8412-b2a40bb36a76');
        Assert::same($request->payload, '[{},{}]');
    }

    #[DataSet(['1|0.1[]', 'Invalid data.'], 'header without uuid')]
    #[DataSet(['v1|0.1|9a06581c-3bec-4cf1-8412-b2a40bb36a76|[]', 'Invalid protocol.'], 'non-numeric protocol')]
    #[DataSet(['1|0.1|not-a-uuid|[]', 'Invalid UUID.'], 'malformed uuid')]
    public function testDecodeRejectsInvalidHeader(string $payload, string $message): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage($message);

        (new V1())->decode($payload);
    }

    #[DataProvider('framesDataProvider')]
    public function testParsedPayloadRestoresFrame(Frame $frame): void
    {
        $request = (new V1())->decode('1|0.1|' . self::UUID . '|' . \json_encode([$frame], \JSON_THROW_ON_ERROR));

        $frames = $request->getParsedPayload();

        Assert::count($frames, 1);
        Assert::instanceOf($frames[0], $frame::class);
        Assert::same($frames[0]->type, $frame->type);
        Assert::same($frames[0]->time->format('Y-m-d H:i:s.u'), self::TIME);
        Assert::same((string) $frames[0], (string) $frame);
    }

    public function testParsedPayloadKeepsFrameOrder(): void
    {
        $time = new \DateTimeImmutable(self::TIME);
        $payload = \json_encode([new Frame\VarDumper('first', $time), new Frame\VarDumper('second', $time)]);
        $request = (new V1())->decode('1|0.1|' . self::UUID . '|' . $payload);

        $frames = $request->getParsedPayload();

        Assert::same(\array_map(static fn(Frame\VarDumper $frame): string => $frame->dump, $frames), ['first', 'second']);
    }

    public function testParsedPayloadRejectsUnsupportedFrameType(): never
    {
        $payload = \json_encode([['type' => 'binary', 'data' => \base64_encode('x'), 'time' => self::TIME]]);
        $request = (new V1())->decode('1|0.1|' . self::UUID . '|' . $payload);

        Expect::exception(\RuntimeException::class)->withMessage('Invalid type.');

        $request->getParsedPayload();
    }

    public function testParsedPayloadRejectsMalformedJson(): never
    {
        $request = (new V1())->decode('1|0.1|' . self::UUID . '|[{');

        Expect::exception(\JsonException::class);

        $request->getParsedPayload();
    }
}
