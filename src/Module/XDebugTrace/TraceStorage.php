<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\XDebugTrace;

use Buggregator\Trap\Module\XDebugTrace\Config\Traces;
use Buggregator\Trap\Module\XDebugTrace\Trace\Summary;
use Buggregator\Trap\Module\XDebugTrace\Trace\TraceError;
use Buggregator\Trap\Module\XDebugTrace\Trace\TraceReader;
use Buggregator\Trap\Server\Event\EventStore;
use Buggregator\Trap\Server\Event\Record;
use Buggregator\Trap\Server\Event\StoreListener;
use Buggregator\Trap\Support\TimeSlice;
use Buggregator\Trap\Support\Uuid;

/**
 * The trace files of this process, in `trap-xdebug-traces-<pid>-<random>` of the system temp directory, named by the UUID
 * of their event: `<uuid>.xt` or `<uuid>.xt.gz`, as they came.
 *
 * At most {@see Traces::keepCount()} traces are kept, the oldest go first with their events. A deleted event
 * takes its file with it. The directory is removed at exit; directories left by a killed process are removed
 * by the next one: each process holds a lock on its own.
 *
 * A container singleton.
 *
 * @psalm-import-type SummaryData from Summary
 *
 * @internal
 */
final class TraceStorage implements StoreListener
{
    private const PREFIX = 'trap-xdebug-traces-';
    private const LOCK = '.lock';

    /** @var array<non-empty-string, non-empty-string> File paths by UUID, the oldest first */
    private array $files = [];

    private ?string $dir = null;

    /** @var resource|null */
    private $lock = null;

    public function __construct(
        private readonly Traces $config,
        private readonly EventStore $events,
        private readonly ?string $baseDir = null,
    ) {
        $events->listen($this);
    }

    /**
     * Copies a trace into the storage and summarizes it.
     *
     * @param resource $source Read from the current position to the end.
     *
     * @throws TraceError When the trace is not in the format 1, or is larger than the upload limit.
     * @throws \RuntimeException When the file can't be written.
     */
    public function store($source, ?TimeSlice $slice = null): StoredTrace
    {
        $uuid = Uuid::generate();
        $part = $this->dir() . \DIRECTORY_SEPARATOR . $uuid . '.part';
        $target = @\fopen($part, 'xb');
        $target === false and throw new \RuntimeException("Can't create the trace file `$part`.");

        try {
            $size = 0;
            $limit = $this->config->maxUploadBytes();
            while (!\feof($source)) {
                $chunk = \fread($source, 65536);
                if ($chunk === false) {
                    throw new \RuntimeException('Failed to read the trace.');
                }
                $size += \strlen($chunk);
                $size > $limit and throw TraceError::tooLarge($limit);
                \fwrite($target, $chunk) === \strlen($chunk) or throw new \RuntimeException("Failed to write `$part`.");
                $slice?->tick();
            }
            \fclose($target);
            $target = null;

            $summary = Summary::summarize($part, $this->config->maxSizeBytes(), $slice);
            $compressed = TraceReader::isGzip($part);
            $path = $this->dir() . \DIRECTORY_SEPARATOR . $uuid . ($compressed ? '.xt.gz' : '.xt');
            \rename($part, $path) or throw new \RuntimeException("Can't move the trace file to `$path`.");
        } catch (\Throwable $e) {
            \is_resource($target) and \fclose($target);
            @\unlink($part);
            throw $e;
        }

        $this->files[$uuid] = $path;
        $this->trim();

        return new StoredTrace($uuid, $path, $size, $compressed, $summary);
    }

    /**
     * @return non-empty-string|null Null when the trace is not kept.
     */
    public function path(string $uuid): ?string
    {
        $path = $this->files[$uuid] ?? null;

        return $path !== null && \is_file($path) ? $path : null;
    }

    #[\Override]
    public function added(Record $record): void {}

    #[\Override]
    public function updated(Record $record): void {}

    #[\Override]
    public function deleted(Record $record): void
    {
        $this->remove($record->event->uuid);
    }

    #[\Override]
    public function cleared(): void
    {
        foreach (\array_keys($this->files) as $uuid) {
            $this->remove($uuid);
        }
    }

    public function __destruct()
    {
        $this->removeDir();
    }

    /**
     * Removes the directories of the processes that are gone: their lock is free.
     */
    private static function sweep(string $base): void
    {
        $dirs = \glob($base . \DIRECTORY_SEPARATOR . self::PREFIX . '*', \GLOB_ONLYDIR);
        foreach ($dirs === false ? [] : $dirs as $dir) {
            $lock = @\fopen($dir . \DIRECTORY_SEPARATOR . self::LOCK, 'cb');
            if ($lock === false) {
                continue;
            }
            $free = \flock($lock, \LOCK_EX | \LOCK_NB);
            \fclose($lock);
            $free and self::removeTree($dir);
        }
    }

    private static function removeTree(string $dir): void
    {
        $names = @\scandir($dir);
        foreach ($names === false ? [] : $names as $name) {
            $name === '.' || $name === '..' or @\unlink($dir . \DIRECTORY_SEPARATOR . $name);
        }
        @\rmdir($dir);
    }

    /**
     * Drops the oldest traces over the limit, with their events.
     */
    private function trim(): void
    {
        $over = \count($this->files) - $this->config->keepCount();
        foreach (\array_slice(\array_keys($this->files), 0, \max(0, $over)) as $uuid) {
            $this->remove($uuid);
            $this->events->delete($uuid);
        }
    }

    private function remove(string $uuid): void
    {
        $path = $this->files[$uuid] ?? null;
        unset($this->files[$uuid]);
        $path === null or @\unlink($path);
    }

    /**
     * @return non-empty-string
     */
    private function dir(): string
    {
        if ($this->dir !== null && \is_dir($this->dir)) {
            return $this->dir;
        }

        $base = \rtrim($this->baseDir ?? \sys_get_temp_dir(), '/\\');
        self::sweep($base);
        $dir = $base . \DIRECTORY_SEPARATOR . self::PREFIX . (int) \getmypid() . '-' . \bin2hex(\random_bytes(4));
        @\mkdir($dir, 0o700, true) or throw new \RuntimeException("Can't create the trace directory `$dir`.");
        $lock = \fopen($dir . \DIRECTORY_SEPARATOR . self::LOCK, 'cb');
        $lock === false or \flock($lock, \LOCK_EX | \LOCK_NB) or throw new \RuntimeException("Can't lock `$dir`.");
        $this->lock = $lock === false ? null : $lock;
        $this->files = [];

        return $this->dir = $dir;
    }

    private function removeDir(): void
    {
        if ($this->dir === null) {
            return;
        }

        // Windows can't delete a file still open
        $lock = $this->lock;
        $this->lock = null;
        $lock === null or \fclose($lock);
        self::removeTree($this->dir);
        $this->dir = null;
        $this->files = [];
    }
}
