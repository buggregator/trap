<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Support;

use Buggregator\Trap\Support\Measure;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

#[Covers(Measure::class)]
final class MeasureTest
{
    #[Test]
    #[DataSet([0, '0.00 B'], 'zero')]
    #[DataSet([512, '512.00 B'], 'bytes')]
    #[DataSet([1024, '1.00 KiB'], 'one kibibyte')]
    #[DataSet([1536, '1.50 KiB'], 'fraction')]
    #[DataSet([3 * 1024 * 1024, '3.00 MiB'], 'mebibytes')]
    #[DataSet([5 * 1024 ** 3, '5.00 GiB'], 'gibibytes')]
    #[DataSet([2 * 1024 ** 4, '2.00 TiB'], 'tebibytes')]
    public function formatsMemorySizeInBinaryUnits(int $size, string $expected): void
    {
        Assert::same(Measure::memory($size), $expected);
    }
}
