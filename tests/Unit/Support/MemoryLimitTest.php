<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Support;

use Buggregator\Trap\Support\MemoryLimit;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Covers(MemoryLimit::class)]
#[Test]
final class MemoryLimitTest
{
    private string $saved = '';

    /** A limit the process can be set to: the whole suite may already take more than a fixed one */
    private int $low = 0;

    #[BeforeTest]
    public function save(): void
    {
        $this->saved = (string) \ini_get('memory_limit');
        $this->low = \max(256, (\memory_get_usage(true) >> 20) + 64) << 20;
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
    #[DataSet(['0x20000000', 512 << 20])]
    #[DataSet(['0X200m', 512 << 20])]
    #[DataSet(['0o1000M', 512 << 20])]
    #[DataSet(['0b1G', 1 << 30])]
    #[DataSet(['17179869185G', \PHP_INT_MAX])]
    #[DataSet(['8589934592G', \PHP_INT_MAX])]
    #[DataSet(['99999999999999999999', \PHP_INT_MAX])]
    #[DataSet(['0xFFFFFFFFFFFFFFFFFF', \PHP_INT_MAX])]
    #[DataSet(['-1', null])]
    public function testParsesLimit(string $value, ?int $bytes): void
    {
        Assert::same(MemoryLimit::parse($value), $bytes);
    }

    #[DataSet([''])]
    #[DataSet(['0'])]
    #[DataSet(['0M'])]
    #[DataSet(['abc'])]
    #[DataSet(['-2'])]
    #[DataSet(['12T'])]
    #[DataSet(['0x'])]
    public function testRejectsUnreadableLimit(string $value): never
    {
        Expect::exception(\InvalidArgumentException::class);

        MemoryLimit::parse($value);
    }

    public function testRaisesLimitGivenInHexadecimal(): void
    {
        $this->setLow();

        Assert::true(MemoryLimit::raise(\sprintf('0x%x', $this->low * 4)));
        Assert::same(MemoryLimit::current(), $this->low * 4);
    }

    public function testRaisesLowerLimit(): void
    {
        $this->setLow();

        $raised = MemoryLimit::raise(\sprintf('%dM', ($this->low * 4) >> 20));

        Assert::true($raised);
        Assert::same(MemoryLimit::current(), $this->low * 4);
    }

    #[DataSet(['128M'])]
    #[DataSet([''])]
    #[DataSet(['nonsense'])]
    public function testNeverLowersLimit(string $value): void
    {
        $this->setLow();

        $raised = MemoryLimit::raise($value);

        Assert::false($raised);
        Assert::same(MemoryLimit::current(), $this->low);
    }

    public function testLeavesUnlimitedProcessAlone(): void
    {
        \ini_set('memory_limit', '-1');

        Assert::false(MemoryLimit::raise('512M'));
        Assert::null(MemoryLimit::current());
    }

    public function testLiftsLimitOnMinusOne(): void
    {
        $this->setLow();

        Assert::true(MemoryLimit::raise('-1'));
        Assert::null(MemoryLimit::current());
    }

    private function setLow(): void
    {
        \ini_set('memory_limit', (string) $this->low);
        Assert::same(MemoryLimit::current(), $this->low);
    }
}
