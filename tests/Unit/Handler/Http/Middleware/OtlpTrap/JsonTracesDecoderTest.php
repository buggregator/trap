<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Handler\Http\Middleware\OtlpTrap;

use Buggregator\Trap\Handler\Http\Middleware\OtlpTrap\JsonTracesDecoder;
use Buggregator\Trap\Handler\Http\Middleware\OtlpTrap\LimitExceededException;
use Buggregator\Trap\Info;
use Buggregator\Trap\Proto\Frame\Otlp;
use Buggregator\Trap\Proto\Frame\Otlp\Trace\Span;
use Buggregator\Trap\Proto\Frame\Otlp\Trace\SpanKind;
use Buggregator\Trap\Proto\Frame\Otlp\Trace\StatusCode;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Covers(JsonTracesDecoder::class)]
#[Test]
final class JsonTracesDecoderTest
{
    private const TRACE_ID = '5b8efff798038103d269b633813fc60c';
    private const SPAN_ID = 'eee19b7ec3c1b174';

    public static function provideMalformedPayloads(): \Generator
    {
        yield 'not a JSON' => ['{"resourceSpans": ['];
        yield 'JSON scalar' => ['"text"'];
        yield 'JSON list' => ['[1,2]'];
        yield 'empty JSON list' => [' []'];
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
        foreach (['1.5', '1e-1', '15e-1', '0.50', '1e', 'e3', '1.e3', '+1', '1e99999', '1e-99999'] as $int) {
            yield "intValue string $int" => [
                self::request([self::span(['attributes' => [['key' => 'k', 'value' => ['intValue' => $int]]]])]),
            ];
        }
        yield 'intValue string above int64 with exponent' => [
            self::request([self::span(['attributes' => [['key' => 'k', 'value' => ['intValue' => '9.3e18']]]])]),
        ];
        yield 'negative flags' => [self::request([self::span(['flags' => -1])])];
        yield 'flags above uint32' => [self::request([self::span(['flags' => 4294967296])])];
        yield 'negative dropped count' => [self::request([self::span(['droppedEventsCount' => '-3'])])];
        yield 'zero traceId' => [self::request([self::span(['traceId' => \str_repeat('0', 32)])])];
        yield 'zero base64 traceId' => [self::request([self::span(['traceId' => 'AAAAAAAAAAAAAAAAAAAAAA=='])])];
        yield 'zero spanId' => [self::request([self::span(['spanId' => \str_repeat('0', 16)])])];
        yield 'zero link spanId' => [
            self::request([self::span(['links' => [['traceId' => self::TRACE_ID, 'spanId' => \str_repeat('0', 16)]]])]),
        ];
    }

    public static function provideClampedTimestamps(): \Generator
    {
        yield 'uint64 max as string' => ['"18446744073709551615"'];
        yield 'above int64 as string' => ['"9223372036854775808"'];
        yield 'above int64 as big JSON integer' => ['99999999999999999999'];
        yield 'above int64 as JSON float' => ['1e20'];
    }

    public static function provideRootParentSpanIds(): \Generator
    {
        yield 'empty' => [''];
        yield 'zero hex' => ['0000000000000000'];
        yield 'zero base64' => ['AAAAAAAAAAA='];
    }

    #[DataProvider('provideClampedTimestamps')]
    public function testClampsTimestampsAboveInt64(string $json): void
    {
        $payload = \str_replace('"END"', $json, self::request([self::span(['endTimeUnixNano' => 'END'])]));

        $frame = (new JsonTracesDecoder())->decode($payload, new \DateTimeImmutable());
        $span = $this->singleSpan($frame->resourceSpans[0]->scopeSpans[0]->spans);

        Assert::same($span->endTimeUnixNano, \PHP_INT_MAX);
        Assert::same($span->getDuration(), \PHP_INT_MAX - 1700000000000000000);
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

        Assert::same($this->singleSpan($frame->resourceSpans[0]->scopeSpans[0]->spans)->attributes, ['big' => 'Infinity', 'big.string' => 'Infinity', 'small' => '-Infinity', 'nan' => 'NaN', 'inf' => '-Infinity']);
        Assert::string((string) $frame)->contains('"big":"Infinity"');
    }

    public function testAcceptsInt64Bounds(): void
    {
        $payload = self::request([self::span(['attributes' => [
            ['key' => 'min', 'value' => ['intValue' => '-9223372036854775808']],
            ['key' => 'max', 'value' => ['intValue' => '9223372036854775807']],
            ['key' => 'zeros', 'value' => ['intValue' => '-007']],
        ]])]);

        $frame = (new JsonTracesDecoder())->decode($payload, new \DateTimeImmutable());

        Assert::same($this->singleSpan($frame->resourceSpans[0]->scopeSpans[0]->spans)->attributes, ['min' => \PHP_INT_MIN, 'max' => \PHP_INT_MAX, 'zeros' => -7]);
    }

    public function testAcceptsWholeNumberStringsWithExponent(): void
    {
        $values = [
            '1e3' => 1000,
            '1.5e3' => 1500,
            '-2E+2' => -200,
            '1500e-1' => 150,
            '5.0' => 5,
            '0e99999' => 0,
            '-0.0' => 0,
            '9.223372036854775807e18' => \PHP_INT_MAX,
            '-9223372036854775808e0' => \PHP_INT_MIN,
        ];
        $attributes = [];
        foreach ($values as $int => $_) {
            $attributes[] = ['key' => (string) $int, 'value' => ['intValue' => (string) $int]];
        }
        $payload = self::request([self::span(['attributes' => $attributes, 'startTimeUnixNano' => '1.7e18'])]);

        $span = $this->singleSpan((new JsonTracesDecoder())->decode($payload, new \DateTimeImmutable())
            ->resourceSpans[0]->scopeSpans[0]->spans);

        Assert::same($span->attributes, $values);
        Assert::same($span->startTimeUnixNano, 1_700_000_000_000_000_000);
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
                'traceId' => 'AQIDBAUGBwgJCgsMDQ4PEA==',
                'spanId' => 'ffffffffffffffff',
                'attributes' => [['key' => 'reason', 'value' => ['stringValue' => 'retry']]],
            ]],
            'status' => ['code' => 2, 'message' => 'Boom'],
        ])]);

        $frame = (new JsonTracesDecoder())->decode($payload, new \DateTimeImmutable());
        $span = $this->singleSpan($frame->resourceSpans[0]->scopeSpans[0]->spans);

        Assert::same($span->traceId, self::TRACE_ID);
        Assert::same($span->spanId, self::SPAN_ID);
        Assert::same($span->parentSpanId, '0102030405060708');
        Assert::same($span->traceState, 'vendor=value');
        Assert::same($span->flags, 257);
        Assert::same($span->name, 'GET /users');
        Assert::same($span->kind, SpanKind::Server);
        Assert::same($span->startTimeUnixNano, 1700000000000000000);
        Assert::same($span->getDuration(), 12345678);
        Assert::same($span->droppedAttributesCount, 1);
        Assert::same($span->status->code, StatusCode::Error);
        Assert::same($span->status->message, 'Boom');

        Assert::count($span->events, 1);
        Assert::same($span->events[0]->name, 'exception');
        Assert::same($span->events[0]->timeUnixNano, 1700000000000001000);
        Assert::same($span->events[0]->attributes, ['exception.type' => 'RuntimeException']);

        Assert::count($span->links, 1);
        Assert::same($span->links[0]->traceId, '0102030405060708090a0b0c0d0e0f10');
        Assert::same($span->links[0]->spanId, 'ffffffffffffffff');
        Assert::same($span->links[0]->attributes, ['reason' => 'retry']);
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

        Assert::equals($span->attributes, [
            'string' => 'text',
            'int' => 9007199254740993,
            'int.number' => 42,
            'double' => 0.5,
            'double.int' => 2.0,
            'double.nan' => 'NaN',
            'bool' => false,
            'bytes' => 'AQID',
            'array' => ['a', 1, [true]],
            'kvlist' => new \ArrayObject(['nested' => new \ArrayObject(['deep' => 1.5])]),
            'empty.array' => [],
            'empty.value' => null,
            'missing.value' => null,
        ]);
        Assert::same($span->attributes['double.int'], 2.0);
    }

    public function testMapsStayObjectsAndListsStayArraysInJson(): void
    {
        $payload = self::request([self::span(['attributes' => [
            ['key' => '0', 'value' => ['stringValue' => 'zero']],
            ['key' => '1', 'value' => ['kvlistValue' => ['values' => [
                ['key' => '0', 'value' => ['doubleValue' => 1.0]],
            ]]]],
            ['key' => 'empty.map', 'value' => ['kvlistValue' => []]],
            ['key' => 'empty.list', 'value' => ['arrayValue' => []]],
            ['key' => 'list', 'value' => ['arrayValue' => ['values' => [['kvlistValue' => []]]]]],
        ]])]);

        $frame = (new JsonTracesDecoder())->decode($payload, new \DateTimeImmutable());
        $json = (string) $frame;

        Assert::string($json)->contains('"attributes":{"0":"zero","1":{"0":1.0},"empty.map":{},"empty.list":[],"list":[{}]}');
        Assert::same((string) Otlp::fromString($json, $frame->time), $json, 'Roundtrip keeps the JSON as is.');
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

        Assert::same($resource->resourceAttributes, ['service.name' => 'billing']);
        Assert::same($resource->getServiceName(), 'billing');
        Assert::same($resource->resourceDroppedAttributesCount, 2);
        Assert::same($resource->schemaUrl, 'https://opentelemetry.io/schemas/1.38.0');
        Assert::same($scope->scopeName, 'my.scope');
        Assert::same($scope->scopeVersion, '1.0.0');
        Assert::same($scope->scopeAttributes, ['scope.attr' => true]);
        Assert::same($scope->schemaUrl, 'https://example.com/scope');
    }

    public function testAcceptsProtoFieldNames(): void
    {
        $payload = \json_encode(['resource_spans' => [[
            'resource' => ['attributes' => [['key' => 'service.name', 'value' => ['string_value' => 'billing']]]],
            'schema_url' => 'https://example.com/resource',
            'scope_spans' => [[
                'scope' => ['name' => 'my.scope'],
                'spans' => [[
                    'trace_id' => self::TRACE_ID,
                    'span_id' => self::SPAN_ID,
                    'parent_span_id' => '0102030405060708',
                    'name' => 'span',
                    'kind' => 'SPAN_KIND_CLIENT',
                    'start_time_unix_nano' => '1700000000000000000',
                    'end_time_unix_nano' => '1700000000000000500',
                    'attributes' => [
                        ['key' => 'flag', 'value' => ['bool_value' => true]],
                        ['key' => 'list', 'value' => ['array_value' => ['values' => [['int_value' => '1']]]]],
                        ['key' => 'map', 'value' => ['kvlist_value' => ['values' => [
                            ['key' => 'x', 'value' => ['double_value' => 0.5]],
                        ]]]],
                    ],
                    'events' => [['time_unix_nano' => '1700000000000000100', 'name' => 'event']],
                    'dropped_links_count' => 2,
                    'status' => ['code' => 'STATUS_CODE_ERROR', 'message' => 'Boom'],
                ]],
            ]],
        ]]]);

        $frame = (new JsonTracesDecoder())->decode($payload, new \DateTimeImmutable());
        $span = $this->singleSpan($frame->resourceSpans[0]->scopeSpans[0]->spans);

        Assert::same($frame->resourceSpans[0]->getServiceName(), 'billing');
        Assert::same($frame->resourceSpans[0]->schemaUrl, 'https://example.com/resource');
        Assert::same($span->spanId, self::SPAN_ID);
        Assert::same($span->parentSpanId, '0102030405060708');
        Assert::same($span->kind, SpanKind::Client);
        Assert::same($span->getDuration(), 500);
        Assert::equals($span->attributes, ['flag' => true, 'list' => [1], 'map' => new \ArrayObject(['x' => 0.5])]);
        Assert::same($span->events[0]->timeUnixNano, 1700000000000000100);
        Assert::same($span->droppedLinksCount, 2);
        Assert::same($span->status->code, StatusCode::Error);
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

        Assert::same($span->traceId, self::TRACE_ID);
        Assert::same($span->kind, SpanKind::Consumer);
        Assert::same($span->status->code, StatusCode::Ok);
    }

    #[DataProvider('provideRootParentSpanIds')]
    public function testEmptyOrZeroParentSpanIdMeansRoot(string $parentSpanId): void
    {
        $payload = self::request([self::span(['parentSpanId' => $parentSpanId])]);

        $frame = (new JsonTracesDecoder())->decode($payload, new \DateTimeImmutable());

        Assert::null($this->singleSpan($frame->resourceSpans[0]->scopeSpans[0]->spans)->parentSpanId);
    }

    public function testCountsSpansOfAllScopesAgainstLimit(): void
    {
        $scope = ['spans' => [self::span(), self::span(['spanId' => 'eee19b7ec3c1b175'])]];
        $payload = \json_encode(['resourceSpans' => [
            ['scopeSpans' => [$scope, $scope]],
            ['scopeSpans' => [$scope]],
        ]]);

        Assert::same((new JsonTracesDecoder(6))->decode($payload, new \DateTimeImmutable())->countSpans(), 6);

        Expect::exception(LimitExceededException::class);
        (new JsonTracesDecoder(5))->decode($payload, new \DateTimeImmutable());
    }

    public function testEmptyRequestHasNoSpans(): void
    {
        $time = new \DateTimeImmutable('2024-01-01 00:00:00');

        $frame = (new JsonTracesDecoder())->decode('{}', $time);

        Assert::same($frame->resourceSpans, []);
        Assert::same($frame->countSpans(), 0);
        Assert::same($frame->time, $time);
    }

    public function testDecodesFixtureFromOpenTelemetryPhpExporter(): void
    {
        $payload = \file_get_contents(Info::TRAP_ROOT . '/tests/Stub/otlp-traces.json');

        $frame = (new JsonTracesDecoder())->decode($payload, new \DateTimeImmutable());

        Assert::same($frame->countSpans(), 4);
        Assert::same($frame->resourceSpans[0]->getServiceName(), 'e2e-app');
        $spans = [];
        foreach ($frame->iterateSpans() as [, , $span]) {
            $spans[$span->name] = $span;
        }

        Assert::same(\array_keys($spans), ['db.query', 'template.load', 'render', 'GET /orders']);
        $root = $spans['GET /orders'];
        Assert::null($root->parentSpanId);
        Assert::same($root->kind, SpanKind::Server);
        Assert::same($root->status->code, StatusCode::Ok);
        Assert::same($root->attributes, [
            'http.request.method' => 'GET',
            'http.response.status_code' => 200,
            'server.ratio' => 0.75,
            'feature.enabled' => true,
            'order.ids' => [1, 2, 3],
        ]);

        $db = $spans['db.query'];
        Assert::same($db->parentSpanId, $root->spanId);
        Assert::same($db->kind, SpanKind::Client);
        Assert::same($db->status->code, StatusCode::Error);
        Assert::same($db->status->message, 'Connection lost');
        Assert::same($db->events[0]->name, 'exception');
        Assert::same($db->events[0]->attributes['exception.type'], 'RuntimeException');

        Assert::same($spans['template.load']->parentSpanId, $spans['render']->spanId);
    }

    #[DataProvider('provideMalformedPayloads')]
    #[ExpectException(\Throwable::class)]
    public function testRejectsMalformedPayload(string $payload): void
    {
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
        Assert::count($spans, 1);
        return $spans[0];
    }
}
