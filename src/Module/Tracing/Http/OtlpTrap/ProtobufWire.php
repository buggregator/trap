<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Tracing\Http\OtlpTrap;

/**
 * Counts records of a protobuf message on the wire, without building it: building a message of a million
 * empty records with the pure-PHP `google/protobuf` takes over a minute and hundreds of megabytes.
 *
 * It fails closed: whatever it cannot read for sure, a protobuf parser might still accept and spend
 * that time on. So a group, an unknown wire type, a tag of more than 5 bytes, a field number out of range,
 * and a truncated value make the message malformed, and more than {@see self::FIELDS_PER_RECORD} fields
 * scanned per accepted record make it too big.
 *
 * @internal
 * @psalm-internal Buggregator\Trap\Module\Tracing\Http
 */
final class ProtobufWire
{
    /**
     * Fields scanned per accepted record, plus {@see self::BASE_FIELDS}: enough for the resources, scopes
     * and metric descriptions of real exports, far too few for a body of millions of tiny fields.
     */
    public const FIELDS_PER_RECORD = 10;

    private const BASE_FIELDS = 1000;
    private const MAX_FIELD_NUMBER = (1 << 29) - 1;
    private const WIRE_VARINT = 0;
    private const WIRE_FIXED64 = 1;
    private const WIRE_LEN = 2;
    private const WIRE_FIXED32 = 5;

    /** @var int<0, max> */
    private int $count = 0;

    /** @var int<0, max> */
    private int $scanned = 0;

    /**
     * @param int<1, max> $limit
     */
    private function __construct(
        private readonly string $bytes,
        private readonly int $limit,
    ) {}

    /**
     * Counts the messages at the end of a path of nested length-delimited fields; stops past `$limit`.
     *
     * @param non-empty-list<non-empty-list<int>> $path Field numbers taken at each level, as
     *        `[[1], [2], [2]]` for `resource_spans[].scope_spans[].spans[]`.
     * @param int<1, max> $limit
     * @return int<0, max> At most `$limit + 1`; `$limit + 1` also when the scan limit is reached.
     *
     * @throws \InvalidArgumentException When the message is malformed.
     */
    public static function count(string $payload, array $path, int $limit): int
    {
        $wire = new self($payload, $limit);
        $wire->walk(0, \strlen($payload), $path);

        return \min($wire->count, $limit + 1);
    }

    private static function malformed(string $reason): \InvalidArgumentException
    {
        return new \InvalidArgumentException("Invalid protobuf message: $reason.");
    }

    /**
     * @param list<non-empty-list<int>> $path
     */
    private function walk(int $from, int $to, array $path): void
    {
        $numbers = \array_shift($path);
        if ($numbers === null) {
            return;
        }

        foreach ($this->fields($from, $to, $numbers) as [$fieldFrom, $fieldTo]) {
            if ($path === []) {
                ++$this->count;
            } else {
                $this->walk($fieldFrom, $fieldTo, $path);
            }

            if ($this->count > $this->limit) {
                return;
            }
        }
    }

    /**
     * Length-delimited fields of the given numbers in a message that spans `$offset..$end` of the bytes.
     *
     * @param list<int> $numbers
     * @return \Generator<int, array{int, int}> Offsets of the start and the end of each field value.
     */
    private function fields(int $offset, int $end, array $numbers): \Generator
    {
        while ($offset < $end) {
            if (++$this->scanned > $this->limit * self::FIELDS_PER_RECORD + self::BASE_FIELDS) {
                // Too many fields to read in time: as over the limit.
                $this->count = $this->limit + 1;
                return;
            }

            $tag = $this->varint($offset, $end, 5);
            $number = $tag >> 3;
            ($number >= 1 && $number <= self::MAX_FIELD_NUMBER) or throw self::malformed('field number out of range');

            switch ($tag & 7) {
                case self::WIRE_VARINT:
                    $this->varint($offset, $end, 10);
                    break;
                case self::WIRE_FIXED64:
                    $offset += 8;
                    break;
                case self::WIRE_FIXED32:
                    $offset += 4;
                    break;
                case self::WIRE_LEN:
                    $length = $this->varint($offset, $end, 10);
                    $length <= $end - $offset or throw self::malformed('truncated field');
                    \in_array($number, $numbers, true) and yield [$offset, $offset + $length];
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
