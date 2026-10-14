<?php

declare(strict_types=1);

namespace Buggregator\Trap\Support\Stream;

/**
 * @internal
 * @psalm-internal Buggregator\Trap
 *
 * @link https://www.php.net/manual/function.stream-filter-register.php
 */
final class Base64DecodeFilter extends \php_user_filter
{
    public const FILTER_NAME = 'trap.base64.decode';

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

            // MIME wraps base64 lines with CRLF: line breaks must not count towards the 4-character groups
            $this->buffer .= \str_replace(["\r", "\n", "\t", " "], '', $data);
            $tail = $closing ? 0 : \strlen($this->buffer) % 4;
            $bucket->data = \base64_decode(\substr($this->buffer, 0, \strlen($this->buffer) - $tail), true);
            $this->buffer = \substr($this->buffer, \strlen($this->buffer) - $tail);

            \stream_bucket_append($out, $bucket);
        }

        if ($closing && $this->buffer !== '' && $this->stream !== null) {
            $bucket = \stream_bucket_new($this->stream, (string) \base64_decode($this->buffer, true));
            $this->buffer = '';
            \stream_bucket_append($out, $bucket);
        }

        return PSFS_PASS_ON;
    }
}
