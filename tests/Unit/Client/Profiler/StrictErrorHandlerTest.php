<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Client\Profiler;

use Buggregator\Trap\Client\Internal\Fallback;
use Buggregator\Trap\Client\Internal\Silently;
use Buggregator\Trap\Client\Log\TrapLogger;
use Buggregator\Trap\Client\Profiler\Internal\HttpClient;
use Buggregator\Trap\Client\Profiler\Internal\Warnings;
use Buggregator\Trap\Client\Profiler\Profile;
use Buggregator\Trap\Client\Profiler\Profiling;
use Buggregator\Trap\Client\Profiler\Session;
use Buggregator\Trap\Client\TrapHandle;
use Buggregator\Trap\Tests\Unit\Client\Profiler\Stub\FakeDriver;
use Buggregator\Trap\Tests\Unit\Client\Profiler\Stub\LogCapture;
use Psr\Log\AbstractLogger;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * The profiler under a user error handler that throws on every warning, ignoring `@` and `error_reporting()`.
 */
#[Test]
#[Covers(Warnings::class)]
#[Covers(TrapLogger::class)]
#[Covers(HttpClient::class)]
#[Covers(Profiling::class)]
#[Covers(Session::class)]
#[Covers(Fallback::class)]
#[Covers(Silently::class)]
final class StrictErrorHandlerTest
{
    private string $dir = '';
    private string $errorLog = '';
    private string|false $errorLogIni = false;

    /** @var resource */
    private mixed $stderr;

    #[BeforeTest]
    public function prepare(): void
    {
        $this->dir = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'trap-strict-' . \bin2hex(\random_bytes(4));
        $this->errorLog = $this->dir . '.log';
        $this->errorLogIni = \ini_set('error_log', $this->errorLog);
        $this->stderr = Fallback::$stream = \fopen('php://memory', 'w+');
        Warnings::reset();
    }

    #[AfterTest]
    public function cleanUp(): void
    {
        Warnings::$logger = null;
        Warnings::reset();
        Fallback::$stream = null;
        $this->errorLogIni === false or \ini_set('error_log', $this->errorLogIni);
        \is_dir($this->dir) and \rmdir($this->dir);
        \is_file($this->errorLog) and \unlink($this->errorLog);
    }

    public function warningReachesStderrWhenTrapIsUnreachable(): void
    {
        Warnings::$logger = new TrapLogger(host: '127.0.0.1', port: self::freePort(), channel: Warnings::CHANNEL);

        self::strict(static fn() => Warnings::once('strict', 'The profile is lost.'));

        Assert::string($this->stderr())->contains('trap.profiler.WARNING: The profile is lost.');
    }

    public function warningReachesStderrWhenTrapIsUnreachableInsideAFiber(): void
    {
        Warnings::$logger = new TrapLogger(host: '127.0.0.1', port: self::freePort(), channel: Warnings::CHANNEL);

        self::strict(static fn() => (new \Fiber(static fn() => Warnings::once('strict', 'The profile is lost.')))->start());

        Assert::string($this->stderr())->contains('trap.profiler.WARNING: The profile is lost.');
    }

    public function warningReachesStderrWhenTheLoggerThrows(): void
    {
        Warnings::$logger = self::throwingLogger();

        self::strict(static fn() => Warnings::once('strict', 'The profile is lost.'));

        Assert::same($this->stderr(), 'trap.profiler.WARNING: The profile is lost.' . \PHP_EOL);
    }

    public function warningGoesToErrorLogWhenStderrIsClosed(): void
    {
        Warnings::$logger = self::throwingLogger();
        \fclose($this->stderr);

        self::strict(static fn() => Warnings::once('strict', 'The profile is lost.'));

        Assert::string((string) \file_get_contents($this->errorLog))->contains('trap.profiler.WARNING: The profile is lost.');
    }

    public function warningGoesToErrorLogWhenStderrIsNotWritable(): void
    {
        Warnings::$logger = self::throwingLogger();
        \touch($this->errorLog);
        Fallback::$stream = \fopen($this->errorLog, 'r');

        try {
            self::strict(static fn() => Warnings::once('strict', 'The profile is lost.'));
        } finally {
            \fclose(Fallback::$stream);
        }

        Assert::string((string) \file_get_contents($this->errorLog))->contains('trap.profiler.WARNING: The profile is lost.');
    }

    public function unreachableTrapIsLoggedAsADeliveryError(): void
    {
        $log = Warnings::$logger = new LogCapture();

        $result = self::strict(
            static fn(): int => TrapHandle::profile(new FakeDriver())
                ->toTrap('127.0.0.1', self::freePort())
                ->run(static fn(): int => 7),
        );

        Assert::same($result, 7);
        Assert::string($log->messages())->contains('Unable to connect to 127.0.0.1:');
        Assert::same($log->records[0]['context']['exception'], \RuntimeException::class);
    }

    public function unwritableFileIsLoggedAsADeliveryError(): void
    {
        $log = Warnings::$logger = new LogCapture();
        \mkdir($this->dir, 0777, true);

        $result = self::strict(
            fn(): int => TrapHandle::profile(new FakeDriver())->toFile($this->dir)->run(static fn(): int => 8),
        );

        Assert::same($result, 8);
        Assert::string($log->messages())->contains("Unable to write the profile to `{$this->dir}`");
    }

    public function profileKeepsTheHostname(): void
    {
        $profile = self::strict(
            static fn(): ?Profile => TrapHandle::profile(new FakeDriver())->to(static fn() => null)->start()->stop(),
        );

        Assert::instanceOf($profile, Profile::class);
        Assert::same($profile->metadata()->hostname, \gethostname());
    }

    /**
     * Runs the callback under an error handler that throws on any error, then checks it is still the active one.
     *
     * @template T
     * @param \Closure(): T $callback
     * @return T
     */
    private static function strict(\Closure $callback): mixed
    {
        $handler = static fn(int $severity, string $message, string $file, int $line): never
            => throw new \ErrorException($message, 0, $severity, $file, $line);

        \set_error_handler($handler);
        try {
            $result = $callback();
            $active = \set_error_handler(null);
            \restore_error_handler();
            Assert::same($active, $handler);
            return $result;
        } finally {
            \restore_error_handler();
        }
    }

    private static function throwingLogger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            #[\Override]
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                throw new \RuntimeException('logger failed');
            }
        };
    }

    /**
     * Returns a port nothing listens on: it was bound by a server that is already closed.
     *
     * @return int<1, 65535>
     */
    private static function freePort(): int
    {
        $server = \stream_socket_server('tcp://127.0.0.1:0');
        Assert::notSame($server, false);
        $address = (string) \stream_socket_get_name($server, false);
        \fclose($server);

        return (int) \substr($address, \strrpos($address, ':') + 1);
    }

    private function stderr(): string
    {
        \rewind($this->stderr);

        return (string) \stream_get_contents($this->stderr);
    }
}
