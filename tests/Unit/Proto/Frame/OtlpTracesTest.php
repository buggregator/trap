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
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Test;

#[Covers(OtlpTraces::class)]
#[Covers(Otlp::class)]
#[Test]
final class OtlpTracesTest
{
    private ?string $runtimeDir = null;

    public function testStringRoundtrip(): void
    {
        $frame = $this->fixtureFrame();

        $restored = Otlp::fromString((string) $frame, $frame->time);

        Assert::instanceOf($restored, OtlpTraces::class);
        Assert::same($restored->type, ProtoType::Otlp);
        Assert::equals($restored, $frame);
        Assert::same((string) $restored, (string) $frame);
    }

    public function testSerializedForm(): void
    {
        $data = Json::decode((string) $this->fixtureFrame());

        Assert::same($data['type'], 'otlp.traces');
        $resource = $data['resourceSpans'][0];
        Assert::same($resource['resource']['attributes']['service.name'], 'e2e-app');
        Assert::same($resource['scopeSpans'][0]['scope']['name'], 'e2e.scope');
        Assert::same($resource['scopeSpans'][0]['scope']['attributes'], []);

        $span = $resource['scopeSpans'][0]['spans'][3];
        Assert::same($span['name'], 'GET /orders');
        Assert::same($span['kind'], 2);
        Assert::null($span['parentSpanId']);
        Assert::same($span['status'], ['code' => 1, 'message' => '']);
        Assert::same($span['attributes']['order.ids'], [1, 2, 3]);
        Assert::int($span['startTimeUnixNano']);
    }

    public function testEmptyAttributeMapsAreJsonObjects(): void
    {
        $json = (string) $this->fixtureFrame();

        Assert::string($json)->contains('"scope":{"name":"e2e.scope","version":"1.2.3","attributes":{}')->notContains('"attributes":[]');
    }

    public function testRoundtripThroughRemoteProtocol(): void
    {
        $frame = $this->fixtureFrame();
        $payload = '1|1.16.1|' . '0f8fad5b-d9cb-469f-a165-70867728950e|[' . Json::encode($frame) . ']';

        $frames = [...(new V1())->decode($payload)->getParsedPayload()];

        Assert::count($frames, 1);
        Assert::instanceOf($frames[0], OtlpTraces::class);
        Assert::same((string) $frames[0], (string) $frame);
        Assert::equals($frames[0]->time, $frame->time);
    }

    public function testFileSenderWritesOneReadableLinePerFrame(): void
    {
        $this->runtimeDir = Info::TRAP_ROOT . '/runtime/tests/otlp-file-sender';
        $frame = $this->fixtureFrame();

        (new EventsToFileSender($this->runtimeDir))->send([$frame, $frame]);

        $files = \glob($this->runtimeDir . '/dump-*.log');
        Assert::count($files, 1);
        $lines = \file($files[0], \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        Assert::count($lines, 2);
        foreach ($lines as $line) {
            $restored = Otlp::fromString($line, $frame->time);
            Assert::instanceOf($restored, OtlpTraces::class);
            Assert::same($restored->countSpans(), 4);
        }
    }

    #[ExpectException(\LogicException::class)]
    public function testFromStringMustBeCalledOnBaseClass(): void
    {
        OtlpTraces::fromString((string) $this->fixtureFrame(), new \DateTimeImmutable());
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function testFromStringRejectsUnknownSignal(): void
    {
        Otlp::fromString('{"type":"otlp.profiles"}', new \DateTimeImmutable());
    }

    #[AfterTest]
    protected function tearDown(): void
    {
        if ($this->runtimeDir !== null) {
            \array_map('unlink', \glob($this->runtimeDir . '/*'));
            \rmdir($this->runtimeDir);
            $this->runtimeDir = null;
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
