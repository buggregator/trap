<?php

declare(strict_types=1);

namespace Buggregator\Trap\Service\FilesObserver;

/**
 * @internal
 */
final class FileInfo
{
    /**
     * @param non-empty-string $path
     * @param int<0, max> $size
     * @param int<0, max> $ctime
     * @param int<0, max> $mtime
     */
    public function __construct(
        public readonly string $path,
        public readonly int $size,
        public readonly int $ctime,
        public readonly int $mtime,
    ) {}

    public static function fromSplFileInfo(\SplFileInfo $fileInfo): self
    {
        /** @psalm-suppress ArgumentTypeCoercion */
        return new self(
            $fileInfo->getRealPath(),
            $fileInfo->getSize(),
            $fileInfo->getCTime(),
            $fileInfo->getMTime(),
        );
    }

    /**
     * @param array{
     *     path: non-empty-string,
     *     size: int<0, max>,
     *     ctime: int<0, max>,
     *     mtime: int<0, max>
     * } $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['path'],
            $data['size'],
            $data['ctime'],
            $data['mtime'],
        );
    }

    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'size' => $this->size,
            'ctime' => $this->ctime,
            'mtime' => $this->mtime,
        ];
    }

    public function getExtension(): string
    {
        return \pathinfo($this->path, PATHINFO_EXTENSION);
    }

    public function getName(): string
    {
        return \pathinfo($this->path, PATHINFO_FILENAME);
    }
}
