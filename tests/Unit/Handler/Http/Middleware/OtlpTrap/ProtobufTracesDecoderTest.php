<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Handler\Http\Middleware\OtlpTrap;

use Buggregator\Trap\Handler\Http\Middleware\OtlpTrap\JsonTracesDecoder;
use Buggregator\Trap\Handler\Http\Middleware\OtlpTrap\ProtobufTracesDecoder;
use Buggregator\Trap\Handler\Http\Middleware\OtlpTrap\LimitExceededException;
use Buggregator\Trap\Info;
use Buggregator\Trap\Proto\Frame\Otlp\Trace\SpanKind;
use Buggregator\Trap\Proto\Frame\Otlp\Trace\StatusCode;
use Opentelemetry\Proto\Collector\Trace\V1\ExportTraceServiceRequest;
use Opentelemetry\Proto\Common\V1\AnyValue;
use Opentelemetry\Proto\Common\V1\KeyValue;
use Opentelemetry\Proto\Common\V1\KeyValueList;
use Opentelemetry\Proto\Trace\V1\ResourceSpans;
use Opentelemetry\Proto\Trace\V1\ScopeSpans;
use Opentelemetry\Proto\Trace\V1\Span;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProtobufTracesDecoder::class)]
final class ProtobufTracesDecoderTest extends TestCase
{
    public function testDecodesFixtureFromOpenTelemetryPhpExporter(): void
    {
        $payload = \file_get_contents(Info::TRAP_ROOT . '/tests/Stub/otlp-traces.pb');

        $frame = (new ProtobufTracesDecoder())->decode($payload, new \DateTimeImmutable());

        self::assertSame(4, $frame->countSpans());
        $resource = $frame->resourceSpans[0];
        self::assertSame('e2e-app', $resource->getServiceName());
        self::assertSame('https://opentelemetry.io/schemas/1.38.0', $resource->schemaUrl);
        self::assertSame('e2e.scope', $resource->scopeSpans[0]->scopeName);
        self::assertSame('1.2.3', $resource->scopeSpans[0]->scopeVersion);

        $spans = [];
        foreach ($frame->iterateSpans() as [, , $span]) {
            $spans[$span->name] = $span;
        }

        $root = $spans['GET /orders'];
        self::assertSame('4ab192eff5d209acee56f11cd2bf1937', $root->traceId);
        self::assertSame('17cf00fe7807d9cf', $root->spanId);
        self::assertNull($root->parentSpanId);
        self::assertSame(SpanKind::Server, $root->kind);
        self::assertSame(StatusCode::Ok, $root->status->code);
        self::assertSame([
            'http.request.method' => 'GET',
            'http.response.status_code' => 200,
            'server.ratio' => 0.75,
            'feature.enabled' => true,
            'order.ids' => [1, 2, 3],
        ], $root->attributes);

        $db = $spans['db.query'];
        self::assertSame('17cf00fe7807d9cf', $db->parentSpanId);
        self::assertSame(SpanKind::Client, $db->kind);
        self::assertSame(StatusCode::Error, $db->status->code);
        self::assertSame('Connection lost', $db->status->message);
        self::assertSame('RuntimeException', $db->events[0]->attributes['exception.type']);
        self::assertSame($spans['render']->spanId, $spans['template.load']->parentSpanId);
    }

    public function testDecodesNestedAndBinaryValues(): void
    {
        $span = (new Span())
            ->setTraceId(\hex2bin('5b8efff798038103d269b633813fc60c'))
            ->setSpanId(\hex2bin('eee19b7ec3c1b174'))
            ->setName('span')
            ->setStartTimeUnixNano(1_700_000_000_000_000_000)
            ->setEndTimeUnixNano(1_700_000_000_000_000_500)
            ->setAttributes([
                (new KeyValue())->setKey('bytes')->setValue((new AnyValue())->setBytesValue("\x01\x02\x03")),
                (new KeyValue())->setKey('kvlist')->setValue((new AnyValue())->setKvlistValue(
                    (new KeyValueList())->setValues([
                        (new KeyValue())->setKey('deep')->setValue((new AnyValue())->setDoubleValue(1.5)),
                    ]),
                )),
            ]);
        $request = (new ExportTraceServiceRequest())->setResourceSpans([
            (new ResourceSpans())->setScopeSpans([(new ScopeSpans())->setSpans([$span])]),
        ]);

        $frame = (new ProtobufTracesDecoder())->decode($request->serializeToString(), new \DateTimeImmutable());
        $decoded = $frame->resourceSpans[0]->scopeSpans[0]->spans[0];

        self::assertSame('5b8efff798038103d269b633813fc60c', $decoded->traceId);
        self::assertSame('eee19b7ec3c1b174', $decoded->spanId);
        self::assertSame(SpanKind::Unspecified, $decoded->kind);
        self::assertSame(500, $decoded->getDuration());
        self::assertEquals(['bytes' => 'AQID', 'kvlist' => new \ArrayObject(['deep' => 1.5])], $decoded->attributes);
    }

    public function testCountsSpansOfAllScopesAgainstLimit(): void
    {
        $span = static fn(int $id): Span => (new Span())
            ->setTraceId(\hex2bin('5b8efff798038103d269b633813fc60c'))
            ->setSpanId(\pack('J', $id))
            ->setAttributes([
                (new KeyValue())->setKey('negative')->setValue((new AnyValue())->setIntValue(-1)),
            ]);
        $scope = static fn(int $id): ScopeSpans => (new ScopeSpans())->setSpans([$span($id), $span($id + 1)]);
        $payload = (new ExportTraceServiceRequest())->setResourceSpans([
            (new ResourceSpans())->setScopeSpans([$scope(1), $scope(3)]),
            (new ResourceSpans())->setScopeSpans([$scope(5), $scope(7)]),
        ])->serializeToString();

        $frame = (new ProtobufTracesDecoder(new JsonTracesDecoder(8)))->decode($payload, new \DateTimeImmutable());
        self::assertSame(8, $frame->countSpans());

        $this->expectException(LimitExceededException::class);
        (new ProtobufTracesDecoder(new JsonTracesDecoder(7)))->decode($payload, new \DateTimeImmutable());
    }

    public function testRejectsMalformedMessage(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ProtobufTracesDecoder())->decode("\x0a\xff\xff\xff", new \DateTimeImmutable());
    }

    public function testSuccessResponseIsEmptyProtobufMessage(): void
    {
        $response = (new ProtobufTracesDecoder())->createSuccessResponse();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/x-protobuf', $response->getHeaderLine('Content-Type'));
        self::assertSame('', (string) $response->getBody());
    }
}
