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
        // Buffer size
        $bs = \strlen($this->buffer);

        while ($bucket = \stream_bucket_make_writeable($in)) {
            /** @var string $data */
            $data = $bucket->data;
            /** @var int<0, max> $dataLen */
            $dataLen = $bucket->datalen;
            /** @var int<1, max> $len */
            $len = $bs + $dataLen;
            $d = $len % 4;

            if ($d === 0 || $closing) {
                $bucket->data = \base64_decode($this->buffer . $data, true);
                $consumed += $dataLen;
                $this->buffer = '';

                // Send the decoded data to the next bucket
                \stream_bucket_append($out, $bucket);

                continue;
            }

            if ($len < 4) {
                $this->buffer .= $data;
                $consumed += $dataLen;

                continue;
            }

            // Decode part of the data
            $bucket->data = \base64_decode($this->buffer . \substr($data, 0, -$d), true);
            $consumed += $dataLen;
            /** @psalm-suppress MixedArgument Bug #239: the rest is taken from the decoded data instead of the raw input */
            $this->buffer = \substr($bucket->data, -$d);

            // Send the decoded data to the next bucket
            \stream_bucket_append($out, $bucket);
        }

        return PSFS_PASS_ON;
    }
}
