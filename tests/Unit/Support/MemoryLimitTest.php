<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Support;

use Buggregator\Trap\Support\MemoryLimit;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Covers(MemoryLimit::class)]
#[Test]
final class MemoryLimitTest
{
    private string $saved = '';

    #[BeforeTest]
    public function save(): void
    {
        $this->saved = (string) \ini_get('memory_limit');
    }

    #[AfterTest]
    public function restore(): void
    {
        \ini_set('memory_limit', $this->saved);
    }

    #[DataSet(['128M', 128 << 20])]
    #[DataSet(['1G', 1 << 30])]
    #[DataSet(['512k', 512 << 10])]
    #[DataSet([' 1048576 ', 1 << 20])]
    #[DataSet(['-1', null])]
    #[DataSet(['', null])]
    #[DataSet(['abc', null])]
    public function testParsesLimit(string $value, ?int $bytes): void
    {
        Assert::same(MemoryLimit::parse($value), $bytes);
    }

    public function testRaisesLowerLimit(): void
    {
        \ini_set('memory_limit', '256M');

        $raised = MemoryLimit::raise('1G');

        Assert::true($raised);
        Assert::same(MemoryLimit::current(), 1 << 30);
    }

    #[DataSet(['128M'])]
    #[DataSet([''])]
    #[DataSet(['nonsense'])]
    public function testNeverLowersLimit(string $value): void
    {
        \ini_set('memory_limit', '256M');

        $raised = MemoryLimit::raise($value);

        Assert::false($raised);
        Assert::same(MemoryLimit::current(), 256 << 20);
    }

    public function testLeavesUnlimitedProcessAlone(): void
    {
        \ini_set('memory_limit', '-1');

        Assert::false(MemoryLimit::raise('512M'));
        Assert::null(MemoryLimit::current());
    }

    public function testLiftsLimitOnMinusOne(): void
    {
        \ini_set('memory_limit', '256M');

        Assert::true(MemoryLimit::raise('-1'));
        Assert::null(MemoryLimit::current());
    }
}
