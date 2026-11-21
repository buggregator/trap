<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\XDebugTrace;

use Buggregator\Trap\Module\XDebugTrace\Config\Traces;
use Buggregator\Trap\Module\XDebugTrace\Trace\TraceError;
use Buggregator\Trap\Module\XDebugTrace\TraceStorage;
use Buggregator\Trap\Server\Event\Event;
use Buggregator\Trap\Server\Event\EventStore;
use Buggregator\Trap\Tests\Unit\Module\XDebugTrace\Trace\TraceReaderTest;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Covers(TraceStorage::class)]
#[Test]
final class TraceStorageTest
{
    private string $base;

    #[BeforeTest]
    public function createBase(): void
    {
        $this->base = \sys_get_temp_dir() . '/trap-xt-storage-' . \bin2hex(\random_bytes(4));
        \mkdir($this->base);
    }

    #[AfterTest]
    public function removeBase(): void
    {
        foreach (\glob($this->base . '/*/{,.}*', \GLOB_BRACE) ?: [] as $file) {
            \is_file($file) and @\unlink($file);
        }
        foreach (\glob($this->base . '/*') ?: [] as $dir) {
            @\rmdir($dir);
        }
        @\rmdir($this->base);
    }

    public function testStoresGzippedTraceAsSent(): void
    {
        $storage = $this->storage();

        $trace = $storage->store(self::open(TraceReaderTest::MAIN));

        Assert::true($trace->compressed);
        Assert::same($trace->size, \filesize(TraceReaderTest::MAIN));
        Assert::string($trace->path)->endsWith($trace->uuid . '.xt.gz');
        Assert::same(\file_get_contents($trace->path), \file_get_contents(TraceReaderTest::MAIN));
        Assert::same($trace->summary['calls'], 39);
        Assert::same($storage->path($trace->uuid), $trace->path);
    }

    public function testStoresPlainTrace(): void
    {
        $source = \fopen('php://temp', 'w+b');
        \fwrite($source, (string) \gzdecode((string) \file_get_contents(TraceReaderTest::MID)));
        \rewind($source);

        $trace = $this->storage()->store($source);

        Assert::false($trace->compressed);
        Assert::string($trace->path)->endsWith('.xt');
        Assert::same($trace->summary['calls'], 12);
    }

    public function testRejectsOtherContentAndKeepsNoFile(): void
    {
        $source = \fopen('php://temp', 'w+b');
        \fwrite($source, "not a trace\n");
        \rewind($source);
        $storage = $this->storage();

        $error = null;
        try {
            $storage->store($source);
        } catch (TraceError $error) {
        }

        Assert::same($error?->reason, TraceError::NOT_FORMAT_1);
        Assert::same(\glob($this->base . '/*/*') ?: [], []);
    }

    public function testRejectsUploadOverLimit(): never
    {
        $config = new Traces();
        $config->maxUpload = '1K';

        Expect::exception(TraceError::class)->withMessage('The trace is larger than the limit of 1024 bytes.');

        $this->storage($config)->store(self::open(TraceReaderTest::MAIN));
    }

    public function testDropsOldestTracesWithTheirEvents(): void
    {
        $config = new Traces();
        $config->keep = 2;
        $events = new EventStore();
        $storage = $this->storage($config, $events);

        $uuids = [];
        for ($i = 0; $i < 3; ++$i) {
            $trace = $storage->store(self::open(TraceReaderTest::MID));
            $events->add(new Event($trace->uuid, 'xdebug-trace', [], \microtime(true)));
            $uuids[] = $trace->uuid;
        }

        Assert::null($storage->path($uuids[0]));
        Assert::null($events->get($uuids[0]));
        Assert::notSame($storage->path($uuids[2]), null);
        Assert::count(self::traceFiles($this->base), 2);
    }

    public function testDeletedEventTakesItsFile(): void
    {
        $events = new EventStore();
        $storage = $this->storage(events: $events);
        $kept = $storage->store(self::open(TraceReaderTest::MID));
        $gone = $storage->store(self::open(TraceReaderTest::MID));
        $events->add(new Event($gone->uuid, 'xdebug-trace', [], 1.0));

        $events->delete($gone->uuid);

        Assert::false(\is_file($gone->path));
        Assert::true(\is_file($kept->path));

        $events->clear();

        Assert::false(\is_file($kept->path));
    }

    public function testRemovesDirectoryOfGoneProcess(): void
    {
        $stale = $this->base . '/trap-xdebug-traces-999999';
        \mkdir($stale);
        \touch($stale . '/.lock');
        \touch($stale . '/old.xt');

        $this->storage();

        Assert::false(\is_dir($stale));
    }

    public function testRemovesItsDirectoryWhenDestroyed(): void
    {
        $storage = $this->storage();
        $trace = $storage->store(self::open(TraceReaderTest::MID));

        // The event store it listens to refers back to it
        unset($storage);
        \gc_collect_cycles();

        Assert::false(\is_dir(\dirname($trace->path)));
    }

    /**
     * @return resource
     */
    private static function open(string $path)
    {
        return \fopen($path, 'rb');
    }

    /**
     * @return list<string>
     */
    private static function traceFiles(string $base): array
    {
        return \glob($base . '/*/*.xt*') ?: [];
    }

    private function storage(Traces $config = new Traces(), EventStore $events = new EventStore()): TraceStorage
    {
        return new TraceStorage($config, $events, $this->base);
    }
}
