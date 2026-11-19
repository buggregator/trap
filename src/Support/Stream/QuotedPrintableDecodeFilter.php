<?php

declare(strict_types=1);

namespace Buggregator\Trap\Support\Stream;

/**
 * Decodes quoted-printable data as {@see quoted_printable_decode()} does: malformed sequences stay as they are.
 *
 * Not the built-in `convert.quoted-printable-decode` filter: it drops the data and warns on a malformed sequence.
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
            $length = \strlen($this->buffer);
            // An `=` in the last two bytes may start an escape or a soft line break that the next data ends
            $tail = 0;
            if (!$closing) {
                $length > 0 && $this->buffer[$length - 1] === '=' and $tail = 1;
                $length > 1 && $this->buffer[$length - 2] === '=' and $tail = 2;
            }
            $bucket->data = \quoted_printable_decode(\substr($this->buffer, 0, $length - $tail));
            $this->buffer = \substr($this->buffer, $length - $tail);

            \stream_bucket_append($out, $bucket);
        }

        if ($closing && $this->buffer !== '' && $this->stream !== null) {
            $bucket = \stream_bucket_new($this->stream, \quoted_printable_decode($this->buffer));
            $this->buffer = '';
            \stream_bucket_append($out, $bucket);
        }

        return PSFS_PASS_ON;
    }
}
