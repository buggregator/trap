<?php

declare(strict_types=1);

namespace Buggregator\Trap\Support;

/**
 * @internal
 * @psalm-internal Buggregator\Trap
 */
final class Charset
{
    /**
     * Tries mbstring, then iconv: either extension may be missing, and each knows charsets the other lacks,
     * as mbstring lacks windows-1250.
     *
     * @return string|null The converted value, or null if neither knows the charset.
     */
    public static function toUtf8(string $value, string $charset): ?string
    {
        // A charset unknown to a converter warns or throws: either means trying the next one
        \set_error_handler(static fn(): bool => true);
        try {
            try {
                $converted = \function_exists('mb_convert_encoding')
                    ? \mb_convert_encoding($value, 'UTF-8', $charset)
                    : false;
            } catch (\ValueError) {
                $converted = false;
            }

            if (!\is_string($converted) && \function_exists('iconv')) {
                $converted = \iconv($charset, 'UTF-8', $value);
                // Not //IGNORE first: glibc's iconv returns false with it whenever it drops bytes
                \is_string($converted) or $converted = \iconv($charset, 'UTF-8//IGNORE', $value);
            }
        } finally {
            \restore_error_handler();
        }

        return \is_string($converted) ? $converted : null;
    }
}
