<?php

declare(strict_types=1);

namespace Buggregator\Client\Traffic\Message\Multipart;

use Nyholm\Psr7\UploadedFile;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * @psalm-type FileDataArray = array{
 *     headers: array<string, non-empty-list<string>>,
 *     name?: string,
 *     fileName: string,
 *     size?: int
 * }
 */
final class File extends Part implements UploadedFileInterface
{
    private ?UploadedFileInterface $uploadedFile = null;
    private ?int $fileSize = null;

    public function __construct(array $headers, ?string $name = null, private ?string $fileName = null)
    {
        parent::__construct($headers, $name);
    }

    /**
     * @param FileDataArray $data
     */
    public static function fromArray(array $data): self
    {
        $self = new self($data, $data['name'] ?? null, $data['fileName']);
        $self->fileSize = $data['size'] ?? null;
        return $self;
    }

    /**
     * @return FileDataArray
     */
    public function jsonSerialize(): array
    {
        return parent::jsonSerialize() + [
                'fileName' => $this->fileName,
                'size' => $this->getSize(),
            ];
    }

    public function setStream(StreamInterface $stream, ?int $size = null, int $code = \UPLOAD_ERR_OK): void
    {
        $this->fileSize = $size ?? $stream->getSize() ?? null;
        $this->uploadedFile = new UploadedFile(
            $stream,
            (int) $this->fileSize,
            $code,
            $this->getClientFilename(),
            $this->getClientMediaType(),
        );
    }

    /**
     * @psalm-assert-if-false never $this->getStream()
     * @psalm-assert-if-false never $this->moveTo()
     * @psalm-assert-if-false never $this->getSize()
     * @psalm-assert-if-false never $this->getError()
     * @psalm-assert-if-false never $this->getUploadedFile()
     */
    public function hasStream(): bool
    {
        return isset($this->uploadedFile);
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
        return $this->fileSize;
    }

    public function getError(): int
    {
        return $this->getUploadedFile()->getError();
    }

    public function getClientFilename(): ?string
    {
        return $this->fileName;
    }

    public function getClientMediaType(): ?string
    {
        return $this->getHeader('Content-Type')[0] ?? null;
    }

    private function getUploadedFile(): UploadedFileInterface
    {
        if (!isset($this->uploadedFile)) {
            throw new \RuntimeException('Uploaded file is not set.');
        }
        return $this->uploadedFile;
    }
}
