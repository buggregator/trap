<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Client;

use Buggregator\Trap\Client\TrapHandle;
use Buggregator\Trap\Log\TrapLogger;
use Buggregator\Trap\Tests\Unit\Client\Stub\CaptureFilter;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
final class TrapLoggerTest extends Base
{
    public function testLoggerReturnsSameInstance(): void
    {
        $logger1 = TrapHandle::logger();
        $logger2 = TrapHandle::logger();

        Assert::instanceOf($logger1, LoggerInterface::class);
        Assert::instanceOf($logger1, TrapLogger::class);
        Assert::same($logger2, $logger1);
    }

    public function testLoggerUsesDefaultChannel(): void
    {
        $logger = TrapHandle::logger();

        Assert::instanceOf($logger, TrapLogger::class);
        Assert::same($this->getLoggerChannel($logger), 'trap');
    }

    public function testLoggerUsesProvidedChannel(): void
    {
        $logger = TrapHandle::logger('app');

        Assert::instanceOf($logger, TrapLogger::class);
        Assert::same($this->getLoggerChannel($logger), 'app');
    }

    public function testLoggerCachesInstancePerChannel(): void
    {
        Assert::same(TrapHandle::logger('app'), TrapHandle::logger('app'));
        Assert::notSame(TrapHandle::logger('worker'), TrapHandle::logger('app'));
    }

    public function testLoggerSendsToServer(): void
    {
        $server = \stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        Assert::notSame($server, false);

        try {
            $address = \stream_socket_get_name($server, false);
            Assert::string($address);

            [, $port] = \explode(':', $address);

            $logger = new TrapLogger(host: '127.0.0.1', port: (int) $port);
            $logger->info('Hello {name}', ['name' => 'Trap']);

            $client = @\stream_socket_accept($server, 1);
            Assert::notSame($client, false);

            $line = \stream_get_line($client, 8192, "\n");
            Assert::notSame($line, false);

            $payload = \json_decode($line, true);

            Assert::same($payload['message'], 'Hello Trap');
            Assert::same($payload['level_name'], 'INFO');
            Assert::same($payload['context'], ['name' => 'Trap']);
        } finally {
            if (\is_resource($client ?? null)) {
                \fclose($client);
            }
            \fclose($server);
        }
    }

    public function testLoggerFallsBackToDefaultPort(): void
    {
        \putenv('TRAP_MONOLOG_PORT=invalid');

        $logger = TrapHandle::logger();

        Assert::instanceOf($logger, TrapLogger::class);
        Assert::same($this->getLoggerPort($logger), 9913);

        $this->resetTrapLogger();
        \putenv('TRAP_MONOLOG_PORT=70000');

        $logger = TrapHandle::logger();

        Assert::instanceOf($logger, TrapLogger::class);
        Assert::same($this->getLoggerPort($logger), 9913);
    }

    public function testFallbackLineIsTextFormatted(): void
    {
        $logger = new TrapLogger(host: '127.0.0.1', port: 1);
        $reflection = new \ReflectionClass($logger);

        $createRecord = $reflection->getMethod('createRecord');
        $formatFallbackLine = $reflection->getMethod('formatFallbackLine');

        $record = $createRecord->invoke(
            $logger,
            'error',
            'Something went wrong: {error}',
            ['error' => 'boom'],
        );

        $line = $formatFallbackLine->invoke($logger, $record);

        Assert::string($line)->startsWith('[')->contains('trap.ERROR: Something went wrong: boom')->contains('{"error":"boom"}');
    }

    public function testInvalidLevelThrowsException(): void
    {
        $logger = new TrapLogger(host: '127.0.0.1', port: 1);

        Expect::exception(InvalidArgumentException::class)->withMessageContaining('Invalid log level "invalid".');

        $logger->log('invalid', 'Bad level');
    }

    #[Covers(TrapLogger::class)]
    public function testInterpolatesObjectsAndArrays(): void
    {
        $server = $this->startServer();

        try {
            $logger = new TrapLogger(host: '127.0.0.1', port: $this->serverPort($server));
            $logger->info('User {user} has {roles}', ['user' => new \stdClass(), 'roles' => ['admin', 'dev']]);

            $payload = $this->receivePayload($server);

            Assert::same($payload['message'], 'User stdClass has ["admin","dev"]');
        } finally {
            \fclose($server);
        }
    }

    #[Covers(TrapLogger::class)]
    public function testFallsBackToStderrWhenServerIsUnreachable(): void
    {
        $logger = new TrapLogger(host: '127.0.0.1', port: $this->freePort());

        $output = $this->captureStderr(static fn() => $logger->error('Lost {what}', ['what' => 'link']));

        Assert::string($output)->contains('trap.ERROR: Lost link {"what":"link"}');
    }

    #[Covers(TrapLogger::class)]
    public function testFallsBackToStderrWhenRecordIsNotJsonEncodable(): void
    {
        $server = $this->startServer();

        try {
            $logger = new TrapLogger(host: '127.0.0.1', port: $this->serverPort($server));

            $output = $this->captureStderr(static fn() => $logger->warning('Got {bad}', ['bad' => ["\xB1"]]));

            Assert::string($output)->contains('trap.WARNING: Got [unserializable array] [unserializable context]');
            Assert::false(@\stream_socket_accept($server, 0.1));
        } finally {
            \fclose($server);
        }
    }

    #[Covers(TrapLogger::class)]
    public function testLoggerSendsToServerInsideFiber(): void
    {
        $server = $this->startServer();

        try {
            $logger = new TrapLogger(host: '127.0.0.1', port: $this->serverPort($server));
            (new \Fiber(static fn() => $logger->notice('Hello {name}', ['name' => 'Fiber'])))->start();

            $payload = $this->receivePayload($server);

            Assert::same($payload['message'], 'Hello Fiber');
            Assert::same($payload['level'], 250);
        } finally {
            \fclose($server);
        }
    }

    #[Covers(TrapLogger::class)]
    public function testFallsBackToStderrInsideFiberWhenServerIsUnreachable(): void
    {
        $logger = new TrapLogger(host: '127.0.0.1', port: $this->freePort());

        $output = $this->captureStderr(
            static fn() => (new \Fiber(static fn() => $logger->critical('Refused')))->start(),
        );

        Assert::string($output)->contains('trap.CRITICAL: Refused []');
    }

    #[Covers(TrapLogger::class)]
    public function testFallsBackToStderrInsideFiberWhenHostCannotBeResolved(): void
    {
        $logger = new TrapLogger(host: '256.256.256.256', port: 1);

        $output = $this->captureStderr(
            static fn() => (new \Fiber(static fn() => $logger->alert('Nowhere')))->start(),
        );

        Assert::string($output)->contains('trap.ALERT: Nowhere []');
    }

    #[BeforeTest]
    protected function setUp(): void
    {
        parent::setUp();
        $this->resetTrapLogger();
        \putenv('TRAP_MONOLOG_PORT');
    }

    #[AfterTest]
    protected function tearDown(): void
    {
        $this->resetTrapLogger();
        \putenv('TRAP_MONOLOG_PORT');
        parent::tearDown();
    }

    private function resetTrapLogger(): void
    {
        $reflection = new \ReflectionClass(TrapHandle::class);
        $loggers = $reflection->getProperty('loggers');
        $loggers->setValue(null, []);
    }

    private function getLoggerPort(TrapLogger $logger): int
    {
        $reflection = new \ReflectionClass($logger);
        $port = $reflection->getProperty('port');

        return $port->getValue($logger);
    }

    private function getLoggerChannel(TrapLogger $logger): string
    {
        $reflection = new \ReflectionClass($logger);
        $channel = $reflection->getProperty('channel');

        return $channel->getValue($logger);
    }

    /**
     * @return resource
     */
    private function startServer(): mixed
    {
        $server = \stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        Assert::notSame($server, false);

        return $server;
    }

    /**
     * @param resource $server
     */
    private function serverPort(mixed $server): int
    {
        $address = \stream_socket_get_name($server, false);
        Assert::string($address);

        return (int) \substr($address, \strrpos($address, ':') + 1);
    }

    /**
     * @param resource $server
     */
    private function receivePayload(mixed $server): array
    {
        $client = @\stream_socket_accept($server, 1);
        Assert::notSame($client, false);

        try {
            $line = \stream_get_line($client, 8192, "\n");
            Assert::string($line);

            return \json_decode($line, true, flags: \JSON_THROW_ON_ERROR);
        } finally {
            \fclose($client);
        }
    }

    /**
     * Returns a port nothing listens on: it was bound by a server that is already closed.
     */
    private function freePort(): int
    {
        $server = $this->startServer();
        $port = $this->serverPort($server);
        \fclose($server);

        return $port;
    }

    private function captureStderr(\Closure $callback): string
    {
        \stream_filter_register('trap-logger-test.capture', CaptureFilter::class);
        CaptureFilter::$captured = '';
        $filter = \stream_filter_append(\STDERR, 'trap-logger-test.capture', \STREAM_FILTER_WRITE);

        try {
            $callback();
        } finally {
            \stream_filter_remove($filter);
        }

        return CaptureFilter::$captured;
    }
}
