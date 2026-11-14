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

    /** @var float|null See {@see Config::$abandonAfter} */
    private readonly ?float $abandonAfter;

    /** Whether the directory was missing at the last scan, so that it is logged once */
    private bool $missing = false;

    private readonly Timer $timer;
    private readonly float $scanInterval;

    /**
     * @var array<non-empty-string, array{FileInfo, float, bool}> Files being written: their last state, when it changed,
     *      and whether they are unchanged since before the observer started
     */
    private array $pending = [];

    /** @var array<non-empty-string, FileInfo> */
    private array $cache = [];

    /** @var non-empty-string */
    private readonly string $path;

    private FrameConverter $converter;

    /** @var int|null Files of the first scan modified before it are skipped, see {@see Config::$skipOldFiles} */
    private ?int $skipBefore;

    /** @var array<string, true> Subdirectories logged as unreadable */
    private array $unreadable = [];

    /** @var \Closure(string): iterable<\SplFileInfo> */
    private readonly \Closure $listing;

    /**
     * @param (\Closure(string): iterable<\SplFileInfo>)|null $listing Lists a directory; for tests.
     */
    public function __construct(
        Config $config,
        private readonly Logger $logger,
        Container $container,
        ?\Closure $listing = null,
    ) {
        $config->isValid() or throw new \InvalidArgumentException('Invalid configuration.');

        $this->listing = $listing ?? static fn(string $dir): iterable => new \FilesystemIterator($dir, \FilesystemIterator::SKIP_DOTS);
        $this->skipBefore = $config->skipOldFiles ? \time() : null;
        $this->abandonAfter = $config->abandonAfter;
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
                $this->syncFiles();
            }

            yield from $this->followPending();
            $this->timer->wait()->reset();
        } while (true);
    }

    /**
     * @return \Traversable<int, Frame>
     */
    private function convert(FileInfo $info): \Traversable
    {
        try {
            yield from $this->converter->convert($info);
        } finally {
            $this->forget($info->path);
        }
    }

    /**
     * Converts the new files and the files being written that are complete now.
     *
     * A converter may take several checks to read a large file, so the files are checked on each pass,
     * changed or not. A file complete before the observer started, see {@see Config::$skipOldFiles}, is skipped.
     *
     * @return \Traversable<int, Frame>
     */
    private function followPending(): \Traversable
    {
        foreach ($this->pending as $path => [$last, $changedAt, $old]) {
            \clearstatcache(true, $path);
            if (!\is_file($path)) {
                // A file put back at the same path is new then, however soon it comes back
                unset($this->cache[$path]);
                $this->forget($path);
                continue;
            }

            $info = FileInfo::fromSplFileInfo(new \SplFileInfo($path));
            $changed = $info->size !== $last->size || $info->mtime !== $last->mtime;
            if ($changed) {
                [$changedAt, $old] = [\microtime(true), false];
            }
            // The state the file is left in: a scan takes a later change for a file written anew
            $this->cache[$path] = $info;

            if ($this->converter->isReady($info)) {
                if ($old) {
                    $this->forget($path);
                } else {
                    yield from $this->convert($info);
                }
            } elseif (!$changed && $this->isAbandoned($changedAt)) {
                $this->abandon($info, log: !$old);
            } else {
                $this->pending[$path] = [$info, $changedAt, $old];
            }
        }
    }

    /**
     * Stops following a file that stays incomplete, until it changes, see {@see self::syncFiles()}.
     */
    private function abandon(FileInfo $info, bool $log): void
    {
        $this->forget($info->path);
        $log and $this->logger->info('File `%s` stays incomplete, skipped until it changes.', $info->path);
    }

    /**
     * Stops following a file, and lets the converter release what it keeps for it.
     */
    private function forget(string $path): void
    {
        unset($this->pending[$path]);
        $this->converter instanceof ForgetsFiles and $this->converter->forget($path);
    }

    /**
     * Puts the new files to follow, and the known ones that changed since, abandoned or converted: a profiler may
     * write a file anew at the same path, or put one back.
     *
     * An old file unchanged for longer than the abandon timeout is abandoned at once, unread: the trace of a run
     * killed long ago never ends, and an old complete file is skipped anyway.
     */
    private function syncFiles(): void
    {
        $listing = $this->getFiles();
        // A failed listing would make the files left out look new on the next scan, and converted again
        if ($listing === null) {
            return;
        }

        [$files, $unreadable] = $listing;
        $newState = [];
        foreach ($this->cache as $path => $info) {
            foreach ($unreadable as $dir) {
                \str_starts_with($path, $dir . \DIRECTORY_SEPARATOR) and $newState[$path] = $info;
            }
        }

        foreach ($files as $info) {
            $path = $info->path;
            $newState[$path] = $info;
            $known = $this->cache[$path] ?? null;
            if ($known !== null) {
                if (!isset($this->pending[$path]) && ($known->size !== $info->size || $known->mtime !== $info->mtime)) {
                    $this->pending[$path] = [$info, \microtime(true), false];
                }
                continue;
            }

            $old = $this->skipBefore !== null && $info->mtime < $this->skipBefore;
            $old && $this->isAbandoned((float) $info->mtime)
                or $this->pending[$path] = [$info, $old ? (float) $info->mtime : \microtime(true), $old];
        }

        $this->skipBefore = null;
        $this->cache = $newState;
    }

    private function isAbandoned(float $changedAt): bool
    {
        return $this->abandonAfter !== null && \microtime(true) - $changedAt > $this->abandonAfter;
    }

    /**
     * Lists the files, skipping the subdirectories that can't be read, as a private one of another user in `/tmp`.
     *
     * @return array{list<FileInfo>, list<string>}|null The files and the real paths of the subdirectories that could
     *         not be read; null when the directory is missing or can't be listed.
     */
    private function getFiles(): ?array
    {
        // A profiler creates its directory with the first file
        if (!\is_dir($this->path)) {
            $this->missing or $this->logger->info('Directory `%s` does not exist yet, waiting for it.', $this->path);
            $this->missing = true;
            return null;
        }
        $this->missing = false;

        $files = [];
        $unreadable = [];
        /** @var list<string> $dirs */
        $dirs = [$this->path];
        while (($dir = \array_pop($dirs)) !== null) {
            try {
                foreach (($this->listing)($dir) as $entry) {
                    try {
                        if ($entry->isDir()) {
                            $entry->isLink() or $dirs[] = $entry->getPathname();
                            continue;
                        }
                        $info = FileInfo::fromSplFileInfo($entry);
                    } catch (\Throwable) {
                        // Removed or renamed since it was listed
                        continue;
                    }
                    $entry->isFile() && $this->converter->validate($info) and $files[] = $info;
                }
            } catch (\Throwable $e) {
                if ($dir === $this->path) {
                    $this->logger->info('Failed to read files from path `%s`', $this->path);
                    $this->logger->exception($e);
                    return null;
                }
                $real = \realpath($dir);
                $unreadable[] = $real === false ? $dir : $real;
                isset($this->unreadable[$dir]) or $this->logger->info('Directory `%s` can not be read, skipped.', $dir);
                $this->unreadable[$dir] = true;
            }
        }

        return [$files, $unreadable];
    }
}
