<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Support;

use Buggregator\Trap\Support\Charset;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

#[Test]
#[Covers(Charset::class)]
final class CharsetTest
{
    #[DataSet(["\xCF\xF0\xE8\xE2\xE5\xF2", 'windows-1251', 'Привет'], 'windows-1251')]
    #[DataSet(["\xC8esk\xFD", 'windows-1250', 'Český'], 'windows-1250, unknown to mbstring')]
    #[DataSet(["caf\xE9", 'ISO-8859-1', 'café'], 'ISO-8859-1')]
    #[DataSet(['café', 'UTF-8', 'café'], 'UTF-8')]
    public function convertsToUtf8(string $value, string $charset, string $expected): void
    {
        Assert::same(Charset::toUtf8($value, $charset), $expected);
    }

    public function returnsNullForUnknownCharset(): void
    {
        Assert::null(Charset::toUtf8("caf\xE9", 'x-unknown'));
    }
}
