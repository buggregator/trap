<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Mcp\Tool;

/**
 * The `max_length` argument of the tools that return a long text.
 *
 * @internal
 */
final class MaxLength
{
    /** Characters of the text when the client sets no limit. */
    public const DEFAULT = 50_000;

    public const MIN = 100;

    /**
     * @param int<100, max> $default
     *
     * @return array<non-empty-string, mixed> The property of the input schema.
     */
    public static function schema(
        int $default = self::DEFAULT,
        string $description = 'The most characters of the text to return; a longer text is cut with a notice.',
    ): array {
        return [
            'type' => 'integer',
            'minimum' => self::MIN,
            'default' => $default,
            'description' => $description,
        ];
    }

    /**
     * @param array<array-key, mixed> $arguments
     * @param int<100, max> $default
     *
     * @return int<0, max>
     *
     * @throws \InvalidArgumentException
     */
    public static function fromArguments(array $arguments, int $default = self::DEFAULT): int
    {
        return Arguments::integer($arguments, 'max_length', self::MIN) ?? $default;
    }

    /**
     * Cuts the text to `$maxLength` characters and tells the model how to get more.
     */
    public static function cut(string $text, int $maxLength): string
    {
        $length = \mb_strlen($text);
        if ($length <= $maxLength) {
            return $text;
        }

        return \mb_substr($text, 0, $maxLength)
            . "\n\n[Truncated: $maxLength of $length characters shown. Call again with a larger `max_length` for more.]";
    }
}
