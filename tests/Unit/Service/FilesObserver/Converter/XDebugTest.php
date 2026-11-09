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
use Testo\Data\DataSet;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Covers(XDebug::class)]
#[Test]
final class XDebugTest
{
    private const STUB = __DIR__ . '/../../../../Stub/xdebug/shared.cachegrind';

    private string $dir;

    public function testAcceptsDefaultProfileNames(): void
    {
        $converter = self::converter();

        Assert::same($converter->validate(new FileInfo('/tmp/cachegrind.out.123', 1, 0, 0)), true);
        Assert::same($converter->validate(new FileInfo('/tmp/cachegrind.out.123.gz', 1, 0, 0)), true);
        Assert::same($converter->validate(new FileInfo('/tmp/trace.123.xt', 1, 0, 0)), false);
        Assert::same($converter->validate(new FileInfo('/tmp/run.app.xhprof', 1, 0, 0)), false);
    }

    #[BeforeTest]
    public function createDir(): void
    {
        $this->dir = \sys_get_temp_dir() . '/' . \uniqid('trap-xdebug-', true);
        \mkdir($this->dir);
    }

    #[AfterTest]
    public function removeDir(): void
    {
        \array_map(\unlink(...), (array) \glob($this->dir . '/*'));
        \rmdir($this->dir);
    }

    #[DataSet(['cachegrind.out.1234', false], 'plain')]
    #[DataSet(['cachegrind.out.9999.gz', true], 'gzipped')]
    public function testConvertsProfile(string $name, bool $gzip): void
    {
        $content = (string) \file_get_contents(self::STUB);
        $path = $this->dir . '/' . $name;
        \file_put_contents($path, $gzip ? \gzencode($content) : $content);
        $file = new FileInfo($path, (int) \filesize($path), 0, 1747576624);

        $frames = \iterator_to_array(self::converter()->convert($file), false);

        Assert::count($frames, 1);
        Assert::instanceOf($frames[0], Profiler::class);
        Assert::same($frames[0]->payload->type, Profiler\Type::XDebug);
        $profile = $frames[0]->payload->getProfile();
        Assert::same($profile->date->getTimestamp(), 1747576624);
        Assert::same($profile->metadata['filename'], $name);
        Assert::same(\count($profile->tree()), 15);
    }

    private static function converter(): XDebug
    {
        return new XDebug(new Logger(), new ProfileBuilder());
    }
}
