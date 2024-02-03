<?php

declare(strict_types=1);

namespace Buggregator\Trap\Service\FilesObserver;

use Buggregator\Trap\Config\FilesObserver as Config;
use Buggregator\Trap\Support\Timer;

/**
 * @internal
 */
final class Handler
{
    private readonly Timer $timer;
    /** @var array<non-empty-string, FileInfo> */
    private array $cache = [];
    private readonly string $path;

    private function __construct(
        Config $config,
    ) {
        $this->path = $config->path;
        $this->timer = new Timer($config->interval);
    }

    /**
     * @return \Generator<int, FileInfo, mixed, void>
     */
    public static function generate(Config $config): \Generator
    {
        $self = new self($config);
        do {
            yield from $self->syncFiles();
            $self->timer->wait()->reset();
        } while (true);
    }

    /**
     * @return list<FileInfo>
     */
    private function syncFiles(): array
    {
        $files = $this->getFiles();
        $newFiles = [];

        foreach ($files as $fileInfo) {
            if (\array_key_exists($fileInfo->getRealPath(), $this->cache)) {
                continue;
            }

            $info = FileInfo::fromSplFileInfo($fileInfo);
            $this->cache[$fileInfo->getRealPath()] = $info;
            $newFiles[] = $info;
        }

        return $newFiles;
    }

    /**
     * @return \Traversable<int, \SplFileInfo>
     */
    private function getFiles(): \Traversable
    {
        /** @var \Iterator<\SplFileInfo> $iterator */
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isFile()) {
                yield $fileInfo;
            }
        }
    }
}
