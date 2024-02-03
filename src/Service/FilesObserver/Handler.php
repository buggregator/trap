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
        $newState = [];

        foreach ($files as $fileInfo) {
            $path = $fileInfo->getRealPath();
            if (!\is_string($path) || \array_key_exists($path, $this->cache)) {
                $newState[$path] = $this->cache[$path];
                continue;
            }

            $info = FileInfo::fromSplFileInfo($fileInfo);
            $newState[$path] = $info;
            $newFiles[] = $info;
        }

        $this->cache = $newState;
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
