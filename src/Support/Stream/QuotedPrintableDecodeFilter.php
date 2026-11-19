<?php

declare(strict_types=1);

namespace Buggregator\Trap\Support\Stream;

/**
 * Decodes quoted-printable and never fails: a malformed escape stays as written. PHP's own
 * `convert.quoted-printable-decode` fails the write on one, losing the data written after it.
 *
 * Whitespace between a soft line break `=` and its line break is skipped, as RFC 2045 asks.
 *
 * @internal
 * @psalm-internal Buggregator\Trap
 *
 * @link https://www.php.net/manual/function.stream-filter-register.php
 */
final class QuotedPrintableDecodeFilter extends \php_user_filter
{
    public const FILTER_NAME = 'trap.quoted-printable.decode';

    private string $buffer = '';

    /**
     * @param resource $in
     * @param resource $out
     * @param int<0, max> $consumed
     */
    #[\Override]
    public function filter($in, $out, &$consumed, bool $closing): int
    {
        while ($bucket = \stream_bucket_make_writeable($in)) {
            /** @var string $data */
            $data = $bucket->data;
            /** @var int<0, max> $dataLen */
            $dataLen = $bucket->datalen;
            $consumed += $dataLen;

            $this->buffer .= $data;
            $tail = $closing ? 0 : self::incompleteTail($this->buffer);
            $bucket->data = \quoted_printable_decode(\substr($this->buffer, 0, \strlen($this->buffer) - $tail));
            $this->buffer = \substr($this->buffer, \strlen($this->buffer) - $tail);

            \stream_bucket_append($out, $bucket);
        }

        if ($closing && $this->buffer !== '' && $this->stream !== null) {
            $bucket = \stream_bucket_new($this->stream, \quoted_printable_decode($this->buffer));
            $this->buffer = '';
            \stream_bucket_append($out, $bucket);
        }

        return PSFS_PASS_ON;
    }

    /**
     * @return int<0, max> Length of the trailing `=`, `=X`, or `=` with whitespace and a CR, that the next data
     *         may complete into an escape or a soft line break.
     */
    private static function incompleteTail(string $data): int
    {
        $at = \strrpos($data, '=');
        if ($at === false) {
            return 0;
        }

        $tail = \substr($data, $at + 1);
        return \preg_match('/^(?:[0-9A-Fa-f]?|[ \\t]*+\\r?)$/', $tail) === 1 ? \strlen($tail) + 1 : 0;
    }
}
