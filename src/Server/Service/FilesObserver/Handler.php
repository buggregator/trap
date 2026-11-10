<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Service\FilesObserver;

use Buggregator\Trap\Server\Config\Files\ObserverConfig as Config;
use Buggregator\Trap\Server\Logger;
use Buggregator\Trap\Server\Proto\Frame;
use Buggregator\Trap\Support\Timer;
use Internal\Container\Container;

/**
 * The handler is responsible for scanning files in a directory and converting them into frames.
 * It does it in a loop with a given interval.
 * A file still being written is followed more often and converted as soon as it is complete.
 *
 * @see Config
 *
 * @internal
 *
 * @implements \IteratorAggregate<int, Frame>
 */
final class Handler implements \IteratorAggregate
{
    /** @var float Seconds between the checks of the files being written */
    private const FOLLOW_INTERVAL = 1.0;

    /** @var float Seconds a file being written may stay unchanged before it is dropped */
    private const ABANDON_AFTER = 600.0;

    private readonly Timer $timer;
    private readonly float $scanInterval;

    /** @var array<non-empty-string, array{FileInfo, float}> Files being written: their last state and when it changed */
    private array $pending = [];

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
        $this->scanInterval = $config->scanInterval;
        $this->timer = new Timer(\min(self::FOLLOW_INTERVAL, $config->scanInterval));
        /** @psalm-suppress InvalidArgument The config is matched by type, so a custom converter may name the parameter freely */
        $this->converter = $container->make($config->converterClass, [$config]);
    }

    /**
     * @return \Traversable<int, Frame>
     */
    #[\Override]
    public function getIterator(): \Traversable
    {
        $scannedAt = null;
        do {
            if ($scannedAt === null || \microtime(true) - $scannedAt >= $this->scanInterval) {
                $scannedAt = \microtime(true);
                foreach ($this->syncFiles() as $info) {
                    if ($this->converter->isReady($info)) {
                        yield from $this->converter->convert($info);
                    } else {
                        $this->pending[$info->path] = [$info, \microtime(true)];
                    }
                }
            }

            yield from $this->followPending();
            $this->timer->wait()->reset();
        } while (true);
    }

    /**
     * Converts the files being written that are complete now.
     *
     * @return \Traversable<int, Frame>
     */
    private function followPending(): \Traversable
    {
        foreach ($this->pending as $path => [$last, $changedAt]) {
            \clearstatcache(true, $path);
            if (!\is_file($path)) {
                unset($this->pending[$path]);
                continue;
            }

            $info = FileInfo::fromSplFileInfo(new \SplFileInfo($path));
            if ($info->size === $last->size && $info->mtime === $last->mtime) {
                if (\microtime(true) - $changedAt > self::ABANDON_AFTER) {
                    unset($this->pending[$path]);
                    $this->logger->info('File `%s` stays incomplete, skipped.', $path);
                }
                continue;
            }

            if ($this->converter->isReady($info)) {
                unset($this->pending[$path]);
                yield from $this->converter->convert($info);
            } else {
                $this->pending[$path] = [$info, \microtime(true)];
            }
        }
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
