<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Client\Profiler;

use Buggregator\Trap\Client\Profiler\Clock;
use Buggregator\Trap\Client\Profiler\Excimer;
use Buggregator\Trap\Client\Profiler\Format;
use Buggregator\Trap\Client\Profiler\Internal\Warnings;
use Buggregator\Trap\Client\Profiler\XHProf;
use Buggregator\Trap\Client\TrapHandle;
use Buggregator\Trap\Tests\Unit\Client\Base;
use Buggregator\Trap\Tests\Unit\Client\Profiler\Stub\LogCapture;
use Testo\Assert;
use Testo\Core\Exception\SkipTest;
use Testo\Expect;
use Testo\Test;

#[Test]
final class DriverTest extends Base
{
    public function excimerIsUnavailableWithoutTheExtension(): void
    {
        \extension_loaded('excimer') and throw new SkipTest('ext-excimer is loaded');

        Assert::false((new Excimer())->isAvailable());
    }

    public function xhprofIsUnavailableWithoutTheExtensions(): void
    {
        \function_exists('xhprof_enable') || \function_exists('tideways_xhprof_enable')
        and throw new SkipTest('an XHProf extension is loaded');

        Assert::false((new XHProf())->isAvailable());
    }

    public function noProfilerAvailableRunsTheCallable(): void
    {
        \extension_loaded('excimer') || \function_exists('xhprof_enable') || \function_exists('tideways_xhprof_enable')
        and throw new SkipTest('a profiler extension is loaded');

        $log = new LogCapture();
        Warnings::$logger = $log;

        try {
            $result = TrapHandle::profile()->run(static fn(): int => 5);
        } finally {
            Warnings::$logger = null;
        }

        Assert::same($result, 5);
        // Logged once per process: an earlier test may have taken it.
        $log->records === [] or Assert::string($log->messages())->contains('No profiler is available');
    }

    public function excimerReportsItsOptions(): void
    {
        $driver = new Excimer(period: 0.01, clock: Clock::Cpu, maxDepth: 64);

        Assert::same($driver->options(), ['period' => 0.01, 'clock' => 'cpu', 'max_depth' => 64]);
        Assert::same($driver->formats(), [Format::Speedscope, Format::Collapsed, Format::XHProf]);
    }

    public function excimerRejectsANonPositivePeriod(): never
    {
        Expect::exception(\InvalidArgumentException::class);

        new Excimer(period: 0.0);
    }

    public function xhprofReportsItsFormat(): void
    {
        $driver = new XHProf(memory: false, cpu: true, ignored: ['strlen']);

        Assert::same($driver->formats(), [Format::XHProf]);
        Assert::same($driver->options()['cpu'], true);
        Assert::same($driver->options()['ignored'], ['strlen']);
    }
}
