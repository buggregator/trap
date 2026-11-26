<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Html\Frontend;

use Buggregator\Trap\Module\Frontend\Message\UiEvent;
use Buggregator\Trap\Module\Html\Frame;
use Buggregator\Trap\Module\Html\Frontend\Mapper;
use Buggregator\Trap\Module\Html\Frontend\Payload;
use Buggregator\Trap\Server\Event\EventStore;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Covers(Payload::class)]
#[Covers(Mapper::class)]
#[Test]
final class PayloadTest
{
    public function testPreviewOfSmallPageHasMarkup(): void
    {
        $html = '<title>T</title><p>' . \str_repeat('a', Payload::PREVIEW_HTML - 50) . '</p>';
        \assert(\strlen($html) <= Payload::PREVIEW_HTML);

        Assert::same((new Payload('Report', $html))->preview(), [
            'title' => 'Report',
            'size' => \strlen($html),
            'text' => \str_repeat('a', Payload::TEXT_LENGTH),
            'html' => $html,
        ]);
    }

    public function testPreviewOfLargePageIsTruncated(): void
    {
        $html = '<p>Start</p>' . \str_repeat('x', Payload::PREVIEW_HTML);

        Assert::same((new Payload('Report', $html))->preview(), [
            'title' => 'Report',
            'size' => \strlen($html),
            'text' => 'Start ' . \str_repeat('x', Payload::TEXT_LENGTH - 6),
            'truncated' => true,
        ]);
    }

    public function testFeedGetsPreviewAndPageWholePayload(): void
    {
        $html = '<p>' . \str_repeat('x', 100_000) . '</p>';
        $event = (new Mapper())->map(new Frame('Report', $html));
        $store = new EventStore();
        $store->add($event);

        $feed = \json_decode(\json_encode(UiEvent::of($event, $store, preview: true), \JSON_THROW_ON_ERROR), true);
        $page = \json_decode(\json_encode(UiEvent::of($event, $store), \JSON_THROW_ON_ERROR), true);

        Assert::false(isset($feed['payload']['html']));
        Assert::true($feed['payload']['truncated']);
        Assert::same($page['payload']['html'], $html);
        Assert::false(isset($page['payload']['truncated']));
    }

    public function testSearchTextStopsAtItsLimit(): void
    {
        $html = '<p>' . \str_repeat('a', Payload::SEARCH_TEXT) . 'beyond</p>';

        Assert::same(\mb_strlen((new Payload('T', $html))->searchText()), \strlen("t\n") + Payload::SEARCH_TEXT);
    }

    public function testSearchTextIsTitleAndVisibleTextInLowerCase(): void
    {
        $html = '<script>Hidden()</script><p>' . \str_repeat('Слово ', 1_000) . 'КОНЕЦ</p>';

        Assert::same(
            (new Payload('Отчёт', $html))->searchText(),
            "отчёт\n" . \str_repeat('слово ', 1_000) . 'конец',
        );
    }
}
