<?php

declare(strict_types=1);

namespace Buggregator\Trap\Traffic\Message\Multipart;

use Nyholm\Psr7\UploadedFile;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * @psalm-type FileDataArray = array{
 *     headers: array<array-key, non-empty-list<string>>,
 *     name?: string,
 *     fileName?: string,
 *     size?: non-negative-int
 * }
 *
 * @internal
 */
final class File extends Part implements UploadedFileInterface
{
    private ?UploadedFileInterface $uploadedFile = null;
    /** @var non-negative-int|null  */
    private ?int $fileSize = null;

    /**
     * @param array<array-key, non-empty-list<string>> $headers
     */
    public function __construct(array $headers, ?string $name = null, private ?string $fileName = null)
    {
        parent::__construct($headers, $name);
    }

    /**
     * @param FileDataArray $data
     */
    public static function fromArray(array $data): self
    {
        $self = new self($data['headers'], $data['name'] ?? null, $data['fileName'] ?? null);
        $self->fileSize = $data['size'] ?? null;
        return $self;
    }

    /**
     * @return FileDataArray
     *
     * @psalm-suppress ImplementedReturnTypeMismatch
     */
    public function jsonSerialize(): array
    {
        $data = parent::jsonSerialize();
        $this->fileName === null or $data['fileName'] = $this->fileName;
        $this->fileSize === null or $data['size'] = $this->fileSize;

        return $data;
    }

    /**
     * @param non-negative-int|null $size
     */
    public function setStream(StreamInterface $stream, ?int $size = null, int $code = \UPLOAD_ERR_OK): void
    {
        /** @psalm-suppress PropertyTypeCoercion */
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

    /**
     * @return non-negative-int|null
     */
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
