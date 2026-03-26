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

    public function testLoggerCanWriteInfo(): void
    {
        $logger = new TrapLogger(host: '127.0.0.1', port: 1);

        $logger->info('Hello {name}', ['name' => 'Trap']);

        self::assertTrue(true);
    }

    public function testLoggerCanWriteError(): void
    {
        $logger = new TrapLogger(host: '127.0.0.1', port: 1);

        $logger->error('Something went wrong: {error}', ['error' => 'boom']);

        self::assertTrue(true);
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
}
