<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Handler\Http\Middleware\SentryTrap;

use Buggregator\Trap\Handler\Http\Middleware\SentryTrap\EnvelopeParser;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\StreamInterface;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Fiber\RunInFiber;
use Testo\Skip;
use Testo\Test;

#[Covers(EnvelopeParser::class)]
#[RunInFiber]
#[Test]
final class EnvelopeParserTest
{
    private const HEADER = '{"event_id":"abc"}';
    private const EVENT = "{\"type\":\"event\"}\n{\"message\":\"ok\"}\n";

    public function testParsesEnvelopeSentBySdk(): void
    {
        $stream = Stream::create(\file_get_contents(__DIR__ . '/../../../../../Stub/sentry-body.bin'));

        $envelope = EnvelopeParser::parse($stream);

        Assert::same($envelope->headers['event_id'], '1c375fcd2f15438f82d8762f083cd8c0');
        Assert::count($envelope->items, 1);
        Assert::same($envelope->items[0]->headers['type'], 'transaction');
        Assert::same($envelope->items[0]->payload['event_id'], '1c375fcd2f15438f82d8762f083cd8c0');
    }

    public function testParsesItemsWithAndWithoutLength(): void
    {
        $stream = Stream::create(
            self::HEADER . "\n"
            . "{\"type\":\"log\"}\n{\"b\":\"line\"}\n"
            . "{\"type\":\"event\",\"length\":13}\n{\"a\":\"sized\"}",
        );

        $envelope = EnvelopeParser::parse($stream);

        Assert::same($envelope->headers, ['event_id' => 'abc']);
        Assert::count($envelope->items, 2);
        Assert::same($envelope->items[0]->headers, ['type' => 'log']);
        Assert::same($envelope->items[0]->payload, ['b' => 'line']);
        Assert::same($envelope->items[1]->headers, ['type' => 'event', 'length' => 13]);
        Assert::same($envelope->items[1]->payload, ['a' => 'sized']);
    }

    #[DataSet(["{\"type\":\"event\",\"length\":13}\n{\"a\":\"sized\"}\n"], 'text item')]
    #[DataSet(["{\"type\":\"attachment\",\"length\":5}\nab\ncd\n"], 'attachment')]
    #[DataSet(["{\"type\":\"attachment\",\"length\":0}\n\n"], 'zero length attachment')]
    public function testContinuesAfterItemWithLength(string $sizedItem): void
    {
        $stream = Stream::create(self::HEADER . "\n" . $sizedItem . self::EVENT);

        $envelope = EnvelopeParser::parse($stream);

        Assert::count($envelope->items, 2);
        Assert::same($envelope->items[1]->payload, ['message' => 'ok']);
    }

    public function testUsesGivenTime(): void
    {
        $time = new \DateTimeImmutable('2023-01-01 12:00:00');

        $envelope = EnvelopeParser::parse(Stream::create(self::HEADER), $time);

        Assert::same($envelope->time, $time);
    }

    #[DataSet([self::HEADER], 'header without line end')]
    #[DataSet([self::HEADER . "\n"], 'header with line end')]
    #[DataSet([self::HEADER . "\n\n" . self::EVENT], 'empty line after header')]
    public function testEnvelopeWithoutItems(string $body): void
    {
        $envelope = EnvelopeParser::parse(Stream::create($body));

        Assert::same($envelope->headers, ['event_id' => 'abc']);
        Assert::same($envelope->items, []);
    }

    #[DataSet(["{\"type\":\"event\",\"length\":-1}\n{}\n"], 'negative length')]
    #[DataSet(["{\"type\":\"event\",\"length\":1048577}\n{}\n"], 'text item over 1MB')]
    #[DataSet(["{\"type\":\"attachment\",\"length\":104857601}\n{}\n"], 'attachment over 100MB')]
    #[DataSet(["{\"type\":\"event\",\"length\":100}\n{}\n"], 'length beyond stream end')]
    #[DataSet(["{\"type\":\"event\",\"length\":0}\n\n"], 'zero length text item')]
    #[DataSet(["not json\n{}\n"], 'invalid item header')]
    #[DataSet(["{\"type\":\"event\"}\nnot json\n"], 'invalid item payload')]
    public function testStopsParsingAtBrokenItem(string $brokenItem): void
    {
        $stream = Stream::create(self::HEADER . "\n" . self::EVENT . $brokenItem . self::EVENT);

        $envelope = EnvelopeParser::parse($stream);

        Assert::count($envelope->items, 1);
        Assert::same($envelope->items[0]->payload, ['message' => 'ok']);
    }

    #[DataSet(["{\"type\":\"attachment\",\"length\":5}\nab\ncd"], 'with length')]
    #[DataSet(["{\"type\":\"attachment\"}\nabcd\n"], 'without length')]
    #[DataSet(["{\"type\":\"attachment\",\"length\":0}\n"], 'zero length')]
    public function testParsesAttachmentItem(string $attachment): void
    {
        $stream = Stream::create(self::HEADER . "\n" . self::EVENT . $attachment);

        $envelope = EnvelopeParser::parse($stream);

        Assert::count($envelope->items, 2);
        Assert::same($envelope->items[0]->payload, ['message' => 'ok']);
        Assert::same($envelope->items[1]->headers['type'], 'attachment');
    }

    public function testAcceptsAttachmentOverTextItemLimit(): void
    {
        $length = 1024 * 1024 + 1;
        $stream = Stream::create(
            self::HEADER . "\n" . self::EVENT
            . "{\"type\":\"attachment\",\"length\":{$length}}\n" . \str_repeat('x', $length),
        );

        $envelope = EnvelopeParser::parse($stream);

        Assert::count($envelope->items, 2);
        Assert::same($envelope->items[1]->headers['length'], $length);
    }

    #[Skip('Bug #232: attachment payload is the int returned by StreamInterface::write(), not the file stream')]
    public function testStoresAttachmentAsStream(): void
    {
        $stream = Stream::create(self::HEADER . "\n{\"type\":\"attachment\",\"length\":5}\nab\ncd\n");

        $envelope = EnvelopeParser::parse($stream);

        $payload = $envelope->items[0]->payload;
        Assert::instanceOf($payload, StreamInterface::class);
        $payload->rewind();
        Assert::same($payload->getContents(), "ab\ncd");
    }

    public function testReadsPayloadArrivingInChunks(): void
    {
        $stream = $this->stream(self::HEADER . "\n{\"type\":\"event\",\"length\":16}\n{\"message\":\"ok\"}", chunk: 3);

        $envelope = EnvelopeParser::parse($stream);

        Assert::count($envelope->items, 1);
        Assert::same($envelope->items[0]->payload, ['message' => 'ok']);
    }

    public function testStopsWhenStreamOfUnknownSizeEndsBeforeDeclaredLength(): void
    {
        $stream = $this->stream(
            self::HEADER . "\n" . self::EVENT . "{\"type\":\"event\",\"length\":100}\n{}\n",
            unknownSize: true,
        );

        $envelope = EnvelopeParser::parse($stream);

        Assert::count($envelope->items, 1);
        Assert::same($envelope->items[0]->payload, ['message' => 'ok']);
    }

    public function testThrowsOnInvalidEnvelopeHeader(): never
    {
        Expect::exception(\JsonException::class);

        EnvelopeParser::parse(Stream::create("not json\n" . self::EVENT));
    }

    public function testThrowsOnEmptyStream(): never
    {
        Expect::exception(\RuntimeException::class)->withMessage('End of stream.');

        EnvelopeParser::parse(Stream::create(''));
    }

    public function testThrowsWhenHeaderLineIsTooLong(): never
    {
        Expect::exception(\RuntimeException::class)->withMessage('Line is too long.');

        EnvelopeParser::parse(Stream::create(\str_repeat('x', 1024 * 1024 + 1) . "\n"));
    }

    public function testThrowsWhenLineEndCannotBeDetected(): never
    {
        Expect::exception(\RuntimeException::class)->withMessage('Failed to detect line end.');

        EnvelopeParser::parse($this->stream(self::HEADER, unknownSize: true));
    }

    /**
     * Wraps a memory stream to return at most `$chunk` bytes per read or to report no size.
     */
    private function stream(string $data, ?int $chunk = null, bool $unknownSize = false): StreamInterface
    {
        return new class(Stream::create($data), $chunk, $unknownSize) implements StreamInterface {
            public function __construct(
                private readonly StreamInterface $inner,
                private readonly ?int $chunk,
                private readonly bool $unknownSize,
            ) {}

            public function __toString(): string
            {
                return (string) $this->inner;
            }

            public function close(): void
            {
                $this->inner->close();
            }

            public function detach()
            {
                return $this->inner->detach();
            }

            public function getSize(): ?int
            {
                return $this->unknownSize ? null : $this->inner->getSize();
            }

            public function tell(): int
            {
                return $this->inner->tell();
            }

            public function eof(): bool
            {
                return $this->inner->eof();
            }

            public function isSeekable(): bool
            {
                return $this->inner->isSeekable();
            }

            public function seek(int $offset, int $whence = \SEEK_SET): void
            {
                $this->inner->seek($offset, $whence);
            }

            public function rewind(): void
            {
                $this->inner->rewind();
            }

            public function isWritable(): bool
            {
                return false;
            }

            public function write(string $string): int
            {
                throw new \RuntimeException('Read-only stream.');
            }

            public function isReadable(): bool
            {
                return true;
            }

            public function read(int $length): string
            {
                return $this->inner->read($this->chunk === null ? $length : \min($length, $this->chunk));
            }

            public function getContents(): string
            {
                return $this->inner->getContents();
            }

            public function getMetadata(?string $key = null)
            {
                return $this->inner->getMetadata($key);
            }
        };
    }
}
