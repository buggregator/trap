<?php

declare(strict_types=1);

namespace Buggregator\Trap\Service\FilesObserver;

use Buggregator\Trap\Config\Server\Files\ObserverConfig as Config;
use Buggregator\Trap\Logger;
use Buggregator\Trap\Proto\Frame;
use Buggregator\Trap\Service\Container;
use Buggregator\Trap\Support\Timer;

/**
 * The handler is responsible for scanning files in a directory and converting them into frames.
 * It does it in a loop with a given interval.
 *
 * @see Config
 *
 * @internal
 *
 * @implements \IteratorAggregate<int, Frame>
 */
final class Handler implements \IteratorAggregate
{
    private readonly Timer $timer;

    /** @var array<non-empty-string, FileInfo> */
    private array $cache = [];

    /** @var non-empty-string */
    private readonly string $path;

    private FrameConverter $converter;

    public function __construct(
        Config $config,
        private readonly Logger $logger,
        Container $container,
    ) {
        $config->isValid() or throw new \InvalidArgumentException('Invalid configuration.');

        $this->path = $config->path;
        $this->timer = new Timer($config->scanInterval);
        $this->converter = $container->make($config->converterClass, [$config]);
    }

    /**
     * @return \Traversable<int, Frame>
     */
    public function getIterator(): \Traversable
    {
        do {
            foreach ($this->syncFiles() as $info) {
                yield from $this->converter->convert($info);
            }

            $this->timer->wait()->reset();
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
            if (\array_key_exists($path, $this->cache)) {
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
        }
    }
}
