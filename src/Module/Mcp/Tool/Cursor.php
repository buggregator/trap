<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Mcp\Tool;

/**
 * The opaque cursor of the listing tools: `<store ID>:<sequence number>`. The store ID is random per
 * Trap process, so a cursor of an earlier run reads as no cursor.
 *
 * @internal
 */
final class Cursor
{
    /**
     * @return non-empty-string
     */
    public static function encode(string $storeId, int $seq): string
    {
        return "$storeId:$seq";
    }

    /**
     * Reads the `since` argument.
     *
     * @param array<array-key, mixed> $arguments
     * @param int<0, max> $last The current sequence number of the store.
     *
     * @return int<0, max> The sequence number; 0, for everything, when the cursor is missing, empty,
     *         malformed, of another Trap process, or ahead of the store.
     *
     * @throws \InvalidArgumentException
     */
    public static function since(array $arguments, string $storeId, int $last): int
    {
        /** @var mixed $since */
        $since = $arguments['since'] ?? '';
        \is_string($since) or throw new \InvalidArgumentException('`since` must be a string.');

        [$id, $seq] = \explode(':', $since, 2) + [1 => ''];
        if ($id !== $storeId || !\ctype_digit($seq)) {
            return 0;
        }

        $seq = (int) $seq;

        return $seq >= 0 && $seq <= $last ? $seq : 0;
    }
}
