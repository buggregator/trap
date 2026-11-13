<?php

declare(strict_types=1);

namespace Buggregator\Trap\Support\Stream;

use Psr\Http\Message\StreamInterface;

/**
 * Read-only view of a seekable stream with its own read position.
 *
 * Several views may read the same origin stream in different fibers: each one seeks
 * the origin to its own position before reading.
 * Closing or detaching a view leaves the origin stream open for the other readers.
 *
 * Parameters are untyped to stay compatible with both psr/http-message 1.x and 2.x.
 *
 * @internal
 * @psalm-internal Buggregator\Trap
 */
final class ConcurrentReadStream implements StreamInterface
{
    /** @var int<0, max> */
    private int $position = 0;

    private bool $eof = false;
    private bool $detached = false;

    /**
     * @throws \InvalidArgumentException If the stream is not seekable.
     */
    public function __construct(
        private readonly StreamInterface $origin,
    ) {
        $origin->isSeekable() or throw new \InvalidArgumentException('Cannot read concurrently a non seekable stream.');
    }

    #[\Override]
    public function close(): void
    {
        $this->detached = true;
    }

    #[\Override]
    public function detach()
    {
        $this->detached = true;
        return null;
    }

    #[\Override]
    public function getSize(): ?int
    {
        return $this->detached ? null : $this->origin->getSize();
    }

    #[\Override]
    public function tell(): int
    {
        $this->assertAttached();
        return $this->position;
    }

    #[\Override]
    public function eof(): bool
    {
        if ($this->detached || $this->eof) {
            return true;
        }

        $size = $this->origin->getSize();
        return $size !== null && $this->position >= $size;
    }

    #[\Override]
    public function isSeekable(): bool
    {
        return !$this->detached;
    }

    /**
     * @param int $offset
     * @param int $whence
     */
    #[\Override]
    public function seek($offset, $whence = \SEEK_SET): void
    {
        $this->assertAttached();

        $this->origin->seek((int) $offset, (int) $whence);
        $this->position = \max(0, $this->origin->tell());
        $this->eof = false;
    }

    #[\Override]
    public function rewind(): void
    {
        $this->seek(0);
    }

    #[\Override]
    public function isWritable(): bool
    {
        return false;
    }

    /**
     * @param string $string
     */
    #[\Override]
    public function write($string): int
    {
        throw new \RuntimeException('Cannot write to a concurrent read stream.');
    }

    #[\Override]
    public function isReadable(): bool
    {
        return !$this->detached && $this->origin->isReadable();
    }

    /**
     * @param int $length
     */
    #[\Override]
    public function read($length): string
    {
        $this->assertAttached();

        $this->origin->seek($this->position);
        $data = $this->origin->read((int) $length);
        $this->position = \max(0, $this->origin->tell());
        $this->eof = $this->origin->eof();

        return $data;
    }

    #[\Override]
    public function getContents(): string
    {
        $this->assertAttached();

        $this->origin->seek($this->position);
        $data = $this->origin->getContents();
        $this->position = \max(0, $this->origin->tell());
        $this->eof = true;

        return $data;
    }

    /**
     * @param string|null $key
     */
    #[\Override]
    public function getMetadata($key = null)
    {
        if ($this->detached) {
            return $key === null ? [] : null;
        }

        return $this->origin->getMetadata($key);
    }

    public function __toString(): string
    {
        if ($this->detached) {
            return '';
        }

        try {
            $this->rewind();
            return $this->getContents();
        } catch (\Throwable) {
            return '';
        }
    }

    private function assertAttached(): void
    {
        $this->detached and throw new \RuntimeException('Stream is detached.');
    }
}
