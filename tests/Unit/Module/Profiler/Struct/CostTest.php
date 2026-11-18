<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Profiler\Struct;

use Buggregator\Trap\Module\Profiler\Struct\Cost;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

#[Test]
#[Covers(Cost::class)]
final class CostTest
{
    #[DataSet([-5, -5], 'integer')]
    #[DataSet([2.9, 2], 'float in the range')]
    #[DataSet([-2.9, -2], 'negative float in the range')]
    #[DataSet([\PHP_INT_MAX + 1, \PHP_INT_MAX], 'integer overflow')]
    #[DataSet([\PHP_INT_MIN - 1, \PHP_INT_MIN], 'integer underflow')]
    #[DataSet([1e19, \PHP_INT_MAX], 'float over the range')]
    #[DataSet([-1e19, \PHP_INT_MIN], 'float under the range')]
    public function saturateClampsToIntegerRange(int|float $value, int $expected): void
    {
        Assert::same(Cost::saturate($value), $expected);
    }

    #[DataSet([2, 3, 5], 'in the range')]
    #[DataSet([\PHP_INT_MAX, 1, \PHP_INT_MAX], 'past the range')]
    #[DataSet([\PHP_INT_MAX, \PHP_INT_MAX, \PHP_INT_MAX], 'both at the maximum')]
    public function sumSaturatesAtMaximum(int $a, int $b, int $expected): void
    {
        Assert::same(Cost::sum($a, $b), $expected);
    }
}
