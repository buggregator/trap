<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Support\Stub;

use Nyholm\Psr7\Stream;
use Psr\Http\Message\StreamInterface;

/**
 * A memory stream that returns at most `$chunk` bytes per read, as a socket does, and records the lengths asked.
 */
final class ChunkedStream implements StreamInterface
{
    /** @var list<int> Lengths passed to {@see self::read()}. */
    public array $reads = [];

    private readonly StreamInterface $inner;

    public function __construct(string $data, private readonly int $chunk = \PHP_INT_MAX)
    {
        $this->inner = Stream::create($data);
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
        return $this->inner->getSize();
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
        $this->reads[] = $length;

        return $this->inner->read(\min($length, $this->chunk));
    }

    public function getContents(): string
    {
        return $this->inner->getContents();
    }

    public function getMetadata(?string $key = null)
    {
        return $this->inner->getMetadata($key);
    }

    public function __toString(): string
    {
        return (string) $this->inner;
    }
}
