<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Profiler\FilesObserver;

use Buggregator\Trap\Module\Profiler\FilesObserver\XHProf;
use Buggregator\Trap\Module\Profiler\Frame;
use Buggregator\Trap\Module\Profiler\XHProf\ProfileBuilder;
use Buggregator\Trap\Server\Logger;
use Buggregator\Trap\Server\Service\FilesObserver\FileInfo;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Covers(XHProf::class)]
#[Test]
final class XHProfTest
{
    private const STUB_DIR = __DIR__ . '/../../../../Stub/xhprof';

    public function testAcceptsOnlyXhprofFiles(): void
    {
        $converter = self::converter();

        Assert::same($converter->validate(new FileInfo('/tmp/run.app.xhprof', 1, 0, 0)), true);
        Assert::same($converter->validate(new FileInfo('/tmp/run.app.json', 1, 0, 0)), false);
    }

    public function testConvertsSerializedProfile(): void
    {
        $path = self::STUB_DIR . '/app.xhprof';
        $file = new FileInfo($path, (int) \filesize($path), 0, 1747576624);

        $frames = \iterator_to_array(self::converter()->convert($file), false);

        Assert::count($frames, 1);
        Assert::instanceOf($frames[0], Frame::class);
        $profile = $frames[0]->payload->getProfile();
        Assert::same($profile->date->getTimestamp(), 1747576624);
        Assert::same($profile->metadata['filename'], 'app');
        Assert::same(\count($profile->calls), 2223);
        Assert::same($profile->peaks->wt, 45310);
    }

    public function testFileAndEndpointPayloadGiveSameCalls(): void
    {
        $fromFile = \unserialize(\file_get_contents(self::STUB_DIR . '/app.xhprof'), ['allowed_classes' => false]);
        $fromEndpoint = \json_decode(\file_get_contents(self::STUB_DIR . '/app.json'), true, flags: \JSON_THROW_ON_ERROR);

        Assert::same($fromFile, $fromEndpoint['profile']);
    }

    private static function converter(): XHProf
    {
        return new XHProf(new Logger(), new ProfileBuilder());
    }
}
