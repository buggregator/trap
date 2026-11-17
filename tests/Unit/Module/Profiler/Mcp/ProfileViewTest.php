<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Profiler\Mcp;

use Buggregator\Trap\Module\Profiler\Frame\Payload;
use Buggregator\Trap\Module\Profiler\Frame\Type;
use Buggregator\Trap\Module\Profiler\Mcp\ProfileView;
use Buggregator\Trap\Module\Profiler\Struct\Peaks;
use Buggregator\Trap\Module\Profiler\Struct\Profile;
use Buggregator\Trap\Server\Event\Event;
use Buggregator\Trap\Server\Event\Record;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

#[Test]
#[Covers(ProfileView::class)]
final class ProfileViewTest
{
    #[DataSet([['app_name' => 'shop', 'filename' => 'run.xhprof'], 12_345, 'shop (XHProf), wall time 12.35 ms, 0 edges'], 'app name')]
    #[DataSet([['app_name' => '', 'filename' => 'run.xhprof'], 0, 'run.xhprof (XHProf), 0 edges'], 'file name')]
    #[DataSet([[], 0, 'profile (XHProf), 0 edges'], 'no name')]
    public function testSummary(array $metadata, int $wallTime, string $expected): void
    {
        Assert::same((new ProfileView())->summary(self::event($metadata, $wallTime)), $expected);
    }

    #[DataSet([['app_name' => 'shop', 'filename' => 'run.xhprof'], 'shop'], 'app name')]
    #[DataSet([['filename' => 'run.xhprof'], 'run.xhprof'], 'file name')]
    #[DataSet([[], ''], 'no name')]
    public function testFields(array $metadata, string $name): void
    {
        $view = new ProfileView();

        Assert::same($view->fields(self::event($metadata, 0)), ['name' => $name, 'format' => 'XHProf']);
        Assert::same($view->fieldNames(), ['profiler' => ['name', 'format']]);
    }

    public function testOtherPayloadIsLeftToOtherViews(): void
    {
        $event = new Event('5a4f8e1c-0000-4000-8000-000000000001', 'profiler', [], 1.0);

        Assert::null((new ProfileView())->summary($event));
        Assert::null((new ProfileView())->text(new Record($event, 1, [])));
        Assert::null((new ProfileView())->fields($event));
    }

    private static function event(array $metadata, int $wallTime): Event
    {
        $payload = Payload::new(Type::XHProf, static fn(): Profile => new Profile(
            metadata: $metadata,
            peaks: new Peaks(wt: $wallTime),
        ));

        return new Event('5a4f8e1c-0000-4000-8000-000000000001', 'profiler', $payload, 1.0);
    }
}
