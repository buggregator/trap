<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Mcp\Tool;

/**
 * A case-insensitive pattern for a whole value, where `*` stands for any characters.
 *
 * @internal
 */
final class Wildcard
{
    /**
     * @param non-empty-string $regex
     */
    private function __construct(
        private readonly string $regex,
    ) {}

    public static function compile(string $pattern): self
    {
        $parts = \array_map(static fn(string $part): string => \preg_quote($part, '/'), \explode('*', $pattern));

        // Without `u` a pattern that is not UTF-8 still compiles, and then case folding is ASCII only
        return new self('/^' . \implode('.*', $parts) . '$/is' . (\mb_check_encoding($pattern, 'UTF-8') ? 'u' : ''));
    }

    public function matches(string $value): bool
    {
        return \preg_match($this->regex, $value) === 1;
    }
}
