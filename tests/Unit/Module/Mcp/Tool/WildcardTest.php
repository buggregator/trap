<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Mcp\Tool;

use Buggregator\Trap\Module\Mcp\Tool\Wildcard;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

#[Test]
#[Covers(Wildcard::class)]
final class WildcardTest
{
    #[DataSet(['error', 'ERROR', true], 'any case')]
    #[DataSet(['error', 'errors', false], 'whole value')]
    #[DataSet(['/api/orders*', '/api/orders/42', true], 'trailing star')]
    #[DataSet(['/api/orders*', '/api/users', false], 'trailing star mismatch')]
    #[DataSet(['*@example.com', 'Alice@Example.com', true], 'leading star')]
    #[DataSet(['a*c', "a\nb\nc", true], 'star over lines')]
    #[DataSet(['*', '', true], 'star alone')]
    #[DataSet(['a.c', 'abc', false], 'regex characters are literal')]
    #[DataSet(['a?c', 'abc', false], 'question mark is literal')]
    #[DataSet(['ОШИБКА', 'ошибка', true], 'Unicode case')]
    public function testMatches(string $pattern, string $value, bool $expected): void
    {
        Assert::same(Wildcard::compile($pattern)->matches($value), $expected);
    }

    public function testValueNotInUtf8DoesNotMatch(): void
    {
        Assert::false(Wildcard::compile('*')->matches("\xFF"));
        Assert::true(Wildcard::compile("\xFF*")->matches("\xFFx"));
    }
}
