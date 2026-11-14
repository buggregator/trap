<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Profiler\Http\Stub;

use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * An uploaded file of an unknown size.
 */
final class UnsizedUpload implements UploadedFileInterface
{
    private function __construct(
        private readonly StreamInterface $stream,
    ) {}

    public static function of(string $content): self
    {
        return new self(UnsizedStream::of($content));
    }

    #[\Override]
    public function getStream(): StreamInterface
    {
        return $this->stream;
    }

    /**
     * @param string $targetPath
     */
    #[\Override]
    public function moveTo($targetPath): void
    {
        throw new \LogicException('Not supported.');
    }

    #[\Override]
    public function getSize(): ?int
    {
        return null;
    }

    #[\Override]
    public function getError(): int
    {
        return \UPLOAD_ERR_OK;
    }

    #[\Override]
    public function getClientFilename(): ?string
    {
        return null;
    }

    #[\Override]
    public function getClientMediaType(): ?string
    {
        return null;
    }
}
