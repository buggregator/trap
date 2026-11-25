<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Html\Mcp;

use Buggregator\Trap\Module\Html\Frame;
use Buggregator\Trap\Module\Html\Frontend\Mapper;
use Buggregator\Trap\Module\Html\Frontend\Payload;
use Buggregator\Trap\Module\Html\Mcp\HtmlView;
use Buggregator\Trap\Server\Event\Event;
use Buggregator\Trap\Server\Event\Record;
use Buggregator\Trap\Tests\Unit\Module\Html\PageTest;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Covers(HtmlView::class)]
#[Covers(Mapper::class)]
#[Covers(Payload::class)]
#[Test]
final class HtmlViewTest
{
    public function testMapsFrameToUiEvent(): void
    {
        $event = (new Mapper())->map(new Frame('Nightly & report', PageTest::PAGE, 'shop'));

        Assert::same($event->type, 'html');
        Assert::same($event->projectKey(), 'shop');
        Assert::same(\json_decode(\json_encode($event, \JSON_THROW_ON_ERROR), true)['payload'], [
            'title' => 'Nightly & report',
            'html' => PageTest::PAGE,
            'size' => \strlen(PageTest::PAGE),
            'text' => 'OK',
        ]);
    }

    public function testTextOfPayloadCut(): void
    {
        $event = (new Mapper())->map(new Frame('', '<p>' . \str_repeat('a', 400) . '</p>'));

        Assert::instanceOf($event->payload, Payload::class);
        Assert::same($event->payload->text, \str_repeat('a', Payload::TEXT_LENGTH));
    }

    public function testSummaryIsTitleOrTextAndSize(): void
    {
        $view = new HtmlView();

        Assert::same($view->summary(self::event('Report', '<p>x</p>')), 'Report (8.00 B)');
        Assert::same($view->summary(self::event('', '<p>Visible</p>')), 'Visible (14.00 B)');
        Assert::same($view->summary(self::event('', '<img src=x>')), 'HTML page (11.00 B)');
        Assert::null($view->summary(new Event('a1', 'monolog', ['message' => 'x'], 1.0)));
    }

    public function testTextIsTitleSizeAndMarkup(): void
    {
        $view = new HtmlView();

        Assert::same(
            $view->text(new Record(self::event('Report', '<p>x</p>'), 1, [])),
            "Title: Report\nSize: 8 bytes\n\n<p>x</p>",
        );
        Assert::null($view->text(new Record(new Event('a1', 'monolog', ['message' => 'x'], 1.0), 1, [])));
    }

    public function testFields(): void
    {
        $view = new HtmlView();

        Assert::same($view->fields(self::event('Report', '<p>x</p>')), ['title' => 'Report', 'kind' => 'page']);
        Assert::same($view->fieldNames(), ['html' => ['title', 'kind', 'format']]);
    }

    private static function event(string $title, string $html): Event
    {
        \assert($html !== '');

        return (new Mapper())->map(new Frame($title, $html));
    }
}
