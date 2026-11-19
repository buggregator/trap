<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Mcp\Tool;

use Buggregator\Trap\Module\Mcp\Tool\Arguments;
use Testo\Assert;
use Testo\Assert\ExpectNoAssertions;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(Arguments::class)]
final class ArgumentsTest
{
    #[DataSet([[], null], 'missing')]
    #[DataSet([['limit' => null], null], 'null')]
    #[DataSet([['limit' => 10], 10], 'integer')]
    #[DataSet([['limit' => 10.0], 10], 'whole float')]
    #[DataSet([['limit' => 5], 5], 'minimum')]
    #[DataSet([['limit' => 20], 20], 'maximum')]
    public function testInteger(array $arguments, ?int $expected): void
    {
        Assert::same(Arguments::integer($arguments, 'limit', 5, 20), $expected);
    }

    #[DataSet([4], 'below minimum')]
    #[DataSet([21], 'above maximum')]
    #[DataSet([10.5], 'fraction')]
    #[DataSet(['10'], 'numeric string')]
    #[DataSet([true], 'boolean')]
    public function testIntegerRejects(mixed $value): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage('`limit` must be an integer from 5 to 20.');

        Arguments::integer(['limit' => $value], 'limit', 5, 20);
    }

    #[DataSet([[], null], 'missing')]
    #[DataSet([['timeout' => 2], 2.0], 'integer')]
    #[DataSet([['timeout' => 2.5], 2.5], 'float')]
    #[DataSet([['timeout' => 0.5], 0.5], 'minimum')]
    public function testNumber(array $arguments, ?float $expected): void
    {
        Assert::same(Arguments::number($arguments, 'timeout', 0.5), $expected);
    }

    #[DataSet([0.4], 'below minimum')]
    #[DataSet([\INF], 'infinite')]
    #[DataSet([\NAN], 'not a number')]
    #[DataSet(['2'], 'numeric string')]
    public function testNumberRejects(mixed $value): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage('`timeout` must be a number of at least 0.5.');

        Arguments::number(['timeout' => $value], 'timeout', 0.5);
    }

    #[DataSet([[], []], 'missing')]
    #[DataSet([['type' => 'smtp'], ['smtp']], 'string')]
    #[DataSet([['type' => ['smtp', 'sentry']], ['smtp', 'sentry']], 'list')]
    public function testStrings(array $arguments, array $expected): void
    {
        Assert::same(Arguments::strings($arguments, 'type'), $expected);
    }

    #[DataSet([[]], 'empty list')]
    #[DataSet([['a' => 'smtp']], 'map')]
    #[DataSet([['smtp', 1]], 'not all strings')]
    #[DataSet([1], 'number')]
    public function testStringsRejects(mixed $value): never
    {
        Expect::exception(\InvalidArgumentException::class)
            ->withMessage('`type` must be a string or a non-empty list of strings.');

        Arguments::strings(['type' => $value], 'type');
    }

    #[DataSet([[], 'asc'], 'missing is the first choice')]
    #[DataSet([['order' => 'desc'], 'desc'], 'given')]
    public function testChoice(array $arguments, string $expected): void
    {
        Assert::same(Arguments::choice($arguments, 'order', ['asc', 'desc']), $expected);
    }

    #[DataSet(['up'], 'unknown')]
    #[DataSet(['ASC'], 'other case')]
    #[DataSet([1], 'not a string')]
    public function testChoiceRejects(mixed $value): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage('`order` must be one of: asc, desc.');

        Arguments::choice(['order' => $value], 'order', ['asc', 'desc']);
    }

    #[DataSet([[], false], 'missing')]
    #[DataSet([['regex' => true], true], 'true')]
    #[DataSet([['regex' => false], false], 'false')]
    public function testBoolean(array $arguments, bool $expected): void
    {
        Assert::same(Arguments::boolean($arguments, 'regex'), $expected);
    }

    public function testBooleanRejects(): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage('`regex` must be a boolean.');

        Arguments::boolean(['regex' => 1], 'regex');
    }

    #[DataSet([['id' => 'a', 'limit' => 1], ['properties' => ['id' => [], 'limit' => []], 'additionalProperties' => false]], 'declared')]
    #[DataSet([[], ['properties' => new \stdClass(), 'additionalProperties' => false]], 'none of none')]
    #[DataSet([['other' => 1], ['properties' => ['id' => []]]], 'others allowed by default')]
    #[DataSet([['other' => 1], ['properties' => ['id' => []], 'additionalProperties' => true]], 'others allowed')]
    #[DataSet([['other' => 1], ['properties' => ['id' => []], 'additionalProperties' => ['type' => 'string']]], 'others of a schema')]
    #[ExpectNoAssertions]
    public function testDeclared(array $arguments, array $schema): void
    {
        Arguments::declared($arguments, $schema, 'tool');
    }

    #[DataSet([['idd' => 'a'], ['properties' => ['id' => []], 'additionalProperties' => false], 'Unknown argument `idd`; `tool` takes: `id`.'], 'one')]
    #[DataSet([['id' => 'a', 'b' => 1, 7 => 2], ['properties' => ['id' => [], 'limit' => []], 'additionalProperties' => false], 'Unknown arguments `b`, `7`; `tool` takes: `id`, `limit`.'], 'several')]
    #[DataSet([['id' => 'a'], ['properties' => new \stdClass(), 'additionalProperties' => false], 'Unknown argument `id`; `tool` takes no arguments.'], 'no properties')]
    #[DataSet([['id' => 'a'], ['additionalProperties' => false], 'Unknown argument `id`; `tool` takes no arguments.'], 'properties missing')]
    public function testDeclaredRejects(array $arguments, array $schema, string $message): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage($message);

        Arguments::declared($arguments, $schema, 'tool');
    }
}
