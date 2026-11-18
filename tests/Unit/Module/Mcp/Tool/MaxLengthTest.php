<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Mcp\Tool;

use Buggregator\Trap\Module\Mcp\Tool\MaxLength;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(MaxLength::class)]
final class MaxLengthTest
{
    public function testSchemaWithoutMaximum(): void
    {
        Assert::same(MaxLength::schema(), [
            'type' => 'integer',
            'minimum' => 100,
            'default' => 50_000,
            'description' => 'The most characters of the text to return; a longer text is cut with a notice.',
        ]);
    }

    public function testSchemaWithMaximum(): void
    {
        Assert::same(MaxLength::schema(1_000, 'Characters.', 5_000), [
            'type' => 'integer',
            'minimum' => 100,
            'maximum' => 5_000,
            'default' => 1_000,
            'description' => 'Characters.',
        ]);
    }

    #[DataSet([[], 1_000], 'missing takes the default')]
    #[DataSet([['max_length' => 100], 100], 'minimum')]
    #[DataSet([['max_length' => 2_000], 2_000], 'maximum')]
    public function testFromArguments(array $arguments, int $expected): void
    {
        Assert::same(MaxLength::fromArguments($arguments, 1_000, 2_000), $expected);
    }

    #[DataSet([99], 'below minimum')]
    #[DataSet([2_001], 'above maximum')]
    public function testFromArgumentsRejectsOutOfRange(int $value): never
    {
        Expect::exception(\InvalidArgumentException::class)
            ->withMessage('`max_length` must be an integer from 100 to 2000.');

        MaxLength::fromArguments(['max_length' => $value], 1_000, 2_000);
    }

    #[DataSet(['абв', 3], 'exactly the limit')]
    #[DataSet(['аб', 3], 'shorter')]
    public function testShortTextIsWhole(string $text, int $maxLength): void
    {
        Assert::same(MaxLength::cut($text, $maxLength), $text);
    }

    #[DataSet(['абвгд', 3, null, false, "абв\n\n[Truncated: 3 of 5 characters shown. Call again with a larger `max_length` for more.]"], 'in characters, not bytes')]
    #[DataSet(['абвгд', 3, 10, false, "абв\n\n[Truncated: 3 of 10 characters shown. Call again with a larger `max_length` for more.]"], 'start of a longer text')]
    #[DataSet(['абв', 3, 3, true, "абв\n\n[Truncated: 3 of at least 3 characters shown. Call again with a larger `max_length` for more.]"], 'not built past the limit')]
    public function testLongTextIsCutWithNotice(string $text, int $maxLength, ?int $length, bool $more, string $expected): void
    {
        Assert::same(MaxLength::cut($text, $maxLength, $length, $more), $expected);
    }
}
