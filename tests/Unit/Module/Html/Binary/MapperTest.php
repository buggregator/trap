<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Html\Binary;

use Buggregator\Trap\Module\Frontend\Message\UiEvent;
use Buggregator\Trap\Module\Html\Binary\Mapper;
use Buggregator\Trap\Module\Html\Binary\Markup;
use Buggregator\Trap\Module\Html\Binary\Payload;
use Buggregator\Trap\Server\Event\EventStore;
use Buggregator\Trap\Server\Event\FrameMapper;
use Buggregator\Trap\Server\Info;
use Buggregator\Trap\Server\Proto\Frame\Binary;
use Buggregator\Trap\Support\StreamHelper;
use Internal\Container\ObjectContainer;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Covers(Mapper::class)]
#[Covers(Payload::class)]
#[Covers(Markup::class)]
#[Covers(UiEvent::class)]
#[Test]
final class MapperTest
{
    public function testBinaryFrameIsHtmlEventOfUi(): void
    {
        $event = (new ObjectContainer())->get(FrameMapper::class)->map(self::frame("\x00\x01binary"));

        Assert::same($event?->type, 'html');
        Assert::instanceOf($event->payload, Payload::class);
        Assert::same($event->projectKey(), 'default');
    }

    public function testPreviewIsCardOfFirstBytes(): void
    {
        $preview = self::payload(\str_repeat("\x00\x01binary", 100))->preview();

        Assert::same($preview['title'], 'Binary data');
        Assert::same($preview['size'], 800);
        Assert::string($preview['html'])
            ->contains('<span class="o">000000f0</span>')
            ->notContains('<span class="o">00000100</span>')
            ->notContains('<script')
            ->contains('… 544.00 B more');
    }

    public function testTitleNamesGuessedFormat(): void
    {
        Assert::same(self::payload("\x1f\x8b\x08\x00")->title, 'Binary data: gzip data');
    }

    public function testPageCarriesBytesAndViewer(): void
    {
        $bytes = "\x00\x01binary";
        $payload = self::payload($bytes);

        $page = $payload->jsonSerialize();

        Assert::same($page['size'], 8);
        Assert::string($page['html'])
            ->startsWith('<!doctype html>')
            ->contains('<div id="bin-data" hidden data-bytes="' . \base64_encode($bytes) . '"></div>')
            ->contains('"download":"/api/binary/' . $payload->uuid . '"')
            ->contains((string) \file_get_contents(Info::TRAP_ROOT . '/resources/binary-viewer/viewer.js'));
    }

    public function testPageCarriesFirstMebibyteOnly(): void
    {
        $page = self::payload(\str_repeat("\x07", Payload::PAGE_BYTES + 10))->jsonSerialize();

        Assert::same($page['size'], Payload::PAGE_BYTES + 10);
        Assert::string($page['html'])
            ->contains('data-bytes="' . \base64_encode(\str_repeat("\x07", Payload::PAGE_BYTES)) . '"');
    }

    public function testFeedGetsPreviewAndPageWholePayload(): void
    {
        $store = new EventStore();
        $event = (new Mapper())->map(self::frame(\str_repeat('x', 100_000) . "\x00"));
        $store->add($event);

        $feed = \json_encode(UiEvent::of($event, $store, preview: true), \JSON_THROW_ON_ERROR);
        $page = \json_encode(UiEvent::of($event, $store), \JSON_THROW_ON_ERROR);

        Assert::true(\strlen($feed) < 10_000);
        Assert::true(\strlen($page) > 100_000);
    }

    /**
     * The bytes come in a sandboxed frame, but markup of them must not become markup of the card.
     */
    public function testMarkupOfBytesIsEscaped(): void
    {
        $payload = self::payload("\x00<script>alert(1)</script><img src=x onerror=\"a\">&amp;");

        Assert::string($payload->preview()['html'])
            ->notContains('<script')
            ->notContains('<img')
            ->contains('&lt;script&gt;')
            ->contains('&quot;a')
            ->contains('&amp;amp;');
        Assert::string($payload->jsonSerialize()['html'])->notContains('<img');
    }

    public function testFormatNameOfBytesIsEscaped(): void
    {
        $payload = self::payload('a:1:{s:3:"<b>";i:1;}');

        Assert::string($payload->preview()['html'])->notContains('<b>');
    }

    public function testViewerScriptCannotEndEarly(): void
    {
        foreach (['viewer.js', 'viewer.css', 'card.css'] as $file) {
            $text = (string) \file_get_contents(Info::TRAP_ROOT . '/resources/binary-viewer/' . $file);
            Assert::false(\stripos($text, '</script') !== false || \stripos($text, '</style') !== false);
        }
    }

    public function testReadingLeavesStreamPosition(): void
    {
        $stream = StreamHelper::createFileStream();
        $stream->write('0123456789');
        $stream->seek(3);

        $payload = new Payload('a1', $stream, new \DateTimeImmutable());
        $payload->jsonSerialize();

        Assert::same($stream->tell(), 3);
        Assert::same($payload->bytes(4), '0123');
    }

    public function testFileNameHasExtensionOfFormat(): void
    {
        $time = new \DateTimeImmutable('2026-11-25 10:20:30');

        Assert::same((new Payload('a1', self::stream("\x89PNG\r\n\x1a\n"), $time))->fileName(), 'binary-20261125-102030.png');
        Assert::same((new Payload('a1', self::stream("\x00\x01"), $time))->fileName(), 'binary-20261125-102030.bin');
    }

    /**
     * A port check or a TCP health check connects and closes.
     */
    public function testConnectionWithoutBytesMakesNoEvent(): void
    {
        Assert::null((new ObjectContainer())->get(FrameMapper::class)->map(self::frame('')));
    }

    public function testOneByteMakesEvent(): void
    {
        Assert::same(self::payload("\x00")->preview()['size'], 1);
    }

    private static function payload(string $bytes): Payload
    {
        $event = (new Mapper())->map(self::frame($bytes));
        \assert($event->payload instanceof Payload);

        return $event->payload;
    }

    private static function frame(string $bytes): Binary
    {
        return new Binary(self::stream($bytes));
    }

    private static function stream(string $bytes): \Psr\Http\Message\StreamInterface
    {
        $stream = StreamHelper::createFileStream();
        $stream->write($bytes);

        return $stream;
    }
}
