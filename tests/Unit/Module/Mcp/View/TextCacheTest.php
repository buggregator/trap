<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Mcp\View;

use Buggregator\Trap\Module\Mcp\View\TextCache;
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
        Assert::same($cache->count(), 1);
    }

    public function testUpdatedEventIsRenderedAgain(): void
    {
        $trap = new Trap();
        $cache = $trap->get(TextCache::class);
        $record = $trap->add(Trap::dump('old'));
        $cache->text($record);

        $updated = $trap->store->add($record->event, Trap::dump('new'));

        Assert::same($cache->count(), 0);
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
        $cache->text($cleared);

        $trap->store->delete($deleted->event->uuid);
        $afterDelete = $cache->count();
        $trap->store->clear();

        Assert::same($afterDelete, 1);
        Assert::same($cache->count(), 0);
    }
}
