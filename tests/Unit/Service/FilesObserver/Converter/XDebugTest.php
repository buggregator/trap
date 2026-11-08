<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Service\FilesObserver\Converter;

use Buggregator\Trap\Logger;
use Buggregator\Trap\Module\Profiler\XDebug\ProfileBuilder;
use Buggregator\Trap\Proto\Frame\Profiler;
use Buggregator\Trap\Service\FilesObserver\Converter\XDebug;
use Buggregator\Trap\Service\FilesObserver\FileInfo;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Covers(XDebug::class)]
#[Test]
final class XDebugTest
{
    private const STUB = __DIR__ . '/../../../../Stub/xdebug/shared.cachegrind';

    public function testAcceptsDefaultProfileNames(): void
    {
        $converter = self::converter();

        Assert::same($converter->validate(new FileInfo('/tmp/cachegrind.out.123', 1, 0, 0)), true);
        Assert::same($converter->validate(new FileInfo('/tmp/cachegrind.out.123.gz', 1, 0, 0)), true);
        Assert::same($converter->validate(new FileInfo('/tmp/trace.123.xt', 1, 0, 0)), false);
        Assert::same($converter->validate(new FileInfo('/tmp/run.app.xhprof', 1, 0, 0)), false);
    }

    public function testConvertsProfile(): void
    {
        $file = new FileInfo(self::STUB, (int) \filesize(self::STUB), 0, 1747576624);

        $frames = \iterator_to_array(self::converter()->convert($file), false);

        Assert::count($frames, 1);
        Assert::instanceOf($frames[0], Profiler::class);
        Assert::same($frames[0]->payload->type, Profiler\Type::XDebug);
        $profile = $frames[0]->payload->getProfile();
        Assert::same($profile->date->getTimestamp(), 1747576624);
        Assert::same($profile->metadata['filename'], 'shared');
        Assert::same(\count($profile->tree()), 15);
    }

    private static function converter(): XDebug
    {
        return new XDebug(new Logger(), new ProfileBuilder());
    }
}
