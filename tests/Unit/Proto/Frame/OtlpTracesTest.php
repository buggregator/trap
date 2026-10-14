<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Proto\Frame;

use Buggregator\Trap\Handler\Http\Middleware\OtlpTrap\JsonTracesDecoder;
use Buggregator\Trap\Info;
use Buggregator\Trap\Proto\Frame\Otlp;
use Buggregator\Trap\Proto\Frame\Otlp\OtlpTraces;
use Buggregator\Trap\Proto\Server\Version\V1;
use Buggregator\Trap\ProtoType;
use Buggregator\Trap\Sender\EventsToFileSender;
use Buggregator\Trap\Support\Json;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OtlpTraces::class)]
#[CoversClass(Otlp::class)]
final class OtlpTracesTest extends TestCase
{
    private ?string $runtimeDir = null;

    public function testStringRoundtrip(): void
    {
        $frame = $this->fixtureFrame();

        $restored = Otlp::fromString((string) $frame, $frame->time);

        self::assertInstanceOf(OtlpTraces::class, $restored);
        self::assertSame(ProtoType::Otlp, $restored->type);
        self::assertEquals($frame, $restored);
        self::assertSame((string) $frame, (string) $restored);
    }

    public function testSerializedForm(): void
    {
        $data = Json::decode((string) $this->fixtureFrame());

        self::assertSame('otlp.traces', $data['type']);
        $resource = $data['resourceSpans'][0];
        self::assertSame('e2e-app', $resource['resource']['attributes']['service.name']);
        self::assertSame('e2e.scope', $resource['scopeSpans'][0]['scope']['name']);
        self::assertSame([], $resource['scopeSpans'][0]['scope']['attributes']);

        $span = $resource['scopeSpans'][0]['spans'][3];
        self::assertSame('GET /orders', $span['name']);
        self::assertSame(2, $span['kind']);
        self::assertNull($span['parentSpanId']);
        self::assertSame(['code' => 1, 'message' => ''], $span['status']);
        self::assertSame([1, 2, 3], $span['attributes']['order.ids']);
        self::assertIsInt($span['startTimeUnixNano']);
    }

    public function testEmptyAttributeMapsAreJsonObjects(): void
    {
        $json = (string) $this->fixtureFrame();

        self::assertStringContainsString('"scope":{"name":"e2e.scope","version":"1.2.3","attributes":{}', $json);
        self::assertStringNotContainsString('"attributes":[]', $json);
    }

    public function testRoundtripThroughRemoteProtocol(): void
    {
        $frame = $this->fixtureFrame();
        $payload = '1|1.16.1|' . '0f8fad5b-d9cb-469f-a165-70867728950e|[' . Json::encode($frame) . ']';

        $frames = [...(new V1())->decode($payload)->getParsedPayload()];

        self::assertCount(1, $frames);
        self::assertInstanceOf(OtlpTraces::class, $frames[0]);
        self::assertSame((string) $frame, (string) $frames[0]);
        self::assertEquals($frame->time, $frames[0]->time);
    }

    public function testFileSenderWritesOneReadableLinePerFrame(): void
    {
        $this->runtimeDir = Info::TRAP_ROOT . '/runtime/tests/otlp-file-sender';
        $frame = $this->fixtureFrame();

        (new EventsToFileSender($this->runtimeDir))->send([$frame, $frame]);

        $files = \glob($this->runtimeDir . '/dump-*.log');
        self::assertCount(1, $files);
        $lines = \file($files[0], \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        self::assertCount(2, $lines);
        foreach ($lines as $line) {
            $restored = Otlp::fromString($line, $frame->time);
            self::assertInstanceOf(OtlpTraces::class, $restored);
            self::assertSame(4, $restored->countSpans());
        }
    }

    public function testFromStringMustBeCalledOnBaseClass(): void
    {
        $this->expectException(\LogicException::class);

        OtlpTraces::fromString((string) $this->fixtureFrame(), new \DateTimeImmutable());
    }

    public function testFromStringRejectsUnknownSignal(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Otlp::fromString('{"type":"otlp.profiles"}', new \DateTimeImmutable());
    }

    protected function tearDown(): void
    {
        if ($this->runtimeDir !== null) {
            \array_map('unlink', \glob($this->runtimeDir . '/*'));
            \rmdir($this->runtimeDir);
        }
    }

    private function fixtureFrame(): OtlpTraces
    {
        return (new JsonTracesDecoder())->decode(
            \file_get_contents(Info::TRAP_ROOT . '/tests/Stub/otlp-traces.json'),
            new \DateTimeImmutable('2024-01-01 12:00:00.123456'),
        );
    }
}
