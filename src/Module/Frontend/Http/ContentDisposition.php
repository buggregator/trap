<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Frontend\Http;

/**
 * @internal
 */
final class ContentDisposition
{
    /**
     * A download of the file: its name as is in `filename*` (RFC 6266), and in ASCII in `filename`
     * for the clients that take only that one.
     *
     * @param string|null $name `unnamed` when empty or null.
     */
    public static function attachment(?string $name): string
    {
        $name === null || $name === '' and $name = 'unnamed';
        // A character of UTF-8 or, in a name of another encoding, a byte
        $ascii = \preg_replace('/[^\x20-\x7E]/u', '_', $name) ?? (string) \preg_replace('/[^\x20-\x7E]/', '_', $name);

        return \sprintf(
            'attachment; filename="%s"; filename*=UTF-8\'\'%s',
            \addcslashes($ascii, '"\\'),
            \rawurlencode($name),
        );
    }
}
