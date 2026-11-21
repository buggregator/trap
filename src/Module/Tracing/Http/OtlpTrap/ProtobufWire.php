<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Tracing\Http\OtlpTrap;

use Google\Protobuf\Descriptor;
use Google\Protobuf\DescriptorPool;
use Google\Protobuf\Internal\Message;
use Opentelemetry\Proto\Collector\Logs\V1\ExportLogsServiceRequest;
use Opentelemetry\Proto\Collector\Metrics\V1\ExportMetricsServiceRequest;
use Opentelemetry\Proto\Collector\Trace\V1\ExportTraceServiceRequest;

/**
 * Checks an OTLP protobuf message on the wire before it is built: building it takes time and memory
 * in proportion to its elements, and a 10 MB body of 2-byte attributes holds millions of them.
 *
 * The walk follows the schema of the bundled generated classes, read from their descriptors, into every
 * message-typed field, and counts:
 * - records (spans, log records, metric data points) against the record limit of the signal;
 * - elements: every occurrence of a repeated field of any type, every unknown field, and the values of
 *   packed arrays (8 per element with `ext-protobuf`, 1 without), against {@see self::maxElements()};
 * - tags it reads against {@see self::maxTags()}, which bounds the walk and the parse of known fields;
 * - bytes of strings and bytes values once converted to JSON against {@see self::MAX_TEXT}: the decoders
 *   convert the message to JSON, where a control character takes 6 bytes and bytes are base64.
 *
 * It fails closed: what it cannot read for sure a parser might still accept, so groups, unknown wire types,
 * tags over 5 bytes, field numbers out of range, truncated values and nesting deeper than
 * {@see self::MAX_DEPTH} messages make the message malformed.
 *
 * The pure-PHP `google/protobuf` builds a message some 50 times slower than `ext-protobuf`: without the
 * extension the limits are lower.
 *
 * @internal
 * @psalm-internal Buggregator\Trap\Module\Tracing\Http
 *
 * @psalm-type Field = array{0: non-empty-string|null, 1: bool, 2: int<-1, 8>, 3: bool, 4: int<0, 2>}
 *         Message of the field or null; repeated; bytes of a packed value (0 for varints, -1 when not packable);
 *         whether an element is a record; whether it is a string (1) or bytes (2).
 */
final class ProtobufWire
{
    /**
     * Elements of one request with `ext-protobuf`: a Collector batch of 10 000 spans with 20 attributes each.
     * An attribute is 2 JSON objects once converted, so the request stays within
     * {@see JsonReader::MAX_CONTAINERS}.
     */
    public const MAX_ELEMENTS = 240_000;

    /**
     * Elements of one request with the pure-PHP `google/protobuf`: an SDK batch of 512 spans with 30 attributes.
     */
    public const MAX_ELEMENTS_PURE_PHP = 20_000;

    /** Tags read in one request with `ext-protobuf`. */
    public const MAX_TAGS = 2_000_000;

    /** Tags read in one request with the pure-PHP `google/protobuf`. */
    public const MAX_TAGS_PURE_PHP = 200_000;

    /**
     * Bytes of strings and bytes values in the JSON the request converts to, with `ext-protobuf`.
     */
    public const MAX_TEXT = 40 * 1024 * 1024;

    /**
     * Bytes of strings and bytes values in the JSON the request converts to, with the pure-PHP `google/protobuf`,
     * which writes them byte by byte.
     */
    public const MAX_TEXT_PURE_PHP = 16 * 1024 * 1024;

    /**
     * Messages nested in each other. A log body of key-value lists nests 3 messages per level, so about
     * 20 levels fit.
     */
    public const MAX_DEPTH = 64;

    private const REQUESTS = [
        'traces' => ExportTraceServiceRequest::class,
        'logs' => ExportLogsServiceRequest::class,
        'metrics' => ExportMetricsServiceRequest::class,
    ];
    private const RECORDS = [
        'opentelemetry.proto.trace.v1.Span',
        'opentelemetry.proto.logs.v1.LogRecord',
        'opentelemetry.proto.metrics.v1.NumberDataPoint',
        'opentelemetry.proto.metrics.v1.HistogramDataPoint',
        'opentelemetry.proto.metrics.v1.ExponentialHistogramDataPoint',
        'opentelemetry.proto.metrics.v1.SummaryDataPoint',
    ];

    // GPBType values
    private const TYPE_MESSAGE = 11;
    private const TYPE_GROUP = 10;
    private const TYPE_STRING = 9;
    private const TYPE_BYTES = 12;
    private const FIXED_SIZES = [1 => 8, 2 => 4, 6 => 8, 7 => 4, 15 => 4, 16 => 8];
    private const TEXT_NONE = 0;
    private const TEXT_STRING = 1;
    private const TEXT_BYTES = 2;
    private const MAX_FIELD_NUMBER = (1 << 29) - 1;
    private const WIRE_VARINT = 0;
    private const WIRE_FIXED64 = 1;
    private const WIRE_LEN = 2;
    private const WIRE_FIXED32 = 5;

    /** @var array<non-empty-string, array<int, Field>> Message full name => field number => field */
    private static array $schema = [];

    /** @var int<0, max> */
    private int $records = 0;

    /** @var int<0, max> */
    private int $elements = 0;

    /** @var int<0, max> */
    private int $tags = 0;

    /** @var int<0, max> */
    private int $text = 0;

    /**
     * @param int<1, max> $maxRecords
     * @param int<1, max> $maxElements
     * @param int<1, max> $maxTags
     * @param int<1, max> $maxText
     * @param int<1, 8> $packedPerElement Packed values counted as one element.
     */
    private function __construct(
        private readonly string $bytes,
        private readonly int $maxRecords,
        private readonly int $maxElements,
        private readonly int $maxTags,
        private readonly int $maxText,
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
     * @return int<1, max>
     */
    public static function maxTags(): int
    {
        return \extension_loaded('protobuf') ? self::MAX_TAGS : self::MAX_TAGS_PURE_PHP;
    }

    /**
     * @param 'traces'|'logs'|'metrics' $signal
     * @param int<1, max> $maxRecords
     * @param string $records What the records are, for the error message.
     * @param int<1, max>|null $maxElements {@see self::maxElements()} when null.
     * @param int<1, max>|null $maxTags {@see self::maxTags()} when null.
     * @param int<1, max>|null $maxText Bytes of text in JSON; {@see self::MAX_TEXT} or
     *        {@see self::MAX_TEXT_PURE_PHP} when null.
     *
     * @throws LimitExceededException When the message has more records, elements, tags or text than accepted.
     * @throws \InvalidArgumentException When the message is malformed.
     */
    public static function check(
        string $payload,
        string $signal,
        int $maxRecords,
        string $records,
        ?int $maxElements = null,
        ?int $maxTags = null,
        ?int $maxText = null,
    ): void {
        $extension = \extension_loaded('protobuf');
        $maxElements ??= self::maxElements();
        $maxTags ??= self::maxTags();
        $maxText ??= $extension ? self::MAX_TEXT : self::MAX_TEXT_PURE_PHP;
        $root = self::schema(self::REQUESTS[$signal]);

        $wire = new self($payload, $maxRecords, $maxElements, $maxTags, $maxText, $extension ? 8 : 1);
        $wire->walk($root, 0, \strlen($payload), 0);

        $wire->records > $maxRecords and throw LimitExceededException::records($maxRecords, $records);
        $wire->elements > $maxElements and throw LimitExceededException::elements($maxElements, $extension);
        $wire->tags > $maxTags and throw LimitExceededException::fields($maxTags);
        $wire->text > $maxText and throw LimitExceededException::text($maxText, $extension);
    }

    /**
     * Reads the schema of a request class and of every message under it, once per process.
     *
     * @param class-string<Message> $class
     * @return non-empty-string Full name of the message of the class.
     */
    private static function schema(string $class): string
    {
        // Building a message registers the descriptors of its file.
        match ($class) {
            ExportLogsServiceRequest::class => new ExportLogsServiceRequest(),
            ExportMetricsServiceRequest::class => new ExportMetricsServiceRequest(),
            default => new ExportTraceServiceRequest(),
        };
        /** @var Descriptor|null $descriptor */
        $descriptor = DescriptorPool::getGeneratedPool()->getDescriptorByClassName($class);
        $descriptor === null and throw new \LogicException("No descriptor of $class.");
        self::describe($descriptor);

        /** @var non-empty-string */
        return $descriptor->getFullName();
    }

    private static function describe(Descriptor $descriptor): void
    {
        /** @var non-empty-string $name */
        $name = $descriptor->getFullName();
        if (isset(self::$schema[$name])) {
            return;
        }

        self::$schema[$name] = [];
        $fields = [];
        for ($i = 0, $count = $descriptor->getFieldCount(); $i < $count; ++$i) {
            $field = $descriptor->getField($i);
            $type = (int) $field->getType();
            $message = null;
            if ($type === self::TYPE_MESSAGE || $type === self::TYPE_GROUP) {
                /** @var Descriptor|null $child */
                $child = $field->getMessageType();
                // Read as scalars, its content would go uncounted.
                $child === null and throw new \LogicException("No descriptor of the message of $name.{$field->getName()}.");
                self::describe($child);
                /** @var non-empty-string $message */
                $message = $child->getFullName();
            }

            $packable = $message === null && $type !== self::TYPE_STRING && $type !== self::TYPE_BYTES;
            $fields[(int) $field->getNumber()] = [
                $message,
                (bool) $field->isRepeated(),
                $packable ? self::FIXED_SIZES[$type] ?? 0 : -1,
                $message !== null && \in_array($message, self::RECORDS, true),
                match ($type) {
                    self::TYPE_STRING => self::TEXT_STRING,
                    self::TYPE_BYTES => self::TEXT_BYTES,
                    default => self::TEXT_NONE,
                },
            ];
        }

        self::$schema[$name] = $fields;
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
        $schema = self::$schema[$message] ?? [];
        $bytes = $this->bytes;

        // Hot loop: one- and two-byte tags, one-byte lengths and varints are read inline;
        // the limits are checked once per tag.
        while ($offset < $end) {
            if (++$this->tags > $this->maxTags
                || $this->elements > $this->maxElements
                || $this->records > $this->maxRecords
                || $this->text > $this->maxText
            ) {
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

            $field = $schema[$number] ?? null;
            // An unknown field, or an occurrence of a repeated one; packed arrays are counted below.
            ($field === null || ($field[1] && ($tag & 7) !== self::WIRE_LEN) || ($field[1] && $field[2] < 0))
                and ++$this->elements;

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
     * A length-delimited field: a message to walk into, a packed array, or a string.
     *
     * @param Field $field
     */
    private function field(array $field, int $from, int $to, int $depth): void
    {
        if ($field[0] !== null) {
            $field[3] and ++$this->records;
            // An empty message has nothing to walk into.
            $from < $to and $this->walk($field[0], $from, $to, $depth + 1);
            return;
        }

        if ($field[4] !== self::TEXT_NONE) {
            $this->text += $this->jsonLength($field[4], $from, $to);
            return;
        }

        if (!$field[1] || $field[2] < 0) {
            return;
        }

        // A packed array: fixed-size values, or varints, which end on a byte below 0x80
        $values = 0;
        if ($field[2] > 0) {
            $values = \intdiv($to - $from, $field[2]);
        } else {
            for ($i = $from; $i < $to; ++$i) {
                \ord($this->bytes[$i]) < 0x80 and ++$values;
            }
        }
        $this->elements += \max(0, \intdiv($values + $this->packedPerElement - 1, $this->packedPerElement));
    }

    /**
     * Bytes of a string or bytes value in the JSON the message converts to: a control character becomes
     * a 6-byte `\u0001`, bytes become base64.
     *
     * @param self::TEXT_STRING|self::TEXT_BYTES $kind
     * @return int<0, max>
     */
    private function jsonLength(int $kind, int $from, int $to): int
    {
        $length = $to - $from;
        if ($kind === self::TEXT_BYTES) {
            return \max(0, \intdiv($length + 2, 3) * 4);
        }

        $controls = $length === 0 ? 0 : (int) \preg_match_all('/[\x00-\x1F]/', \substr($this->bytes, $from, $length));

        return \max(0, $length + 5 * $controls);
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
