<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Client\Internal;

use Buggregator\Trap\Client\Internal\Fallback;
use Buggregator\Trap\Client\Internal\Silently;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Covers(Fallback::class)]
#[Covers(Silently::class)]
#[Test]
final class FallbackTest
{
    private string $errorLog = '';
    private string|false $errorLogIni = false;

    #[BeforeTest]
    public function redirectErrorLog(): void
    {
        $this->errorLog = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'trap-fallback-' . \bin2hex(\random_bytes(4)) . '.log';
        $this->errorLogIni = \ini_set('error_log', $this->errorLog);
    }

    #[AfterTest]
    public function cleanUp(): void
    {
        Fallback::$stream = null;
        $this->errorLogIni === false or \ini_set('error_log', $this->errorLogIni);
        \is_file($this->errorLog) and \unlink($this->errorLog);
    }

    public function testWritesLineToStream(): void
    {
        $stream = Fallback::$stream = \fopen('php://memory', 'w+b');

        Fallback::write('The profile is lost.');

        \rewind($stream);
        Assert::same(\stream_get_contents($stream), 'The profile is lost.' . \PHP_EOL);
        Assert::same($this->errorLog(), '');
    }

    public function testLogsLineWhenStreamIsClosed(): void
    {
        $stream = Fallback::$stream = \fopen('php://memory', 'w+b');
        \fclose($stream);

        Fallback::write('The profile is lost.');

        Assert::string($this->errorLog())->contains('The profile is lost.');
    }

    public function testLogsLineWhenStreamIsNotWritable(): void
    {
        $path = (string) \tempnam(\sys_get_temp_dir(), 'trap-fallback-');
        Fallback::$stream = \fopen($path, 'rb');
        \error_clear_last();

        try {
            Fallback::write('The profile is lost.');
        } finally {
            \fclose(Fallback::$stream);
            \unlink($path);
        }

        Assert::string($this->errorLog())->contains('The profile is lost.');
        Assert::null(\error_get_last());
    }

    private function errorLog(): string
    {
        return \is_file($this->errorLog) ? (string) \file_get_contents($this->errorLog) : '';
    }
}
