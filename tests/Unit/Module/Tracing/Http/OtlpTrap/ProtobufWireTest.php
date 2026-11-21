<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Tracing\Http\OtlpTrap;

use Buggregator\Trap\Module\Tracing\Http\OtlpTrap\LimitExceededException;
use Buggregator\Trap\Module\Tracing\Http\OtlpTrap\ProtobufWire;
use Testo\Assert\ExpectNoAssertions;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Covers(ProtobufWire::class)]
#[Test]
final class ProtobufWireTest
{
    public function testCountsAttributesInsideOneSpan(): never
    {
        $attributes = \str_repeat(self::len(9, self::len(1, 'a')), 101);
        Expect::exception(LimitExceededException::class)->withMessageContaining('Too many elements');

        ProtobufWire::check(self::span($attributes), 'traces', 10, 'spans', maxElements: 100);
    }

    public function testCountsEmptyAttributesEventsAndLinks(): never
    {
        $fields = \str_repeat(self::len(9, '') . self::len(11, '') . self::len(13, ''), 40);
        Expect::exception(LimitExceededException::class)->withMessageContaining('Too many elements');

        ProtobufWire::check(self::span($fields), 'traces', 10, 'spans', maxElements: 100);
    }

    public function testCountsNestedValuesOfALogBody(): never
    {
        // body (5) = AnyValue { kvlist_value (6) = KeyValueList { values (1) = KeyValue { value (2) =
        // AnyValue { array_value (5) = ArrayValue { values (1) x 120 } } } } }
        $array = self::len(5, \str_repeat(self::len(1, self::len(1, 'x')), 120));
        $body = self::len(5, self::len(6, self::len(1, self::len(1, 'k') . self::len(2, $array))));
        Expect::exception(LimitExceededException::class)->withMessageContaining('Too many elements');

        ProtobufWire::check(self::len(1, self::len(2, self::len(2, $body))), 'logs', 10, 'log records', maxElements: 100);
    }

    public function testCountsExemplarsAndPackedBuckets(): never
    {
        // A histogram (9) data point (1) with 2 exemplars (8) and 800 packed bucket counts (6)
        $point = \str_repeat(self::len(8, ''), 2) . self::len(6, \str_repeat("\x01\x00\x00\x00\x00\x00\x00\x00", 800));
        $metric = self::len(1, 'm') . self::len(9, self::len(1, $point));
        Expect::exception(LimitExceededException::class)->withMessageContaining('Too many elements');

        ProtobufWire::check(self::len(1, self::len(2, self::len(2, $metric))), 'metrics', 10, 'data points', maxElements: 99);
    }

    public function testCountsEveryTagAgainstTheTagLimit(): never
    {
        // Unknown varint fields of a span
        Expect::exception(LimitExceededException::class)->withMessageContaining('Too many protobuf fields');

        ProtobufWire::check(self::span(\str_repeat("\xf8\x07\x00", 200)), 'traces', 10, 'spans', maxTags: 100);
    }

    public function testCountsResourceEntityRefsAndTheirKeys(): never
    {
        // resource (1) { entity_refs (3) { id_keys (3) x 60 } x 50 }
        $refs = \str_repeat(self::len(3, \str_repeat(self::len(3, 'k'), 1)), 50) . self::len(3, \str_repeat(self::len(3, ''), 60));
        Expect::exception(LimitExceededException::class)->withMessageContaining('Too many elements');

        ProtobufWire::check(self::len(1, self::len(1, $refs)), 'traces', 10, 'spans', maxElements: 100);
    }

    public function testCountsUnpackedRepeatedScalars(): never
    {
        // A histogram (9) data point (1) with 101 unpacked explicit_bounds (7, fixed64)
        $point = \str_repeat("\x39" . \pack('e', 1.5), 101);
        $metric = self::len(1, 'm') . self::len(9, self::len(1, $point));
        Expect::exception(LimitExceededException::class)->withMessageContaining('Too many elements');

        ProtobufWire::check(self::len(1, self::len(2, self::len(2, $metric))), 'metrics', 10, 'data points', maxElements: 100);
    }

    public function testCountsUnknownFieldsInsideKnownMessages(): never
    {
        // Unknown varint fields inside Span.status (15)
        Expect::exception(LimitExceededException::class)->withMessageContaining('Too many elements');

        ProtobufWire::check(self::span(self::len(15, \str_repeat("\xf8\x07\x00", 101))), 'traces', 10, 'spans', maxElements: 100);
    }

    public function testWeighsControlCharactersOfStringsAsJson(): never
    {
        // One attribute value (string) of 20 control characters: 120 bytes as JSON
        $attribute = self::len(9, self::len(1, 'k') . self::len(2, self::len(1, \str_repeat("\x01", 20))));
        Expect::exception(LimitExceededException::class)->withMessageContaining('Too much text');

        ProtobufWire::check(self::span($attribute), 'traces', 10, 'spans', maxText: 110);
    }

    #[ExpectNoAssertions]
    public function testWeighsPlainStringsByTheirLength(): void
    {
        $attribute = self::len(9, self::len(1, 'k') . self::len(2, self::len(1, \str_repeat('a', 100))));

        ProtobufWire::check(self::span($attribute), 'traces', 10, 'spans', maxText: 110);
    }

    public function testWeighsBytesAsBase64(): never
    {
        // Span.trace_id (1) of 90 bytes: 120 as base64
        Expect::exception(LimitExceededException::class)->withMessageContaining('Too much text');

        ProtobufWire::check(self::span(self::len(1, \str_repeat("\xff", 90))), 'traces', 10, 'spans', maxText: 110);
    }

    public function testRejectsNestingTooDeep(): never
    {
        $value = '';
        for ($i = 0; $i < ProtobufWire::MAX_DEPTH; ++$i) {
            $value = self::len(5, self::len(1, $value));
        }
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('nesting too deep');

        ProtobufWire::check(self::len(1, self::len(2, self::len(2, self::len(5, $value)))), 'logs', 10, 'log records');
    }

    #[ExpectNoAssertions]
    public function testAcceptsWithinTheLimits(): void
    {
        $attributes = \str_repeat(self::len(9, self::len(1, 'key') . self::len(2, self::len(1, 'value'))), 50);

        ProtobufWire::check(self::span($attributes . "\x38\x01"), 'traces', 10, 'spans', maxElements: 100, maxTags: 1000);
    }

    private static function span(string $fields): string
    {
        return self::len(1, self::len(2, self::len(2, $fields)));
    }

    /**
     * A length-delimited protobuf field.
     */
    private static function len(int $number, string $value): string
    {
        $length = \strlen($value);
        $varint = '';
        while ($length > 0x7F) {
            $varint .= \chr(($length & 0x7F) | 0x80);
            $length >>= 7;
        }

        return \chr($number << 3 | 2) . $varint . \chr($length) . $value;
    }
}
