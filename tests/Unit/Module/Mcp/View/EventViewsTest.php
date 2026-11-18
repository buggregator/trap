<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Mcp\View;

use Buggregator\Trap\Module\Mcp\View\EventViews;
use Buggregator\Trap\Server\Application\Module\Modules;
use Buggregator\Trap\Server\Event\Record;
use Buggregator\Trap\Tests\Unit\Module\Mcp\Stub\ViewModule;
use Internal\Container\ObjectContainer;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(EventViews::class)]
final class EventViewsTest
{
    public static function views(): EventViews
    {
        $container = new ObjectContainer();
        $container->set(new Modules($container, [ViewModule::class]));

        return $container->get(EventViews::class);
    }

    public function testModuleViewGoesFirst(): void
    {
        $event = PayloadSummaryTest::event('fixed', ['message' => 'from the payload']);

        Assert::same(self::views()->summary($event), 'fixed summary');
        Assert::same(self::views()->text(new Record($event, 1, [])), 'fixed text');
    }

    public function testPayloadSummaryIsNext(): void
    {
        $event = PayloadSummaryTest::event('monolog', ['message' => 'from the payload', 'level_name' => 'INFO']);

        Assert::same(self::views()->summary($event), 'INFO from the payload');
    }

    public function testSummaryFallsBackToType(): void
    {
        Assert::same(self::views()->summary(PayloadSummaryTest::event('unknown', [])), 'unknown');
    }

    public function testFailedSummaryFallsBackToType(): void
    {
        Assert::same(self::views()->summary(PayloadSummaryTest::event('broken', [])), 'broken');
    }

    public function testModuleFieldsGoFirstWithoutEmptyValues(): void
    {
        Assert::same(self::views()->fields(PayloadSummaryTest::event('fixed', [])), ['answer' => 42]);
    }

    public function testPayloadFieldsAreNext(): void
    {
        $event = PayloadSummaryTest::event('monolog', ['level_name' => 'INFO', 'channel' => 'app']);

        Assert::same(self::views()->fields($event), ['level' => 'INFO', 'channel' => 'app']);
    }

    public function testFailedOrUnknownEventHasNoFields(): void
    {
        Assert::same(self::views()->fields(PayloadSummaryTest::event('broken', [])), []);
        Assert::same(self::views()->fields(PayloadSummaryTest::event('unknown', [])), []);
    }

    public function testFieldNamesOfTheFirstProviderWin(): void
    {
        $names = self::views()->fieldNames();

        Assert::same($names['fixed'], ['answer', 'empty']);
        Assert::same($names['monolog'], ['ignored']);
        Assert::same($names['smtp'], ['subject', 'from', 'to', 'cc', 'bcc']);
    }

    public function testTextFallsBackToPayloadJson(): void
    {
        $record = new Record(PayloadSummaryTest::event('unknown', ['key' => 'value/1']), 1, []);

        Assert::same(self::views()->text($record), "{\n    \"key\": \"value/1\"\n}");
    }

    public function testPayloadJsonCannotEncodeIsReported(): void
    {
        $record = new Record(PayloadSummaryTest::event('unknown', ['value' => \INF]), 1, []);

        $prefix = 'The event has no text view: ';
        $text = self::views()->text($record);

        Assert::string($text)->startsWith($prefix);
        Assert::true(\strlen($text) > \strlen($prefix), 'The reason follows the prefix.');
    }
}
