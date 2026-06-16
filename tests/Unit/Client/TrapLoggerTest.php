<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Client;

use Buggregator\Trap\Client\TrapHandle;
use Buggregator\Trap\Log\TrapLogger;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LoggerInterface;

final class TrapLoggerTest extends Base
{
    public function testLoggerReturnsSameInstance(): void
    {
        $logger1 = TrapHandle::logger();
        $logger2 = TrapHandle::logger();

        self::assertInstanceOf(LoggerInterface::class, $logger1);
        self::assertInstanceOf(TrapLogger::class, $logger1);
        self::assertSame($logger1, $logger2);
    }

    public function testLoggerUsesDefaultChannel(): void
    {
        $logger = TrapHandle::logger();

        self::assertInstanceOf(TrapLogger::class, $logger);
        self::assertSame('trap', $this->getLoggerChannel($logger));
    }

    public function testLoggerUsesProvidedChannel(): void
    {
        $logger = TrapHandle::logger('app');

        self::assertInstanceOf(TrapLogger::class, $logger);
        self::assertSame('app', $this->getLoggerChannel($logger));
    }

    public function testLoggerCachesInstancePerChannel(): void
    {
        self::assertSame(TrapHandle::logger('app'), TrapHandle::logger('app'));
        self::assertNotSame(TrapHandle::logger('app'), TrapHandle::logger('worker'));
    }

    public function testLoggerSendsToServer(): void
    {
        $server = \stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        self::assertNotFalse($server);

        try {
            $address = \stream_socket_get_name($server, false);
            self::assertIsString($address);

            [, $port] = \explode(':', $address);

            $logger = new TrapLogger(host: '127.0.0.1', port: (int) $port);
            $logger->info('Hello {name}', ['name' => 'Trap']);

            $client = @\stream_socket_accept($server, 1);
            self::assertNotFalse($client);

            $line = \stream_get_line($client, 8192, "\n");
            self::assertNotFalse($line);

            $payload = \json_decode($line, true);

            self::assertSame('Hello Trap', $payload['message']);
            self::assertSame('INFO', $payload['level_name']);
            self::assertSame(['name' => 'Trap'], $payload['context']);
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

        self::assertInstanceOf(TrapLogger::class, $logger);
        self::assertSame(9913, $this->getLoggerPort($logger));

        $this->resetTrapLogger();
        \putenv('TRAP_MONOLOG_PORT=70000');

        $logger = TrapHandle::logger();

        self::assertInstanceOf(TrapLogger::class, $logger);
        self::assertSame(9913, $this->getLoggerPort($logger));
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

        self::assertStringStartsWith('[', $line);
        self::assertStringContainsString('trap.ERROR: Something went wrong: boom', $line);
        self::assertStringContainsString('{"error":"boom"}', $line);
    }

    public function testInvalidLevelThrowsException(): void
    {
        $logger = new TrapLogger(host: '127.0.0.1', port: 1);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid log level "invalid".');

        $logger->log('invalid', 'Bad level');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetTrapLogger();
        \putenv('TRAP_MONOLOG_PORT');
    }

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
