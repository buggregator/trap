<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Handler\Http\Middleware\OtlpTrap;

use Buggregator\Trap\Handler\Http\Middleware\OtlpTrap\JsonTracesDecoder;
use Buggregator\Trap\Info;
use Buggregator\Trap\Proto\Frame\Otlp\Trace\Span;
use Buggregator\Trap\Proto\Frame\Otlp\Trace\SpanKind;
use Buggregator\Trap\Proto\Frame\Otlp\Trace\StatusCode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(JsonTracesDecoder::class)]
final class JsonTracesDecoderTest extends TestCase
{
    private const TRACE_ID = '5b8efff798038103d269b633813fc60c';
    private const SPAN_ID = 'eee19b7ec3c1b174';

    public static function provideMalformedPayloads(): \Generator
    {
        yield 'not a JSON' => ['{"resourceSpans": ['];
        yield 'JSON scalar' => ['"text"'];
        yield 'resourceSpans is an object' => ['{"resourceSpans": {"a": 1}}'];
        yield 'span is a list' => [self::request([[1, 2]])];
        yield 'missing traceId' => [self::request([['spanId' => self::SPAN_ID]])];
        yield 'missing spanId' => [self::request([['traceId' => self::TRACE_ID]])];
        yield 'short traceId' => [self::request([['traceId' => 'abc', 'spanId' => self::SPAN_ID]])];
        yield 'invalid timestamp' => [self::request([self::span(['startTimeUnixNano' => 'yesterday'])])];
        yield 'name is a number' => [self::request([self::span(['name' => 42])])];
        yield 'attribute without key' => [self::request([self::span(['attributes' => [['value' => []]]])])];
        yield 'boolValue is a string' => [
            self::request([self::span(['attributes' => [['key' => 'k', 'value' => ['boolValue' => 'yes']]]])]),
        ];
        yield 'doubleValue is a string' => [
            self::request([self::span(['attributes' => [['key' => 'k', 'value' => ['doubleValue' => 'many']]]])]),
        ];
        yield 'negative timestamp' => [self::request([self::span(['startTimeUnixNano' => '-1'])])];
        yield 'negative number timestamp' => [self::request([self::span(['endTimeUnixNano' => -5])])];
        yield 'negative event timestamp' => [self::request([self::span(['events' => [['timeUnixNano' => -1]]])])];
        yield 'fractional timestamp' => [self::request([self::span(['startTimeUnixNano' => 1.5])])];
        yield 'intValue above int64' => [
            self::request([self::span(['attributes' => [['key' => 'k', 'value' => ['intValue' => '9223372036854775808']]]])]),
        ];
        yield 'intValue below int64' => [
            self::request([self::span(['attributes' => [['key' => 'k', 'value' => ['intValue' => -1e19]]]])]),
        ];
        yield 'negative flags' => [self::request([self::span(['flags' => -1])])];
        yield 'flags above uint32' => [self::request([self::span(['flags' => 4294967296])])];
        yield 'negative dropped count' => [self::request([self::span(['droppedEventsCount' => '-3'])])];
    }

    public static function provideClampedTimestamps(): \Generator
    {
        yield 'uint64 max as string' => ['"18446744073709551615"'];
        yield 'above int64 as string' => ['"9223372036854775808"'];
        yield 'above int64 as big JSON integer' => ['99999999999999999999'];
        yield 'above int64 as JSON float' => ['1e20'];
    }

    #[DataProvider('provideClampedTimestamps')]
    public function testClampsTimestampsAboveInt64(string $json): void
    {
        $payload = \str_replace('"END"', $json, self::request([self::span(['endTimeUnixNano' => 'END'])]));

        $frame = (new JsonTracesDecoder())->decode($payload, new \DateTimeImmutable());
        $span = $this->singleSpan($frame->resourceSpans[0]->scopeSpans[0]->spans);

        self::assertSame(\PHP_INT_MAX, $span->endTimeUnixNano);
        self::assertSame(\PHP_INT_MAX - 1700000000000000000, $span->getDuration());
    }

    public function testKeepsNonFiniteDoublesAsNames(): void
    {
        $payload = \strtr(self::request([self::span(['attributes' => [
            ['key' => 'big', 'value' => ['doubleValue' => 'BIG']],
            ['key' => 'big.string', 'value' => ['doubleValue' => '1e400']],
            ['key' => 'small', 'value' => ['doubleValue' => 'SMALL']],
            ['key' => 'nan', 'value' => ['doubleValue' => 'NaN']],
            ['key' => 'inf', 'value' => ['doubleValue' => '-Infinity']],
        ]])]), ['"BIG"' => '1e400', '"SMALL"' => '-1e400']);

        $frame = (new JsonTracesDecoder())->decode($payload, new \DateTimeImmutable());

        self::assertSame(
            ['big' => 'Infinity', 'big.string' => 'Infinity', 'small' => '-Infinity', 'nan' => 'NaN', 'inf' => '-Infinity'],
            $this->singleSpan($frame->resourceSpans[0]->scopeSpans[0]->spans)->attributes,
        );
        self::assertStringContainsString('"big":"Infinity"', (string) $frame);
    }

    public function testAcceptsInt64Bounds(): void
    {
        $payload = self::request([self::span(['attributes' => [
            ['key' => 'min', 'value' => ['intValue' => '-9223372036854775808']],
            ['key' => 'max', 'value' => ['intValue' => '9223372036854775807']],
            ['key' => 'zeros', 'value' => ['intValue' => '-007']],
        ]])]);

        $frame = (new JsonTracesDecoder())->decode($payload, new \DateTimeImmutable());

        self::assertSame(
            ['min' => \PHP_INT_MIN, 'max' => \PHP_INT_MAX, 'zeros' => -7],
            $this->singleSpan($frame->resourceSpans[0]->scopeSpans[0]->spans)->attributes,
        );
    }

    public function testDecodesSpanFields(): void
    {
        $payload = self::request([self::span([
            'parentSpanId' => '0102030405060708',
            'traceState' => 'vendor=value',
            'flags' => 257,
            'name' => 'GET /users',
            'kind' => 2,
            'startTimeUnixNano' => '1700000000000000000',
            'endTimeUnixNano' => '1700000000012345678',
            'droppedAttributesCount' => 1,
            'events' => [[
                'timeUnixNano' => '1700000000000001000',
                'name' => 'exception',
                'attributes' => [['key' => 'exception.type', 'value' => ['stringValue' => 'RuntimeException']]],
            ]],
            'links' => [[
                'traceId' => 'AAAAAAAAAAAAAAAAAAAAAA==',
                'spanId' => 'ffffffffffffffff',
                'attributes' => [['key' => 'reason', 'value' => ['stringValue' => 'retry']]],
            ]],
            'status' => ['code' => 2, 'message' => 'Boom'],
        ])]);

        $frame = (new JsonTracesDecoder())->decode($payload, new \DateTimeImmutable());
        $span = $this->singleSpan($frame->resourceSpans[0]->scopeSpans[0]->spans);

        self::assertSame(self::TRACE_ID, $span->traceId);
        self::assertSame(self::SPAN_ID, $span->spanId);
        self::assertSame('0102030405060708', $span->parentSpanId);
        self::assertSame('vendor=value', $span->traceState);
        self::assertSame(257, $span->flags);
        self::assertSame('GET /users', $span->name);
        self::assertSame(SpanKind::Server, $span->kind);
        self::assertSame(1700000000000000000, $span->startTimeUnixNano);
        self::assertSame(12345678, $span->getDuration());
        self::assertSame(1, $span->droppedAttributesCount);
        self::assertSame(StatusCode::Error, $span->status->code);
        self::assertSame('Boom', $span->status->message);

        self::assertCount(1, $span->events);
        self::assertSame('exception', $span->events[0]->name);
        self::assertSame(1700000000000001000, $span->events[0]->timeUnixNano);
        self::assertSame(['exception.type' => 'RuntimeException'], $span->events[0]->attributes);

        self::assertCount(1, $span->links);
        self::assertSame('00000000000000000000000000000000', $span->links[0]->traceId);
        self::assertSame('ffffffffffffffff', $span->links[0]->spanId);
        self::assertSame(['reason' => 'retry'], $span->links[0]->attributes);
    }

    public function testDecodesAttributesOfAllValueTypes(): void
    {
        $payload = self::request([self::span(['attributes' => [
            ['key' => 'string', 'value' => ['stringValue' => 'text']],
            ['key' => 'int', 'value' => ['intValue' => '9007199254740993']],
            ['key' => 'int.number', 'value' => ['intValue' => 42]],
            ['key' => 'double', 'value' => ['doubleValue' => 0.5]],
            ['key' => 'double.int', 'value' => ['doubleValue' => 2]],
            ['key' => 'double.nan', 'value' => ['doubleValue' => 'NaN']],
            ['key' => 'bool', 'value' => ['boolValue' => false]],
            ['key' => 'bytes', 'value' => ['bytesValue' => 'AQID']],
            ['key' => 'array', 'value' => ['arrayValue' => ['values' => [
                ['stringValue' => 'a'],
                ['intValue' => '1'],
                ['arrayValue' => ['values' => [['boolValue' => true]]]],
            ]]]],
            ['key' => 'kvlist', 'value' => ['kvlistValue' => ['values' => [
                ['key' => 'nested', 'value' => ['kvlistValue' => ['values' => [
                    ['key' => 'deep', 'value' => ['doubleValue' => 1.5]],
                ]]]],
            ]]]],
            ['key' => 'empty.array', 'value' => ['arrayValue' => []]],
            ['key' => 'empty.value', 'value' => []],
            ['key' => 'missing.value'],
        ]])]);

        $frame = (new JsonTracesDecoder())->decode($payload, new \DateTimeImmutable());
        $span = $this->singleSpan($frame->resourceSpans[0]->scopeSpans[0]->spans);

        self::assertSame([
            'string' => 'text',
            'int' => 9007199254740993,
            'int.number' => 42,
            'double' => 0.5,
            'double.int' => 2.0,
            'double.nan' => 'NaN',
            'bool' => false,
            'bytes' => 'AQID',
            'array' => ['a', 1, [true]],
            'kvlist' => ['nested' => ['deep' => 1.5]],
            'empty.array' => [],
            'empty.value' => null,
            'missing.value' => null,
        ], $span->attributes);
    }

    public function testDecodesResourceAndScope(): void
    {
        $payload = \json_encode(['resourceSpans' => [[
            'resource' => [
                'attributes' => [['key' => 'service.name', 'value' => ['stringValue' => 'billing']]],
                'droppedAttributesCount' => 2,
            ],
            'schemaUrl' => 'https://opentelemetry.io/schemas/1.38.0',
            'scopeSpans' => [[
                'scope' => [
                    'name' => 'my.scope',
                    'version' => '1.0.0',
                    'attributes' => [['key' => 'scope.attr', 'value' => ['boolValue' => true]]],
                ],
                'schemaUrl' => 'https://example.com/scope',
                'spans' => [self::span()],
            ]],
        ]]]);

        $frame = (new JsonTracesDecoder())->decode($payload, new \DateTimeImmutable());
        $resource = $frame->resourceSpans[0];
        $scope = $resource->scopeSpans[0];

        self::assertSame(['service.name' => 'billing'], $resource->resourceAttributes);
        self::assertSame('billing', $resource->getServiceName());
        self::assertSame(2, $resource->resourceDroppedAttributesCount);
        self::assertSame('https://opentelemetry.io/schemas/1.38.0', $resource->schemaUrl);
        self::assertSame('my.scope', $scope->scopeName);
        self::assertSame('1.0.0', $scope->scopeVersion);
        self::assertSame(['scope.attr' => true], $scope->scopeAttributes);
        self::assertSame('https://example.com/scope', $scope->schemaUrl);
    }

    public function testAcceptsEnumNamesAndUppercaseHexIds(): void
    {
        $payload = self::request([self::span([
            'traceId' => \strtoupper(self::TRACE_ID),
            'kind' => 'SPAN_KIND_CONSUMER',
            'status' => ['code' => 'STATUS_CODE_OK'],
        ])]);

        $frame = (new JsonTracesDecoder())->decode($payload, new \DateTimeImmutable());
        $span = $this->singleSpan($frame->resourceSpans[0]->scopeSpans[0]->spans);

        self::assertSame(self::TRACE_ID, $span->traceId);
        self::assertSame(SpanKind::Consumer, $span->kind);
        self::assertSame(StatusCode::Ok, $span->status->code);
    }

    public function testEmptyParentSpanIdMeansRoot(): void
    {
        $payload = self::request([self::span(['parentSpanId' => ''])]);

        $frame = (new JsonTracesDecoder())->decode($payload, new \DateTimeImmutable());

        self::assertNull($this->singleSpan($frame->resourceSpans[0]->scopeSpans[0]->spans)->parentSpanId);
    }

    public function testEmptyRequestHasNoSpans(): void
    {
        $time = new \DateTimeImmutable('2024-01-01 00:00:00');

        $frame = (new JsonTracesDecoder())->decode('{}', $time);

        self::assertSame([], $frame->resourceSpans);
        self::assertSame(0, $frame->countSpans());
        self::assertSame($time, $frame->time);
    }

    public function testDecodesFixtureFromOpenTelemetryPhpExporter(): void
    {
        $payload = \file_get_contents(Info::TRAP_ROOT . '/tests/Stub/otlp-traces.json');

        $frame = (new JsonTracesDecoder())->decode($payload, new \DateTimeImmutable());

        self::assertSame(4, $frame->countSpans());
        self::assertSame('e2e-app', $frame->resourceSpans[0]->getServiceName());
        $spans = [];
        foreach ($frame->iterateSpans() as [, , $span]) {
            $spans[$span->name] = $span;
        }

        self::assertSame(['db.query', 'template.load', 'render', 'GET /orders'], \array_keys($spans));
        $root = $spans['GET /orders'];
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
        self::assertSame($root->spanId, $db->parentSpanId);
        self::assertSame(SpanKind::Client, $db->kind);
        self::assertSame(StatusCode::Error, $db->status->code);
        self::assertSame('Connection lost', $db->status->message);
        self::assertSame('exception', $db->events[0]->name);
        self::assertSame('RuntimeException', $db->events[0]->attributes['exception.type']);

        self::assertSame($spans['render']->spanId, $spans['template.load']->parentSpanId);
    }

    #[DataProvider('provideMalformedPayloads')]
    public function testRejectsMalformedPayload(string $payload): void
    {
        $this->expectException(\Throwable::class);

        (new JsonTracesDecoder())->decode($payload, new \DateTimeImmutable());
    }

    /**
     * @param list<array> $spans
     */
    private static function request(array $spans): string
    {
        return \json_encode(['resourceSpans' => [['scopeSpans' => [['spans' => $spans]]]]]);
    }

    private static function span(array $fields = []): array
    {
        return $fields + [
            'traceId' => self::TRACE_ID,
            'spanId' => self::SPAN_ID,
            'name' => 'span',
            'kind' => 1,
            'startTimeUnixNano' => '1700000000000000000',
            'endTimeUnixNano' => '1700000000001000000',
        ];
    }

    /**
     * @param list<Span> $spans
     */
    private function singleSpan(array $spans): Span
    {
        self::assertCount(1, $spans);
        return $spans[0];
    }
}
