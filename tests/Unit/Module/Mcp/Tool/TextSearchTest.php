<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Mcp\Tool;

use Buggregator\Trap\Module\Mcp\Tool\TextSearch;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(TextSearch::class)]
final class TextSearchTest
{
    public function testSchema(): void
    {
        $schema = TextSearch::schema('events');

        Assert::same(\array_keys($schema), ['search', 'regex']);
        Assert::same($schema['search']['type'], 'string');
        Assert::same($schema['search']['minLength'], 1);
        Assert::same($schema['search']['description'], 'Only the events that contain this text, in any case.');
        Assert::same($schema['regex']['type'], 'boolean');
        Assert::false($schema['regex']['default']);
    }

    public function testNoSearch(): void
    {
        Assert::null(TextSearch::fromArguments([]));
        Assert::null(TextSearch::fromArguments(['regex' => false]));
    }

    #[DataSet(['order_id', '"ORDER_ID" => 42', true], 'any case')]
    #[DataSet(['Заказ', 'новый заказ', true], 'Unicode case')]
    #[DataSet(['order_id', 'order-id', false], 'absent')]
    #[DataSet(['a.c', 'abc', false], 'regex characters are literal')]
    public function testSubstring(string $search, string $text, bool $expected): void
    {
        Assert::same(TextSearch::fromArguments(['search' => $search])?->matches($text), $expected);
    }

    #[DataSet(['order_id\W+\d+', '"ORDER_ID" => 42', true], 'pattern in any case')]
    #[DataSet(['^b$', "a\nb", false], 'anchors take the whole text')]
    #[DataSet(['a/b~c', 'a/b~c', true], 'slash and tilde need no escaping')]
    #[DataSet(['^\d+$', '42', true], 'digits')]
    public function testRegex(string $search, string $text, bool $expected): void
    {
        Assert::same(TextSearch::fromArguments(['search' => $search, 'regex' => true])?->matches($text), $expected);
    }

    #[DataSet(['x', "x\xFF", true], 'ASCII')]
    #[DataSet(['заказ', "\xFFНовый ЗАКАЗ", true], 'Unicode case')]
    #[DataSet(['y', "x\xFF", false], 'absent')]
    public function testTextNotInUtf8IsSearchedAsBySubstring(string $search, string $text, bool $expected): void
    {
        $regex = TextSearch::fromArguments(['search' => $search, 'regex' => true]);
        $substring = TextSearch::fromArguments(['search' => $search]);

        Assert::same($regex?->matches($text), $expected);
        Assert::same($substring?->matches($text), $expected);
    }

    #[DataSet(["a\x01b", "a\x01b", true], 'delimiter')]
    #[DataSet(["a\\\x01b", "a\x01b", true], 'escaped delimiter')]
    #[DataSet(['a\\\\', 'a\\', true], 'escaped backslash at the end')]
    #[DataSet(["[\x01]", "\x01", true], 'delimiter in a class')]
    public function testDelimiterNeedsNoEscaping(string $search, string $text, bool $expected): void
    {
        Assert::same(TextSearch::fromArguments(['search' => $search, 'regex' => true])?->matches($text), $expected);
    }

    #[DataSet(['abc\\'], 'one')]
    #[DataSet(['abc\\\\\\'], 'three')]
    public function testTrailingBackslashIsRejected(string $search): never
    {
        Expect::exception(\InvalidArgumentException::class)
            ->withMessage('`search` is not a valid regular expression: \ at end of pattern.');

        TextSearch::fromArguments(['search' => $search, 'regex' => true]);
    }

    public function testInvalidRegexIsRejected(): never
    {
        Expect::exception(\InvalidArgumentException::class)
            ->withMessageMatchingRegex('/^`search` is not a valid regular expression: .*missing closing parenthesis/');

        TextSearch::fromArguments(['search' => '(unclosed', 'regex' => true]);
    }

    public function testCatastrophicRegexFailsTheCall(): never
    {
        $limit = \ini_get('pcre.backtrack_limit');
        $jit = \ini_get('pcre.jit');
        // Without JIT the limit is of backtracking: the error is the same on every platform
        \ini_set('pcre.backtrack_limit', '1000');
        \ini_set('pcre.jit', '0');
        $search = TextSearch::fromArguments(['search' => '(a+)+$', 'regex' => true]);

        Expect::exception(\InvalidArgumentException::class)
            ->withMessage('The regular expression in `search` failed: Backtrack limit exhausted. Simplify it, as by avoiding nested quantifiers.');

        try {
            $search?->matches(\str_repeat('a', 40) . 'b');
        } finally {
            \ini_set('pcre.backtrack_limit', (string) $limit);
            \ini_set('pcre.jit', (string) $jit);
        }
    }

    #[DataSet([['search' => ''], '`search` must be a non-empty string.'], 'empty search')]
    #[DataSet([['search' => 1], '`search` must be a non-empty string.'], 'number')]
    #[DataSet([['search' => 'a', 'regex' => 'yes'], '`regex` must be a boolean.'], 'regex not boolean')]
    #[DataSet([['regex' => true], '`regex` needs `search`, the pattern to match.'], 'regex without search')]
    public function testInvalidArguments(array $arguments, string $message): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage($message);

        TextSearch::fromArguments($arguments);
    }
}
