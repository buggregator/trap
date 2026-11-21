<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Tracing\Http\OtlpTrap;

/**
 * Checks an OTLP protobuf message on the wire before it is built: building it takes time and memory
 * in proportion to its elements, and a 10 MB body of 2-byte attributes holds millions of them.
 *
 * The walk follows the OTLP schema into every repeated message field and counts:
 * - records (spans, log records, metric data points) against the record limit of the signal;
 * - elements: records, attributes and the values of nested arrays and key-value lists, events, links,
 *   exemplars, quantiles, and values of packed bucket arrays, against {@see self::maxElements()};
 * - tags it reads, unknown fields included, against {@see self::MAX_TAGS}, which bounds the walk itself.
 *
 * The pure-PHP `google/protobuf` builds a message some 50 times slower than `ext-protobuf`, at tens of
 * microseconds per element: without the extension the element limit is {@see self::MAX_ELEMENTS_PURE_PHP}
 * and each packed value is an element; with it, every 8 packed values are one.
 *
 * It fails closed: what it cannot read for sure a parser might still accept, so groups, unknown wire types,
 * tags over 5 bytes, field numbers out of range, truncated values and nesting deeper than
 * {@see self::MAX_DEPTH} make the message malformed.
 *
 * @internal
 * @psalm-internal Buggregator\Trap\Module\Tracing\Http
 *
 * @psalm-type Field = array{0: non-empty-string|null, 1: int<0, 2>, 2?: int<0, 8>}
 */
final class ProtobufWire
{
    /**
     * Elements of one request with `ext-protobuf`: a Collector batch of 10 000 spans with 30 attributes each.
     * An element takes about 500 bytes of memory and 5 microseconds on its way to the store.
     */
    public const MAX_ELEMENTS = 400_000;

    /**
     * Elements of one request with the pure-PHP `google/protobuf`: an SDK batch of 512 spans with 30 attributes,
     * a few seconds to build at most.
     */
    public const MAX_ELEMENTS_PURE_PHP = 20_000;

    /** Tags read in one request, a second or two of the walk. */
    public const MAX_TAGS = 2_000_000;

    public const MAX_DEPTH = 64;
    private const SINGLE = 0;
    private const ELEMENT = 1;
    private const RECORD = 2;

    /**
     * Message name => field number => [message of the field or null for a packed scalar array, what an element
     * of the field is, bytes per packed value (0 for varints)]. Fields not listed are skipped as a whole.
     *
     * @var array<non-empty-string, array<int, Field>>
     */
    private const SCHEMA = [
        'traces' => [1 => ['ResourceSpans', self::ELEMENT]],
        'ResourceSpans' => [1 => ['Resource', self::SINGLE], 2 => ['ScopeSpans', self::ELEMENT]],
        'ScopeSpans' => [1 => ['Scope', self::SINGLE], 2 => ['Span', self::RECORD]],
        'Span' => [9 => ['KeyValue', self::ELEMENT], 11 => ['Event', self::ELEMENT], 13 => ['Link', self::ELEMENT]],
        'Event' => [3 => ['KeyValue', self::ELEMENT]],
        'Link' => [5 => ['KeyValue', self::ELEMENT]],

        'logs' => [1 => ['ResourceLogs', self::ELEMENT]],
        'ResourceLogs' => [1 => ['Resource', self::SINGLE], 2 => ['ScopeLogs', self::ELEMENT]],
        'ScopeLogs' => [1 => ['Scope', self::SINGLE], 2 => ['LogRecord', self::RECORD]],
        'LogRecord' => [5 => ['AnyValue', self::SINGLE], 6 => ['KeyValue', self::ELEMENT]],

        'metrics' => [1 => ['ResourceMetrics', self::ELEMENT]],
        'ResourceMetrics' => [1 => ['Resource', self::SINGLE], 2 => ['ScopeMetrics', self::ELEMENT]],
        'ScopeMetrics' => [1 => ['Scope', self::SINGLE], 2 => ['Metric', self::ELEMENT]],
        'Metric' => [
            5 => ['Gauge', self::SINGLE],
            7 => ['Gauge', self::SINGLE],
            9 => ['Histogram', self::SINGLE],
            10 => ['ExponentialHistogram', self::SINGLE],
            11 => ['Summary', self::SINGLE],
            12 => ['KeyValue', self::ELEMENT],
        ],
        'Gauge' => [1 => ['NumberDataPoint', self::RECORD]],
        'Histogram' => [1 => ['HistogramDataPoint', self::RECORD]],
        'ExponentialHistogram' => [1 => ['ExponentialHistogramDataPoint', self::RECORD]],
        'Summary' => [1 => ['SummaryDataPoint', self::RECORD]],
        'NumberDataPoint' => [7 => ['KeyValue', self::ELEMENT], 5 => ['Exemplar', self::ELEMENT]],
        'HistogramDataPoint' => [
            9 => ['KeyValue', self::ELEMENT],
            8 => ['Exemplar', self::ELEMENT],
            6 => [null, self::ELEMENT, 8],
            7 => [null, self::ELEMENT, 8],
        ],
        'ExponentialHistogramDataPoint' => [
            1 => ['KeyValue', self::ELEMENT],
            11 => ['Exemplar', self::ELEMENT],
            8 => ['Buckets', self::SINGLE],
            9 => ['Buckets', self::SINGLE],
        ],
        'Buckets' => [2 => [null, self::ELEMENT, 0]],
        'SummaryDataPoint' => [7 => ['KeyValue', self::ELEMENT], 6 => ['ValueAtQuantile', self::ELEMENT]],
        'ValueAtQuantile' => [],
        'Exemplar' => [7 => ['KeyValue', self::ELEMENT]],

        'Resource' => [1 => ['KeyValue', self::ELEMENT]],
        'Scope' => [3 => ['KeyValue', self::ELEMENT]],
        'KeyValue' => [2 => ['AnyValue', self::SINGLE]],
        'AnyValue' => [5 => ['ArrayValue', self::SINGLE], 6 => ['KeyValueList', self::SINGLE]],
        'ArrayValue' => [1 => ['AnyValue', self::ELEMENT]],
        'KeyValueList' => [1 => ['KeyValue', self::ELEMENT]],
    ];

    private const MAX_FIELD_NUMBER = (1 << 29) - 1;
    private const WIRE_VARINT = 0;
    private const WIRE_FIXED64 = 1;
    private const WIRE_LEN = 2;
    private const WIRE_FIXED32 = 5;

    /** @var int<0, max> */
    private int $records = 0;

    /** @var int<0, max> */
    private int $elements = 0;

    /** @var int<0, max> */
    private int $tags = 0;

    /**
     * @param int<1, max> $maxRecords
     * @param int<1, max> $maxElements
     * @param int<1, max> $maxTags
     * @param int<1, 8> $packedPerElement Packed values counted as one element.
     */
    private function __construct(
        private readonly string $bytes,
        private readonly int $maxRecords,
        private readonly int $maxElements,
        private readonly int $maxTags,
        private readonly int $packedPerElement,
    ) {}

    /**
     * @return int<1, max>
     */
    public static function maxElements(): int
    {
        return \extension_loaded('protobuf') ? self::MAX_ELEMENTS : self::MAX_ELEMENTS_PURE_PHP;
    }

    /**
     * @param 'traces'|'logs'|'metrics' $signal
     * @param int<1, max> $maxRecords
     * @param string $records What the records are, for the error message.
     * @param int<1, max>|null $maxElements {@see self::maxElements()} when null.
     * @param int<1, max> $maxTags
     *
     * @throws LimitExceededException When the message has more records, elements or tags than accepted.
     * @throws \InvalidArgumentException When the message is malformed.
     */
    public static function check(
        string $payload,
        string $signal,
        int $maxRecords,
        string $records,
        ?int $maxElements = null,
        int $maxTags = self::MAX_TAGS,
    ): void {
        $extension = \extension_loaded('protobuf');
        $maxElements ??= self::maxElements();
        $wire = new self($payload, $maxRecords, $maxElements, $maxTags, $extension ? 8 : 1);
        $wire->walk($signal, 0, \strlen($payload), 0);

        $wire->records > $maxRecords and throw LimitExceededException::records($maxRecords, $records);
        $wire->elements > $maxElements and throw LimitExceededException::elements($maxElements, $extension);
        $wire->tags > $maxTags and throw LimitExceededException::fields($maxTags);
    }

    private static function malformed(string $reason): \InvalidArgumentException
    {
        return new \InvalidArgumentException("Invalid protobuf message: $reason.");
    }

    /**
     * @param non-empty-string $message
     */
    private function walk(string $message, int $offset, int $end, int $depth): void
    {
        $depth <= self::MAX_DEPTH or throw self::malformed('nesting too deep');
        $schema = self::SCHEMA[$message];
        $bytes = $this->bytes;

        // Hot loop: one-byte tags, lengths and varints are read inline, the limits are checked once per tag.
        while ($offset < $end) {
            if (++$this->tags > $this->maxTags || $this->elements > $this->maxElements || $this->records > $this->maxRecords) {
                return;
            }

            $tag = \ord($bytes[$offset]);
            if ($tag < 0x80) {
                ++$offset;
            } elseif ($offset + 1 < $end && ($next = \ord($bytes[$offset + 1])) < 0x80) {
                $tag = ($tag & 0x7F) | ($next << 7);
                $offset += 2;
            } else {
                $tag = $this->varint($offset, $end, 5);
            }
            $number = $tag >> 3;
            ($number >= 1 && $number <= self::MAX_FIELD_NUMBER) or throw self::malformed('field number out of range');

            switch ($tag & 7) {
                case self::WIRE_VARINT:
                    $offset < $end && \ord($bytes[$offset]) < 0x80 ? ++$offset : $this->varint($offset, $end, 10);
                    break;
                case self::WIRE_FIXED64:
                    $offset += 8;
                    break;
                case self::WIRE_FIXED32:
                    $offset += 4;
                    break;
                case self::WIRE_LEN:
                    $length = $offset < $end ? \ord($bytes[$offset]) : 0x80;
                    $length < 0x80 ? ++$offset : $length = $this->varint($offset, $end, 10);
                    $length <= $end - $offset or throw self::malformed('truncated field');
                    $field = $schema[$number] ?? null;
                    $field === null or $this->field($field, $offset, $offset + $length, $depth);
                    $offset += $length;
                    break;
                default:
                    // Groups are not used by OTLP, and some parsers skip them.
                    throw self::malformed('unsupported wire type');
            }

            $offset <= $end or throw self::malformed('truncated field');
        }
    }

    /**
     * @param Field $field
     */
    private function field(array $field, int $from, int $to, int $depth): void
    {
        if ($field[0] !== null) {
            $field[1] === self::SINGLE or ++$this->elements;
            $field[1] === self::RECORD and ++$this->records;
            // An empty message has nothing to walk into.
            $from < $to and $this->walk($field[0], $from, $to, $depth + 1);
            return;
        }

        // A packed array: fixed-size values, or varints, which end on a byte below 0x80
        $size = $field[2] ?? 0;
        $values = 0;
        if ($size > 0) {
            $values = \intdiv($to - $from, $size);
        } else {
            for ($i = $from; $i < $to; ++$i) {
                \ord($this->bytes[$i]) < 0x80 and ++$values;
            }
        }
        $this->elements += \max(0, \intdiv($values + $this->packedPerElement - 1, $this->packedPerElement));
    }

    /**
     * Reads a varint at `$offset` and moves the offset past it.
     *
     * @param int<1, 10> $maxBytes
     * @return int<0, max> The 64th bit is dropped: tags and lengths never use it, and other values are only skipped.
     */
    private function varint(int &$offset, int $end, int $maxBytes): int
    {
        $value = 0;
        for ($i = 0; $i < $maxBytes && $offset < $end; ++$i) {
            $byte = \ord($this->bytes[$offset++]);
            $i < 9 and $value |= ($byte & 0x7F) << (7 * $i);
            if ($byte < 0x80) {
                \assert($value >= 0);
                return $value;
            }
        }

        throw self::malformed($offset < $end ? 'varint too long' : 'truncated varint');
    }
}
