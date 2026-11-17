<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Mcp\Tool;

/**
 * The `search` and `regex` arguments: a case-insensitive substring, or a PCRE pattern without delimiters.
 *
 * @internal
 */
final class TextSearch
{
    /**
     * @param non-empty-string|null $regex The pattern with delimiters and flags; null for a substring search.
     */
    private function __construct(
        private readonly string $needle,
        private readonly ?string $regex,
    ) {}

    /**
     * @return array<non-empty-string, array<non-empty-string, mixed>> Properties of the input schema.
     */
    public static function schema(string $subject): array
    {
        return [
            'search' => [
                'type' => 'string',
                'minLength' => 1,
                'description' => "Only the $subject that contain this text, in any case.",
            ],
            'regex' => [
                'type' => 'boolean',
                'default' => false,
                'description' => '`search` is a PCRE regular expression without delimiters, such as '
                    . '`order_id\W+\d+`, matched in any case.',
            ],
        ];
    }

    /**
     * @param array<array-key, mixed> $arguments
     *
     * @return self|null Null without `search`.
     *
     * @throws \InvalidArgumentException
     */
    public static function fromArguments(array $arguments): ?self
    {
        /** @var mixed $search */
        $search = $arguments['search'] ?? null;
        $regex = Arguments::boolean($arguments, 'regex');
        if ($search === null) {
            return null;
        }

        \is_string($search) && $search !== '' or throw new \InvalidArgumentException('`search` must be a non-empty string.');
        if (!$regex) {
            return new self($search, null);
        }

        // A control character as the delimiter: the pattern needs no escaping of `/` or `~`
        $pattern = "\x01$search\x01i" . (\mb_check_encoding($search, 'UTF-8') ? 'u' : '');
        $error = null;
        \set_error_handler(static function (int $code, string $message) use (&$error): bool {
            $error = (string) \preg_replace('/^preg_match\(\): /', '', $message);
            return true;
        });
        try {
            $valid = \preg_match($pattern, '') !== false;
        } finally {
            \restore_error_handler();
        }

        $valid or throw new \InvalidArgumentException(\sprintf(
            '`search` is not a valid regular expression: %s.',
            $error ?? \preg_last_error_msg(),
        ));

        return new self($search, $pattern);
    }

    /**
     * @throws \InvalidArgumentException When the regular expression hits a PCRE limit, as with nested quantifiers.
     */
    public function matches(string $text): bool
    {
        if ($this->regex === null) {
            return \mb_stripos($text, $this->needle, 0, 'UTF-8') !== false;
        }

        $result = \preg_match($this->regex, $text);
        if ($result !== false) {
            return $result === 1;
        }

        // A text that is not UTF-8 can't match a UTF-8 pattern; any other failure is of the pattern
        \preg_last_error() === \PREG_BAD_UTF8_ERROR or throw new \InvalidArgumentException(\sprintf(
            'The regular expression in `search` failed: %s. Simplify it, as by avoiding nested quantifiers.',
            \preg_last_error_msg(),
        ));

        return false;
    }
}
