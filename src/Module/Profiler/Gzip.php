<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler;

/**
 * Decompresses a gzip-compressed profile within a size limit.
 *
 * @internal
 */
final class Gzip
{
    private const MAGIC = "\x1F\x8B";

    public static function isCompressed(string $content): bool
    {
        return \str_starts_with($content, self::MAGIC);
    }

    /**
     * @param positive-int $maxSize Limit of the decompressed size in bytes.
     *
     * @throws \InvalidArgumentException When the data is not gzip, decompresses past the limit,
     *         or the server has no zlib extension.
     */
    public static function inflate(string $content, int $maxSize): string
    {
        \function_exists('gzdecode') or throw new \InvalidArgumentException(
            'The profile is gzip-compressed, but the server has no zlib extension: send it decompressed.',
        );

        $tooLarge = "The profile decompresses to more than {$maxSize} bytes.";
        $error = '';
        \set_error_handler(static function (int $code, string $message) use (&$error): bool {
            $error = $message;
            return true;
        });
        try {
            $inflated = \gzdecode($content, $maxSize);
        } finally {
            \restore_error_handler();
        }

        if ($inflated === false) {
            throw new \InvalidArgumentException(
                \str_contains($error, 'insufficient memory') ? $tooLarge : 'Invalid gzip data.',
            );
        }
        // The limit is checked by the chunks zlib inflates
        \strlen($inflated) <= $maxSize or throw new \InvalidArgumentException($tooLarge);

        return $inflated;
    }
}
