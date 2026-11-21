<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Tracing\Http\OtlpTrap;

use Buggregator\Trap\Module\Tracing\Frame\Trace\Attributes;
use Buggregator\Trap\Module\Tracing\Frame\Trace\SpanKind;
use Buggregator\Trap\Module\Tracing\Frame\Trace\StatusCode;

/**
 * Reads fields of the OTLP/JSON encoding: the proto3 JSON mapping with trace and span IDs as hex strings.
 *
 * Proto3 JSON lets a sender put 64-bit integers either as numbers or as strings, and enums
 * either as numbers or as names; both forms are accepted. Integers outside their protobuf type
 * are rejected, except timestamps above `PHP_INT_MAX`, which are clamped to it.
 *
 * @internal
 * @psalm-internal Buggregator\Trap\Module\Tracing\Http
 *
 * @psalm-import-type AttributeMap from Attributes
 * @psalm-import-type AttributeValue from Attributes
 */
final class JsonReader
{
    public const TRACE_ID_BYTES = 16;
    public const SPAN_ID_BYTES = 8;
    private const UINT32_MAX = 0xFFFF_FFFF;

    /**
     * Objects and lists of one request body: a Collector batch of 10 000 spans with 30 attributes each,
     * at most about 270 MB once decoded.
     */
    public const MAX_CONTAINERS = 650_000;

    /**
     * Decodes a request body that must be a JSON object.
     *
     * @throws \JsonException
     */
    public static function decode(string $payload): array
    {
        // Each decoded object or list takes a few hundred bytes: a 10 MB body of `[0],` would take gigabytes.
        $containers = self::countContainers($payload);
        $containers > self::MAX_CONTAINERS and throw LimitExceededException::containers(self::MAX_CONTAINERS);

        // Big integers as strings: as floats they would lose precision before the range checks.
        $data = \json_decode($payload, true, 512, \JSON_THROW_ON_ERROR | \JSON_BIGINT_AS_STRING);
        // A list like `[1,2]` decodes to an array as well, and `[]` is indistinguishable from `{}`.
        \is_array($data) && $payload[\strspn($payload, " \t\n\r")] === '{'
            or throw new \InvalidArgumentException('The request body must be a JSON object.');

        return $data;
    }

    /**
     * Objects and lists of a JSON text: `{` and `[` outside strings.
     *
     * @throws \InvalidArgumentException When a string is not closed.
     */
    public static function countContainers(string $json): int
    {
        $stripped = \preg_replace('/"(?:[^"\\\\]++|\\\\.)*+"/s', '""', $json);
        \is_string($stripped) or throw new \InvalidArgumentException('Invalid JSON string.');

        return \substr_count($stripped, '{') + \substr_count($stripped, '[');
    }

    public static function arrayField(mixed $data, string $key): array
    {
        /** @var mixed $value */
        $value = \is_array($data) ? self::field($data, $key) : null;

        return \is_array($value) ? $value : [];
    }

    /**
     * Reads a `repeated KeyValue` field into a map; a repeated key keeps the last value.
     *
     * @return AttributeMap
     */
    public static function attributes(array $data, string $key = 'attributes'): array
    {
        $result = [];
        /** @var mixed $item */
        foreach (self::list($data, $key) as $item) {
            $item = self::object($item, $key . '[]');
            $name = self::field($item, 'key');
            \is_string($name) or throw new \InvalidArgumentException(\sprintf('`%s[].key` must be a string.', $key));
            $result[$name] = self::anyValue(self::field($item, 'value'));
        }

        return $result;
    }

    /**
     * @return AttributeValue
     */
    public static function anyValue(mixed $value): mixed
    {
        // An unset AnyValue is valid and means an empty value.
        if ($value === null || $value === []) {
            return null;
        }

        $value = self::object($value, 'value');

        return match (true) {
            self::has($value, 'stringValue') => self::string($value, 'stringValue'),
            self::has($value, 'boolValue') => self::bool($value, 'boolValue'),
            self::has($value, 'intValue') => self::int64($value, 'intValue'),
            self::has($value, 'doubleValue') => self::double(self::field($value, 'doubleValue')),
            self::has($value, 'bytesValue') => self::string($value, 'bytesValue'),
            self::has($value, 'arrayValue') => \array_map(
                self::anyValue(...),
                self::list(self::object(self::field($value, 'arrayValue') ?? [], 'arrayValue'), 'values'),
            ),
            self::has($value, 'kvlistValue') => new \ArrayObject(self::attributes(
                self::object(self::field($value, 'kvlistValue') ?? [], 'kvlistValue'),
                'values',
            )),
            default => null,
        };
    }

    /**
     * Non-finite doubles stay as their proto3 JSON names because JSON numbers cannot carry them.
     */
    public static function double(mixed $value): float|string
    {
        $double = match (true) {
            \is_float($value) => $value,
            \is_int($value) => (float) $value,
            \in_array($value, ['NaN', 'Infinity', '-Infinity'], true) => $value,
            \is_string($value) && \is_numeric($value) => (float) $value,
            default => throw new \InvalidArgumentException('`doubleValue` must be a number.'),
        };

        // A number like 1e400 overflows to INF while decoding.
        return match (true) {
            !\is_float($double), \is_finite($double) => $double,
            \is_nan($double) => 'NaN',
            default => $double > 0 ? 'Infinity' : '-Infinity',
        };
    }

    /**
     * Accepts lowercase or uppercase hex as the OTLP/JSON spec requires, and base64 as the plain
     * proto3 JSON mapping of `bytes` produces.
     *
     * @param int<1, max> $bytes
     * @return non-empty-string|null Lowercase hex; null when the field is absent, empty or all zeros,
     *         which OpenTelemetry defines as an invalid ID.
     */
    public static function id(array $data, string $key, int $bytes): ?string
    {
        $value = self::string($data, $key);
        if ($value === '') {
            return null;
        }

        $binary = \base64_decode($value, true);
        $hex = match (true) {
            \strlen($value) === $bytes * 2 && \preg_match('/^[0-9a-f]++$/i', $value) === 1 => \strtolower($value),
            $binary !== false && \strlen($binary) === $bytes => \bin2hex($binary),
            default => throw new \InvalidArgumentException(
                \sprintf('`%s` must be a %d-byte hex string.', $key, $bytes),
            ),
        };

        return $hex === '' || \trim($hex, '0') === '' ? null : $hex;
    }

    /**
     * @param list<SpanKind>|list<StatusCode> $cases
     * @return int Zero when the field is absent.
     */
    public static function enum(array $data, string $key, string $prefix, array $cases): int
    {
        /** @var mixed $value */
        $value = self::field($data, $key) ?? 0;
        if (\is_string($value) && \str_starts_with($value, $prefix)) {
            foreach ($cases as $case) {
                if ($prefix . \strtoupper($case->name) === $value) {
                    return $case->value;
                }
            }

            return 0;
        }

        return self::int64($data, $key);
    }

    /**
     * Reads a `fixed64` Unix-nano timestamp. Values above `PHP_INT_MAX` are valid on the wire
     * but not representable, so they are clamped.
     *
     * @return int<0, max>
     */
    public static function timestamp(array $data, string $key): int
    {
        $value = self::integer($data, $key, 0, \PHP_INT_MAX, clamp: true);
        \assert($value >= 0);

        return $value;
    }

    /**
     * @return int<0, 4294967295>
     */
    public static function uint32(array $data, string $key): int
    {
        $value = self::integer($data, $key, 0, self::UINT32_MAX);
        \assert($value >= 0 && $value <= self::UINT32_MAX);

        return $value;
    }

    public static function int64(array $data, string $key): int
    {
        return self::integer($data, $key, \PHP_INT_MIN, \PHP_INT_MAX);
    }

    /**
     * @param bool $clamp Turn a value above `$max`, even one beyond the int64 range, into `$max` instead of failing.
     */
    public static function integer(array $data, string $key, int $min, int $max, bool $clamp = false): int
    {
        /** @var mixed $value */
        $value = self::field($data, $key) ?? 0;

        // Beyond the int64 range a value becomes an infinite float, so the range checks below see it.
        $number = match (true) {
            \is_int($value) => $value,
            \is_string($value) => self::parseIntegerString($value),
            \is_float($value) && \is_finite($value) && \floor($value) === $value => match (true) {
                $value >= 9_223_372_036_854_775_808.0 => \INF,
                $value < -9_223_372_036_854_775_808.0 => -\INF,
                default => (int) $value,
            },
            default => null,
        } ?? throw new \InvalidArgumentException(\sprintf('`%s` must be an integer.', $key));

        if ($number < $min || ($number > $max && !$clamp)) {
            throw new \InvalidArgumentException(\sprintf('`%s` is out of range.', $key));
        }

        return $number > $max ? $max : (int) $number;
    }

    /**
     * Parses an integer string of proto3 JSON, which may use a fraction and an exponent, like `1.5e3`,
     * as long as the value is whole. Parsed as digits, not as a float, to keep all 64 bits.
     *
     * @return int|float|null An infinite float beyond the int64 range; null when the string is not
     *         a whole number.
     */
    public static function parseIntegerString(string $value): int|float|null
    {
        if (\preg_match('/^(-?)(\d++)(?:\.(\d++))?(?:[eE]([+-]?)(\d++))?$/', $value, $match) !== 1) {
            return null;
        }

        $negative = $match[1] === '-';
        $fraction = $match[3] ?? '';
        $digits = \ltrim($match[2] . $fraction, '0');
        if ($digits === '') {
            return 0;
        }

        $exponent = \ltrim($match[5] ?? '', '0');
        if (\strlen($exponent) > 4) {
            // A non-zero number with such an exponent is either far beyond int64 or a fraction.
            return ($match[4] ?? '') === '-' ? null : ($negative ? -\INF : \INF);
        }

        $shift = (int) $exponent * (($match[4] ?? '') === '-' ? -1 : 1) - \strlen($fraction);
        if ($shift < 0) {
            if (-$shift >= \strlen($digits) || \trim(\substr($digits, $shift), '0') !== '') {
                return null;
            }
            $digits = \substr($digits, 0, $shift);
        } elseif ($shift > 0) {
            if (\strlen($digits) + $shift > 19) {
                return $negative ? -\INF : \INF;
            }
            $digits .= \str_repeat('0', $shift);
        }

        return self::parseDigits($negative, $digits);
    }

    /**
     * @param string $digits Without leading zeros.
     */
    public static function parseDigits(bool $negative, string $digits): int|float
    {
        $limit = $negative ? '9223372036854775808' : '9223372036854775807';
        $fits = \strlen($digits) < \strlen($limit)
            || (\strlen($digits) === \strlen($limit) && \strcmp($digits, $limit) <= 0);

        return match (true) {
            !$fits => $negative ? -\INF : \INF,
            $negative => (int) ('-' . $digits),
            default => (int) $digits,
        };
    }

    public static function bool(array $data, string $key): bool
    {
        /** @var mixed $value */
        $value = self::field($data, $key);
        \is_bool($value) or throw new \InvalidArgumentException(\sprintf('`%s` must be a boolean.', $key));

        return $value;
    }

    public static function string(array $data, string $key): string
    {
        $value = self::field($data, $key) ?? '';
        \is_string($value) or throw new \InvalidArgumentException(\sprintf('`%s` must be a string.', $key));

        return $value;
    }

    /**
     * @return list<mixed>
     */
    public static function list(array $data, string $key): array
    {
        $value = self::field($data, $key) ?? [];
        \is_array($value) && \array_is_list($value)
            or throw new \InvalidArgumentException(\sprintf('`%s` must be an array.', $key));

        return $value;
    }

    /**
     * Reads a field by its JSON name or by its original proto name: proto3 JSON parsers must accept both.
     */
    public static function field(array $data, string $jsonName): mixed
    {
        return $data[$jsonName] ?? $data[self::protoName($jsonName)] ?? null;
    }

    public static function has(array $data, string $jsonName): bool
    {
        return \array_key_exists($jsonName, $data) || \array_key_exists(self::protoName($jsonName), $data);
    }

    /**
     * `startTimeUnixNano` => `start_time_unix_nano`
     */
    public static function protoName(string $jsonName): string
    {
        return \strtolower((string) \preg_replace('/[A-Z]/', '_$0', $jsonName));
    }

    public static function object(mixed $value, string $name): array
    {
        if (\is_array($value) && ($value === [] || !\array_is_list($value))) {
            return $value;
        }

        throw new \InvalidArgumentException(\sprintf('`%s` must be an object.', $name));
    }
}
