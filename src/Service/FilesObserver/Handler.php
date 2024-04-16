<?php

declare(strict_types=1);

namespace Buggregator\Trap\Service\FilesObserver;

use Buggregator\Trap\Config\FilesObserver as Config;
use Buggregator\Trap\Logger;
use Buggregator\Trap\Proto\Frame;
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
    private FrameConverter $converter;

    private function __construct(
        Config $config,
        private readonly Logger $logger,
    ) {
        $this->path = $config->path;
        $this->timer = new Timer($config->interval);
        $this->converter = new ($config->converter)();
    }

    /**
     * @return \Generator<int, Frame, mixed, void>
     */
    public static function generate(Config $config, Logger $logger): \Generator
    {
        $self = new self($config, $logger);
        do {
            foreach ($self->syncFiles() as $info) {
                yield from $self->converter->convert($info);
            }

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

        foreach ($files as $info) {
            $path = $info->path;
            if (!\is_string($path) || \array_key_exists($path, $this->cache)) {
                $newState[$path] = $this->cache[$path];
                continue;
            }

            $newState[$path] = $info;
            $newFiles[] = $info;
        }

        $this->cache = $newState;
        return $newFiles;
    }

    /**
     * @return \Traversable<int, FileInfo>
     */
    private function getFiles(): \Traversable
    {
        try {
            /** @var \Iterator<\SplFileInfo> $iterator */
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->path, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST,
            );

            foreach ($iterator as $fileInfo) {
                if ($fileInfo->isFile() && $this->converter->validate($info = FileInfo::fromSplFileInfo($fileInfo))) {
                    yield $info;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->info('Failed to read files from path `%s`', $this->path);
            $this->logger->exception($e);
            return [];
        }
    }
}
