<?php

declare(strict_types=1);

namespace Buggregator\Trap\Client\Log;

use Buggregator\Trap\Client\Internal\Fallback;
use Buggregator\Trap\Client\Internal\Silently;
use Psr\Log\AbstractLogger;
use Psr\Log\InvalidArgumentException;

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
    private const ASYNC_WRITE_RETRY_COUNT = 3;
    private const ASYNC_WRITE_RETRY_DELAY_MICROSECONDS = 5_000;
    private const HANDSHAKE_STEP_WAIT_SECONDS = 0.01;

    /**
     * A TLS 1.3 server sends session tickets after the handshake. The logger never reads, and closing a socket
     * with unread data resets the connection, which may drop the record before the server reads it.
     * TLS 1.2 sends its tickets within the handshake.
     */
    private const CRYPTO_METHOD = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;

    /**
     * @param string $host A host, or a host after `tls://` or `ssl://` to connect over TLS.
     * @param non-empty-string $channel
     * @param bool $verifyPeer Whether a TLS connection checks the certificate and its name.
     *        Off by default: Trap serves a self-signed certificate unless it is given a trusted one.
     */
    public function __construct(
        private readonly string $host = '127.0.0.1',
        private readonly int $port = 9913,
        private readonly string $channel = 'trap',
        private readonly float $connectTimeout = 0.5,
        private readonly bool $verifyPeer = false,
    ) {}

    /**
     * Sends a log record to the Trap server in Monolog-compatible JSON format,
     * falling back to STDERR if the connection fails.
     *
     * @psalm-capabilities read-props|write-this-props|write-props|read-globals|write-globals|io|time
     */
    #[\Override]
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
        return \Fiber::getCurrent() instanceof \Fiber
            ? $this->sendAsync($payload)
            : $this->sendSync($payload);
    }

    /**
     * Sends the payload synchronously using a blocking connection.
     */
    private function sendSync(string $payload): bool
    {
        [$tls, $host] = $this->target();
        $address = \sprintf('%s://%s:%d', $tls ? 'tls' : 'tcp', $host, $this->port);
        $timeout = $this->connectTimeout;
        $context = $this->createContext($host);

        // Socket warnings are handled here: a user error handler that throws on them would skip the fallback.
        $stream = Silently::run(static fn(): mixed => \stream_socket_client(
            $address,
            $errorCode,
            $errorMessage,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context,
        ));

        if ($stream === false) {
            return false;
        }

        $timeoutMicroseconds = (int) ($timeout * 1_000_000.0);
        Silently::run(static fn(): bool => \stream_set_timeout(
            $stream,
            \intdiv($timeoutMicroseconds, 1_000_000),
            $timeoutMicroseconds % 1_000_000,
        ));

        try {
            $payload .= "\n";
            $offset = 0;
            $length = \strlen($payload);

            while ($offset < $length) {
                $chunk = \substr($payload, $offset);
                $written = Silently::run(static fn(): int|false => \fwrite($stream, $chunk));

                if (!\is_int($written) || $written <= 0) {
                    return false;
                }

                $offset += $written;
            }

            return true;
        } finally {
            Silently::run(static fn(): bool => \fclose($stream));
        }
    }

    /**
     * Sends the payload using a non-blocking connection.
     */
    private function sendAsync(string $payload): bool
    {
        [$tls, $host] = $this->target();
        // An async `tls://` connect would skip the handshake, so it runs on the TCP stream once that is up.
        $address = \sprintf('tcp://%s:%d', $host, $this->port);
        $timeout = $this->connectTimeout;
        $context = $this->createContext($host);

        // Never suspend the fiber inside a guarded call: the no-op error handler would leak into the resumed code.
        $stream = Silently::run(static fn(): mixed => \stream_socket_client(
            $address,
            $errorCode,
            $errorMessage,
            $timeout,
            STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
            $context,
        ));

        if ($stream === false) {
            return false;
        }

        Silently::run(static fn(): bool => \stream_set_blocking($stream, false));

        try {
            if ($tls && !$this->handshake($stream)) {
                return false;
            }

            $payload .= "\n";
            $offset = 0;
            $length = \strlen($payload);

            while ($offset < $length) {
                $isWritable = false;

                for ($attempt = 0; $attempt < self::ASYNC_WRITE_RETRY_COUNT; $attempt++) {
                    $result = Silently::run(static function () use ($stream): int|false {
                        $write = [$stream];
                        $read = [];
                        $except = [];

                        return \stream_select($read, $write, $except, 0, self::ASYNC_WRITE_RETRY_DELAY_MICROSECONDS);
                    });

                    if ($result === 1) {
                        $isWritable = true;
                        break;
                    }

                    if ($result === false) {
                        return false;
                    }
                }

                if (!$isWritable) {
                    return false;
                }

                $chunk = \substr($payload, $offset);
                $written = Silently::run(static fn(): int|false => \fwrite($stream, $chunk));

                if (!\is_int($written) || $written <= 0) {
                    return false;
                }

                $offset += $written;
            }

            return true;
        } finally {
            Silently::run(static fn(): bool => \fclose($stream));
        }
    }

    /**
     * Runs the TLS handshake on a non-blocking stream until the connect timeout runs out.
     *
     * @param resource $stream
     */
    private function handshake(mixed $stream): bool
    {
        $deadline = \microtime(true) + $this->connectTimeout;

        // The handshake fails on a socket that is still connecting.
        if (!$this->waitForConnect($stream, $deadline)) {
            return false;
        }

        while (true) {
            $result = Silently::run(static fn(): bool|int => \stream_socket_enable_crypto(
                $stream,
                true,
                self::CRYPTO_METHOD,
            ));

            if ($result !== 0) {
                return $result === true;
            }

            if (\microtime(true) >= $deadline) {
                return false;
            }

            // A step mostly waits for the server's reply; the short wait also retries one that waits to write.
            $left = (int) ((\min($deadline - \microtime(true), self::HANDSHAKE_STEP_WAIT_SECONDS)) * 1_000_000.0);
            $left > 0 and Silently::run(static function () use ($stream, $left): int|false {
                $read = [$stream];
                $write = [];
                $except = [];

                return \stream_select($read, $write, $except, 0, $left);
            });
        }
    }

    /**
     * Waits until an async connect completes, fails, or the deadline passes.
     *
     * @param resource $stream
     * @param float $deadline A {@see \microtime()} timestamp.
     */
    private function waitForConnect(mixed $stream, float $deadline): bool
    {
        while (($left = (int) (($deadline - \microtime(true)) * 1_000_000.0)) > 0) {
            // Windows reports a failed connect in the except set only, never as writable.
            $ready = Silently::run(static function () use ($stream, $left): array|false {
                $read = [];
                $write = [$stream];
                $except = [$stream];

                return \stream_select($read, $write, $except, \intdiv($left, 1_000_000), $left % 1_000_000) === false
                    ? false
                    : [$write, $except];
            });

            if ($ready === false || $ready[1] !== []) {
                return false;
            }

            if ($ready[0] !== []) {
                // Elsewhere a failed connect is writable too; only a connected socket has a peer.
                return Silently::run(static fn(): string|false => \stream_socket_get_name($stream, true)) !== false;
            }
        }

        return false;
    }

    /**
     * @return array{bool, string} Whether to connect over TLS, and the host without the scheme.
     */
    private function target(): array
    {
        return \preg_match('~^(?:tls|ssl)://(.*)$~is', $this->host, $match) === 1
            ? [true, $match[1]]
            : [false, $this->host];
    }

    /**
     * @return resource
     */
    private function createContext(string $host): mixed
    {
        $options = ['peer_name' => $host, 'SNI_enabled' => true, 'crypto_method' => self::CRYPTO_METHOD];
        $this->verifyPeer or $options += [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
        ];

        return \stream_context_create(['ssl' => $options]);
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
                $replacements['{' . (string) $key . '}'] = $value::class;

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
        Fallback::write($this->formatFallbackLine($record));
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
