<?php

declare(strict_types=1);

namespace Buggregator\Trap\Log;

use Psr\Log\InvalidArgumentException;
use Psr\Log\AbstractLogger;

/**
 * @psalm-type LogRecord = array{
 *     message: string,
 *     context: array<array-key, mixed>,
 *     level: int,
 *     level_name: string,
 *     channel: string,
 *     datetime: string,
 *     extra: array<array-key, mixed>
 * }
 */
final class TrapLogger extends AbstractLogger
{
    public function __construct(
        private string $host = '127.0.0.1',
        private int $port = 9913,
        private string $channel = 'trap',
        private float $connectTimeout = 0.5,
    ) {}

    /**
     * Sends a log record to the Trap server in Monolog-compatible JSON format,
     * falling back to STDERR if the connection fails.
     */
    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        $level = \strtolower((string) $level);

        $record = $this->createRecord($level, $message, $context);

        $payload = \json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            $this->writeFallback($record);

            return;
        }

        if (!$this->sendToTrap($payload)) {
            $this->writeFallback($record);
        }
    }

    /**
     * Routes the payload to the appropriate send method
     * based on whether we are currently inside a Fiber.
     */
    private function sendToTrap(string $payload): bool
    {
        return \Fiber::getCurrent() === null
            ? $this->sendSync($payload)
            : $this->sendAsync($payload);
    }

    /**
     * Sends the payload synchronously using a blocking TCP connection.
     */
    private function sendSync(string $payload): bool
    {
        $address = \sprintf('tcp://%s:%d', $this->host, $this->port);

        $errorCode = 0;
        $errorMessage = '';

        $stream = @\stream_socket_client(
            $address,
            $errorCode,
            $errorMessage,
            $this->connectTimeout,
        );

        if ($stream === false) {
            return false;
        }

        try {
            $payload .= "\n";
            $offset = 0;
            $length = \strlen($payload);

            while ($offset < $length) {
                $written = @\fwrite($stream, \substr($payload, $offset));

                if (!\is_int($written) || $written <= 0) {
                    return false;
                }

                $offset += $written;
            }

            return true;
        } finally {
            \fclose($stream);
        }
    }

    /**
     * Sends the payload using a non-blocking TCP connection.
     */
    private function sendAsync(string $payload): bool
    {
        $address = \sprintf('tcp://%s:%d', $this->host, $this->port);

        $errorCode = 0;
        $errorMessage = '';

        $stream = @\stream_socket_client(
            $address,
            $errorCode,
            $errorMessage,
            $this->connectTimeout,
            STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
        );

        if ($stream === false) {
            return false;
        }

        @\stream_set_blocking($stream, false);

        try {
            $payload .= "\n";
            $offset = 0;
            $length = \strlen($payload);

            while ($offset < $length) {
                $write = [$stream];
                $read = [];
                $except = [];

                if (@\stream_select($read, $write, $except, 0, 0) !== 1) {
                    return false;
                }

                $written = @\fwrite($stream, \substr($payload, $offset));

                if (!\is_int($written) || $written <= 0) {
                    return false;
                }

                $offset += $written;
            }

            return true;
        } finally {
            \fclose($stream);
        }
    }

    /**
     * Replaces {placeholder} tokens in the message with values from context.
     * Follows PSR-3 placeholder interpolation rules.
     *
     * @param array<array-key, mixed> $context
     */
    private function interpolate(string $message, array $context): string
    {
        if ($context === [] || !\str_contains($message, '{')) {
            return $message;
        }

        $replacements = [];

        /** @psalm-suppress MixedAssignment */
        foreach ($context as $key => $value) {
            if (\is_object($value) && !\method_exists($value, '__toString')) {
                $replacements['{' . (string) $key . '}'] = \get_class($value);

                continue;
            }

            if (\is_array($value)) {
                $valueJson = \json_encode($value, JSON_UNESCAPED_UNICODE);

                $replacements['{' . (string) $key . '}'] = $valueJson !== false
                    ? $valueJson
                    : '[unserializable array]';

                continue;
            }

            $replacements['{' . (string) $key . '}'] = (string) $value;
        }

        return \strtr($message, $replacements);
    }

    /**
     * @param LogRecord $record
     */
    private function writeFallback(array $record): void
    {
        $line = $this->formatFallbackLine($record);

        if (\defined('STDERR')) {
            \fwrite(\STDERR, $line . \PHP_EOL);
        } else {
            \error_log($line);
        }
    }

    /**
     * @param LogRecord $record
     */
    private function formatFallbackLine(array $record): string
    {
        $contextJson = \json_encode($record['context'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return \sprintf(
            '[%s] %s.%s: %s %s',
            $record['datetime'],
            $record['channel'],
            $record['level_name'],
            $record['message'],
            $contextJson !== false ? $contextJson : '[unserializable context]',
        );
    }

    /**
     * Builds a Monolog-compatible log record array from the given level, message and context.
     *
     * @param array<array-key, mixed> $context
     * @return LogRecord
     */
    private function createRecord(string $level, string|\Stringable $message, array $context): array
    {
        $interpolated = $this->interpolate((string) $message, $context);

        return [
            'message'    => $interpolated,
            'context'    => $context,
            'level'      => $this->mapLevelToInt($level),
            'level_name' => \strtoupper($level),
            'channel'    => $this->channel,
            'datetime'   => (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339_EXTENDED),
            'extra'      => [],
        ];
    }

    /**
     * Map PSR-3 level name to Monolog-style integer level.
     */
    private function mapLevelToInt(string $level): int
    {
        $levelMap = [
            'debug' => 100,
            'info' => 200,
            'notice' => 250,
            'warning' => 300,
            'error' => 400,
            'critical' => 500,
            'alert' => 550,
            'emergency' => 600,
        ];

        if (!isset($levelMap[$level])) {
            throw new InvalidArgumentException(\sprintf('Invalid log level "%s".', $level));
        }

        return $levelMap[$level];
    }
}
