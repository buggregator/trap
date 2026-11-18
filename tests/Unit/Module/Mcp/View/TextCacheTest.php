<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Mcp\View;

use Buggregator\Trap\Module\Mcp\View\EventViews;
use Buggregator\Trap\Module\Mcp\View\TextCache;
use Buggregator\Trap\Server\Config\Events;
use Buggregator\Trap\Server\Event\Event;
use Buggregator\Trap\Server\Event\Record;
use Buggregator\Trap\Server\Proto\Frame;
use Buggregator\Trap\Tests\Unit\Module\Mcp\Stub\ScriptedView;
use Buggregator\Trap\Tests\Unit\Module\Mcp\Stub\Trap;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
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

        $first = $cache->searchable($record);

        Assert::string($first)->contains('"cached"');
        Assert::same($cache->searchable($record), $first);
        Assert::same($cache->prefix($record, 100_000), [$first, \mb_strlen($first)]);
        Assert::same($cache->count(), 1);
        Assert::same($cache->bytes(), \strlen($first));
    }

    public function testUpdatedEventIsRenderedAgain(): void
    {
        $trap = new Trap();
        $cache = $trap->get(TextCache::class);
        $record = $trap->add(Trap::dump('old'));
        $cache->searchable($record);

        $updated = $trap->store->add($record->event, Trap::dump('new'));

        Assert::same($cache->count(), 0);
        Assert::same($cache->bytes(), 0);
        Assert::string($cache->searchable($updated))->contains('"old"')->contains('"new"');
        Assert::same($cache->count(), 1);
    }

    public function testTextOfAnotherRecordOfTheEventIsNotServed(): void
    {
        $trap = Trap::scripted();
        $cache = $trap->get(TextCache::class);
        $old = $trap->store->add(ScriptedView::event('changed', 'old'));
        $new = $trap->store->add(ScriptedView::event('changed', 'new'));
        $cache->searchable($new);

        Assert::same($cache->searchable($old), 'old');
        Assert::same($cache->searchable($new), 'new');
        Assert::same($trap->view()->renders, 3);
        Assert::same($cache->bytes(), 3);
    }

    public function testDeletedAndClearedEventsAreForgotten(): void
    {
        $trap = new Trap();
        $cache = $trap->get(TextCache::class);
        $deleted = $trap->add(Trap::dump('deleted'));
        $cleared = $trap->add(Trap::dump('cleared'));
        $cache->searchable($deleted);
        $size = \strlen($cache->searchable($cleared));

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
        Assert::same($cache->prefix($record, \PHP_INT_MAX), [$whole, \mb_strlen($whole)], 'The whole text is rendered again.');
    }

    public function testTextLargerThanTheCacheIsNotKept(): void
    {
        $trap = new Trap();
        $record = $trap->add(Trap::dump('large'));
        $cache = new TextCache($trap->get(EventViews::class), $trap->store, maxBytes: 10);

        $text = $cache->searchable($record);

        Assert::same($text, self::render($trap, $record));
        Assert::same($cache->count(), 0);
        Assert::same($cache->bytes(), 0);
    }

    public function testPrefixFitsInTheCachedStart(): void
    {
        $trap = Trap::scripted();
        $record = $trap->store->add(ScriptedView::event('long', \str_repeat('ж', 100_000)));
        $cache = $trap->get(TextCache::class);
        $cache->searchable($record);

        $prefix = $cache->prefix($record, 2_000);

        Assert::same($prefix, [\str_repeat('ж', 2_000), 100_000]);
        Assert::same($trap->view()->renders, 1);
    }

    public function testPrefixLongerThanTheCachedStartIsRendered(): void
    {
        $trap = Trap::scripted();
        $record = $trap->store->add(ScriptedView::event('long', \str_repeat('ж', 100_000)));
        $cache = $trap->get(TextCache::class);
        $kept = \mb_strlen($cache->searchable($record));

        $prefix = $cache->prefix($record, 50_000);

        Assert::same($kept, TextCache::MAX_SEARCHED_BYTES / 2);
        Assert::same($prefix, [\str_repeat('ж', 50_000), 100_000]);
        Assert::same($trap->view()->renders, 2);
        Assert::same($cache->bytes(), TextCache::MAX_SEARCHED_BYTES);
    }

    public function testShortTextIsServedWhole(): void
    {
        $trap = Trap::scripted();
        $record = $trap->store->add(ScriptedView::event('short', 'short text'));
        $cache = $trap->get(TextCache::class);
        $cache->searchable($record);

        Assert::same($cache->prefix($record, 1_000), ['short text', 10]);
        Assert::same($cache->prefix($record, 5), ['short', 10]);
        Assert::same($trap->view()->renders, 1);
    }

    #[DataSet([1, TextCache::MIN_BYTES], 'small store')]
    #[DataSet([1_000, 1_000 * (TextCache::MAX_SEARCHED_BYTES + 1024)], 'large store')]
    #[DataSet([100_000, TextCache::MAX_BYTES], 'huge store')]
    public function testSizeFollowsTheStore(int $maxEvents, int $maxBytes): void
    {
        $trap = new Trap();
        $config = new Events();
        $config->maxEvents = $maxEvents;

        $cache = new TextCache($trap->get(EventViews::class), $trap->store, $config);

        Assert::same($cache->maxBytes(), $maxBytes);
    }

    public function testWarmRendersTheNewEventsOnce(): void
    {
        $trap = Trap::scripted();
        $cache = $trap->get(TextCache::class);
        $deleted = $trap->store->add(ScriptedView::event('deleted', 'deleted'));
        $trap->store->add(ScriptedView::event('one', 'one'));
        $trap->store->add(ScriptedView::event('two', 'two'));
        $trap->store->delete($deleted->event->uuid);

        $left = $cache->warm(0.0);
        $leftAfterAll = $cache->warm(10.0);

        Assert::true($left, 'A slice renders one event at least.');
        Assert::false($leftAfterAll);
        Assert::same($cache->count(), 2);
        Assert::same($trap->view()->renders, 2);
        Assert::false($cache->warm(10.0));
        Assert::same($trap->view()->renders, 2);
    }

    public function testWarmTakesTheEventsStoredBeforeTheCache(): void
    {
        $trap = Trap::scripted();
        $trap->store->add(ScriptedView::event('early', 'early'));
        $cache = $trap->get(TextCache::class);

        $cache->warm(10.0);

        Assert::same($cache->count(), 1);
    }

    public function testWarmRendersAnUpdatedEventAgain(): void
    {
        $trap = Trap::scripted();
        $cache = $trap->get(TextCache::class);
        $record = $trap->store->add(ScriptedView::event('updated', 'updated'));
        $cache->warm(10.0);

        $trap->store->add($record->event);
        $cache->warm(10.0);

        Assert::same($trap->view()->renders, 2);
        Assert::same($cache->count(), 1);
    }

    public function testWarmLeavesLargeEventsToTheTools(): void
    {
        $trap = Trap::scripted();
        $cache = $trap->get(TextCache::class);
        $large = $trap->store->add(ScriptedView::event('large', \str_repeat('x', TextCache::MAX_WARMED_BYTES)));
        $trap->store->add(ScriptedView::event('small', 'small'));

        $left = $cache->warm(10.0);
        $warmed = [$cache->count(), $trap->view()->renders];
        $cache->searchable($large);

        Assert::false($left);
        Assert::same($warmed, [1, 1]);
        Assert::same($cache->count(), 2);
    }

    public function testWarmLeavesEventsOfLargeFramesToTheTools(): void
    {
        $trap = new Trap();
        $cache = $trap->get(TextCache::class);
        $trap->add(Trap::dump(\str_repeat('x', TextCache::MAX_WARMED_BYTES)));
        $small = $trap->add(Trap::dump('small'));

        $cache->warm(10.0);

        Assert::same($cache->count(), 1);
        Assert::same($cache->bytes(), \strlen(self::render($trap, $small)) + \strlen($cache->summary($small)));
    }

    public function testWarmRendersAnEventWhosePayloadIsNotJson(): void
    {
        $trap = Trap::scripted();
        $cache = $trap->get(TextCache::class);
        $trap->store->add(new Event('infinite', 'scripted', ['text' => 'infinite', 'value' => \INF], 1.0));

        $cache->warm(10.0);

        Assert::same($cache->count(), 1);
    }

    public function testWarmLeavesEventsOfUnmeasurableFramesToTheTools(): void
    {
        $trap = Trap::scripted();
        $cache = $trap->get(TextCache::class);
        $frame = new class('unmeasurable') extends Frame {
            public static function fromString(string $payload, \DateTimeImmutable $time): static
            {
                throw new \LogicException('Not parsed.');
            }

            public function __toString(): string
            {
                throw new \RuntimeException('The size is unknown.');
            }
        };
        $record = $trap->store->add(ScriptedView::event('unmeasurable', 'unmeasurable'), $frame);

        $left = $cache->warm(10.0);
        $warmed = [$cache->count(), $trap->view()->renders];

        Assert::false($left);
        Assert::same($warmed, [0, 0]);
        Assert::same($cache->searchable($record), 'unmeasurable');
    }

    public function testWarmStopsWhileTheNextTextMightNotFit(): void
    {
        $trap = Trap::scripted();
        foreach (['one', 'two', 'six'] as $uuid) {
            $trap->store->add(ScriptedView::event($uuid, $uuid));
        }
        // Each event takes 11 bytes: room for the largest entry after one event, not after two
        $cache = new TextCache(
            $trap->get(EventViews::class),
            $trap->store,
            maxBytes: TextCache::MAX_SEARCHED_BYTES + TextCache::SUMMARY_BYTES + 20,
        );

        $left = $cache->warm(10.0);

        Assert::false($left);
        Assert::same($cache->count(), 2);
        Assert::same($trap->view()->renders, 2);
    }

    private static function render(Trap $trap, Record $record): string
    {
        return $trap->get(EventViews::class)->text($record);
    }
}
