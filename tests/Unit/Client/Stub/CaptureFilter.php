<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Client\Stub;

/**
 * Stream filter that swallows everything written through it into {@see self::$captured}.
 */
final class CaptureFilter extends \php_user_filter
{
    public static string $captured = '';

    public function filter($in, $out, &$consumed, bool $closing): int
    {
        while ($bucket = \stream_bucket_make_writeable($in)) {
            self::$captured .= $bucket->data;
            $consumed += $bucket->datalen;
        }

        return \PSFS_PASS_ON;
    }
}
