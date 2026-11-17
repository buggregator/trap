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
    /** The delimiter of the pattern: a control character, so that `/` or `~` need no escaping. */
    private const DELIMITER = "\x01";

    private readonly bool $ascii;

    /**
     * @param non-empty-string|null $regex The pattern with delimiters and flags; null for a substring search.
     */
    private function __construct(
        private readonly string $needle,
        private readonly ?string $regex,
    ) {
        $this->ascii = \mb_check_encoding($needle, 'ASCII');
    }

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
            $regex and throw new \InvalidArgumentException('`regex` needs `search`, the pattern to match.');
            return null;
        }

        \is_string($search) && $search !== '' or throw new \InvalidArgumentException('`search` must be a non-empty string.');
        if (!$regex) {
            return new self($search, null);
        }

        $pattern = self::DELIMITER . self::escapeDelimiter($search) . self::DELIMITER . 'i'
            . (\mb_check_encoding($search, 'UTF-8') ? 'u' : '');
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
            // Byte-wise for an ASCII needle: many times faster on a long text, and the same but for the few
            // non-ASCII letters that fold to ASCII ones, such as the Kelvin sign
            return $this->ascii
                ? \stripos($text, $this->needle) !== false
                : \mb_stripos($text, $this->needle, 0, 'UTF-8') !== false;
        }

        $result = \preg_match($this->regex, $text);
        // A UTF-8 pattern fails on a text that is not UTF-8; its valid parts are searched, as a substring is
        $result === false && \preg_last_error() === \PREG_BAD_UTF8_ERROR
            and $result = \preg_match($this->regex, \mb_scrub($text, 'UTF-8'));

        $result !== false or throw new \InvalidArgumentException(\sprintf(
            'The regular expression in `search` failed: %s. Simplify it, as by avoiding nested quantifiers.',
            \preg_last_error_msg(),
        ));

        return $result === 1;
    }

    /**
     * Escapes the delimiter where the pattern has it, and rejects a trailing backslash, which would escape the
     * closing delimiter: PCRE would report either as an error of a delimiter the client has never written.
     *
     * @throws \InvalidArgumentException
     */
    private static function escapeDelimiter(string $search): string
    {
        $trailing = \strlen($search) - \strlen(\rtrim($search, '\\'));
        $trailing % 2 === 0 or throw new \InvalidArgumentException(
            '`search` is not a valid regular expression: \ at end of pattern.',
        );

        // An escape pair is kept as is: its second character is escaped already
        return (string) \preg_replace_callback(
            '/\\\\.|' . self::DELIMITER . '/s',
            static fn(array $match): string => $match[0] === self::DELIMITER ? '\\' . self::DELIMITER : $match[0],
            $search,
        );
    }
}
