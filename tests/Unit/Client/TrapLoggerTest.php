<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Client;

use Buggregator\Trap\Client\TrapHandle;
use Buggregator\Trap\Log\TrapLogger;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Testo\Assert;
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
}
