<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Html\Mcp;

use Buggregator\Trap\Module\Html\Binary\Mapper;
use Buggregator\Trap\Module\Html\Frontend\Payload;
use Buggregator\Trap\Module\Html\Mcp\BinaryView;
use Buggregator\Trap\Module\Html\Mcp\HtmlView;
use Buggregator\Trap\Module\Mcp\View\EventViews;
use Buggregator\Trap\Server\Event\Event;
use Buggregator\Trap\Server\Event\Record;
use Buggregator\Trap\Server\Proto\Frame\Binary;
use Buggregator\Trap\Support\StreamHelper;
use Internal\Container\ObjectContainer;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Covers(BinaryView::class)]
#[Test]
final class BinaryViewTest
{
    public function testSummaryIsTitleAndSize(): void
    {
        Assert::same((new BinaryView())->summary(self::event("\x1f\x8b\x08\x00")), 'Binary data: gzip data (4.00 B)');
        Assert::null((new BinaryView())->summary(new Event('a1', 'html', new Payload('', '<p>'), 1.0)));
    }

    public function testTextIsFormatAndHexdumpNotMarkup(): void
    {
        $text = (new BinaryView())->text(new Record(self::event("\x1f\x8b\x08\x00<b>"), 1, []));

        Assert::same($text, <<<'TEXT'
            Binary data: TCP traffic no protocol module recognised.
            Size: 7 bytes
            Format: gzip data - Compressed: download and gunzip it.
            Entropy: 2.81 bits per byte of the first 7 bytes

            Bytes:
            00000000  1f 8b 08 00 3c 62 3e                              |....<b>|
            TEXT);
    }

    public function testTextOfLargeDataHasFirstBytes(): void
    {
        $text = (string) (new BinaryView())->text(new Record(self::event(\str_repeat("\x00\x01", 2000)), 1, []));

        Assert::string($text)->contains('The first 1024 bytes:')->contains("\n000003f0  ")->notContains("\n00000400  ");
    }

    /**
     * The `kind` field tells the binary events from the pages: both are `html` events.
     */
    public function testFieldsTellBinaryFromPage(): void
    {
        $views = (new ObjectContainer())->get(EventViews::class);

        Assert::same($views->fields(self::event("\x1f\x8b\x08\x00")), [
            'title' => 'Binary data: gzip data',
            'kind' => 'binary',
            'format' => 'gzip',
        ]);
        Assert::same($views->fields(self::event("\x00\x01")), ['title' => 'Binary data', 'kind' => 'binary']);
        Assert::same(
            $views->fields(new Event('a1', 'html', new Payload('Report', '<p>'), 1.0)),
            ['title' => 'Report', 'kind' => 'page'],
        );
        Assert::same($views->fieldNames()['html'] ?? null, (new HtmlView())->fieldNames()['html']);
    }

    private static function event(string $bytes): Event
    {
        $stream = StreamHelper::createFileStream();
        $stream->write($bytes);

        return (new Mapper())->map(new Binary($stream));
    }
}
