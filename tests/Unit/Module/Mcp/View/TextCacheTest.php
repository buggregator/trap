<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Mcp\View;

use Buggregator\Trap\Module\Mcp\View\EventViews;
use Buggregator\Trap\Module\Mcp\View\TextCache;
use Buggregator\Trap\Server\Event\Record;
use Buggregator\Trap\Tests\Unit\Module\Mcp\Stub\Trap;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(TextCache::class)]
final class TextCacheTest
{
    public function testKeepsTheTextOfTheStoredRecord(): void
    {
        $trap = new Trap();
        $cache = $trap->get(TextCache::class);
        $record = $trap->add(Trap::dump('cached'));

        $first = $cache->text($record);

        Assert::string($first)->contains('"cached"');
        Assert::same($cache->text($record), $first);
        Assert::same($cache->searchable($record), $first);
        Assert::same($cache->count(), 1);
        Assert::same($cache->bytes(), \strlen($first));
    }

    public function testUpdatedEventIsRenderedAgain(): void
    {
        $trap = new Trap();
        $cache = $trap->get(TextCache::class);
        $record = $trap->add(Trap::dump('old'));
        $cache->text($record);

        $updated = $trap->store->add($record->event, Trap::dump('new'));

        Assert::same($cache->count(), 0);
        Assert::same($cache->bytes(), 0);
        Assert::string($cache->text($updated))->contains('"old"')->contains('"new"');
        Assert::same($cache->count(), 1);
    }

    public function testDeletedAndClearedEventsAreForgotten(): void
    {
        $trap = new Trap();
        $cache = $trap->get(TextCache::class);
        $deleted = $trap->add(Trap::dump('deleted'));
        $cleared = $trap->add(Trap::dump('cleared'));
        $cache->text($deleted);
        $size = \strlen($cache->text($cleared));

        $trap->store->delete($deleted->event->uuid);
        $afterDelete = [$cache->count(), $cache->bytes()];
        $trap->store->clear();

        Assert::same($afterDelete, [1, $size]);
        Assert::same($cache->count(), 0);
        Assert::same($cache->bytes(), 0);
    }

    public function testFullCacheKeepsItsEntriesThroughRepeatedScans(): void
    {
        $trap = new Trap();
        $records = [$trap->add(Trap::dump('one')), $trap->add(Trap::dump('two')), $trap->add(Trap::dump('six'))];
        $size = \strlen(self::render($trap, $records[0]));
        $cache = new TextCache($trap->get(EventViews::class), $trap->store, maxBytes: 2 * $size);

        foreach ([1, 2] as $_) {
            foreach ($records as $record) {
                Assert::string($cache->searchable($record))->contains('"');
            }
        }

        Assert::same($cache->count(), 2, 'The third text has no room and evicts nothing.');
        Assert::same($cache->bytes(), 2 * $size);
    }

    public function testDeletionMakesRoomForTheNextText(): void
    {
        $trap = new Trap();
        $first = $trap->add(Trap::dump('one'));
        $second = $trap->add(Trap::dump('two'));
        $cache = new TextCache($trap->get(EventViews::class), $trap->store, maxBytes: \strlen(self::render($trap, $first)));
        $cache->searchable($first);
        $cache->searchable($second);
        $full = $cache->count();

        $trap->store->delete($first->event->uuid);
        $cache->searchable($second);

        Assert::same($full, 1);
        Assert::same($cache->count(), 1);
        Assert::same($cache->bytes(), \strlen(self::render($trap, $second)));
    }

    public function testSearchedTextIsCutAtCharacterBoundary(): void
    {
        $trap = new Trap();
        $record = $trap->add(Trap::dump(\str_repeat('ж', 100)));
        $whole = self::render($trap, $record);
        $offset = (int) \strpos($whole, 'ж');
        // In the middle of the second two-byte letter
        $cache = new TextCache($trap->get(EventViews::class), $trap->store, maxSearchedBytes: $offset + 3);

        $searched = $cache->searchable($record);

        Assert::same($searched, \substr($whole, 0, $offset + 2));
        Assert::same($cache->bytes(), \strlen($searched));
        Assert::same($cache->text($record), $whole, 'The whole text is rendered again.');
    }

    public function testTextLargerThanTheCacheIsNotKept(): void
    {
        $trap = new Trap();
        $record = $trap->add(Trap::dump('large'));
        $cache = new TextCache($trap->get(EventViews::class), $trap->store, maxBytes: 10);

        $text = $cache->text($record);

        Assert::same($text, self::render($trap, $record));
        Assert::same($cache->count(), 0);
        Assert::same($cache->bytes(), 0);
    }

    private static function render(Trap $trap, Record $record): string
    {
        return $trap->get(EventViews::class)->text($record);
    }
}
