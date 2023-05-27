<?php

declare(strict_types=1);

namespace Buggregator\Client\Traffic\Message\Multipart;

use Nyholm\Psr7\UploadedFile;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;

class File extends Part implements UploadedFileInterface
{
    private UploadedFileInterface $uploadedFile;

    public function __construct(array $headers, ?string $name = null, private ?string $fileName = null)
    {
        parent::__construct($headers, $name);
    }

    public function isFile(): bool
    {
        return true;
    }

    public function setStream(StreamInterface $stream, ?int $size = null, int $code = \UPLOAD_ERR_OK): void
    {
        $this->uploadedFile = new UploadedFile(
            $stream,
            $size ?? $stream->getSize() ?? 0,
            $code,
            $this->fileName,
            $this->getHeader('Content-Type')[0] ?? null,
        );
    }

    public function getStream(): StreamInterface
    {
        return $this->getUploadedFile()->getStream();
    }

    public function moveTo(string $targetPath): void
    {
        $this->getUploadedFile()->moveTo($targetPath);
    }

    public function getSize(): ?int
    {
        return $this->getUploadedFile()->getSize();
    }

    public function getError(): int
    {
        return $this->getUploadedFile()->getError();
    }

    public function getClientFilename(): ?string
    {
        return $this->getUploadedFile()->getClientFilename();
    }

    public function getClientMediaType(): ?string
    {
        return $this->getUploadedFile()->getClientMediaType();
    }

    private function getUploadedFile(): UploadedFileInterface
    {
        if (!isset($this->uploadedFile)) {
            throw new \RuntimeException('Uploaded file is not set.');
        }
        return $this->uploadedFile;
    }
}
