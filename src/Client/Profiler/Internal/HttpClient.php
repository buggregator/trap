<?php

declare(strict_types=1);

namespace Buggregator\Trap\Client\Profiler\Internal;

use Buggregator\Trap\Client\Internal\Silently;

/**
 * A minimal HTTP/1.1 client that posts a body and waits for the status line.
 *
 * @internal
 */
final class HttpClient
{
    /**
     * @param float $timeout Seconds to wait for the connection and for each read or write.
     */
    public function __construct(
        private readonly float $timeout = 1.0,
    ) {}

    /**
     * @param 'http'|'https' $scheme
     * @param int<1, 65535> $port
     * @param non-empty-string $target The request target: the path with the query.
     * @param array<non-empty-string, string> $headers Without CR and LF.
     *
     * @throws \RuntimeException When the server is unreachable, the request can't be written,
     *         or the server answers with an error status. No status line within the timeout is no error:
     *         a busy server may still take the request.
     */
    public function post(string $scheme, string $host, int $port, string $target, array $headers, string $body): void
    {
        $address = \str_contains($host, ':') ? "[{$host}]" : $host;
        $context = \stream_context_create(['ssl' => ['peer_name' => $host, 'SNI_enabled' => true]]);
        $timeout = $this->timeout;
        $errorCode = 0;
        $errorMessage = '';
        // Socket warnings are turned into the exceptions below; a user error handler that throws on them
        // would replace those.
        $stream = Silently::run(static function () use ($scheme, $address, $port, $timeout, $context, &$errorCode, &$errorMessage): mixed {
            return \stream_socket_client(
                ($scheme === 'https' ? 'tls' : 'tcp') . "://{$address}:{$port}",
                $errorCode,
                $errorMessage,
                $timeout,
                \STREAM_CLIENT_CONNECT,
                $context,
            );
        });
        $stream === false and throw new \RuntimeException(\sprintf(
            'Unable to connect to %s:%d: %s',
            $address,
            $port,
            $errorMessage === '' ? "error {$errorCode}" : $errorMessage,
        ));

        try {
            $microseconds = (int) ($this->timeout * 1_000_000.0);
            Silently::run(static fn(): bool => \stream_set_timeout($stream, \intdiv($microseconds, 1_000_000), $microseconds % 1_000_000));

            $default = $port === ($scheme === 'https' ? 443 : 80);
            $request = "POST {$target} HTTP/1.1\r\n"
                . 'Host: ' . $address . ($default ? '' : ":{$port}") . "\r\n";
            foreach ($headers as $name => $value) {
                $request .= "{$name}: {$value}\r\n";
            }
            $request .= 'Content-Length: ' . \strlen($body) . "\r\n"
                . "Connection: close\r\n"
                . "\r\n"
                . $body;

            for ($offset = 0, $length = \strlen($request); $offset < $length; $offset += $written) {
                $chunk = \substr($request, $offset);
                $written = Silently::run(static fn(): int|false => \fwrite($stream, $chunk));
                $written === false || $written === 0
                and throw new \RuntimeException(\sprintf('Unable to send the request to %s:%d.', $address, $port));
            }

            // Closing the socket before the server has read the body may reset the connection and lose the
            // request, so wait for the status line.
            $status = Silently::run(static fn(): string|false => \fgets($stream));
            \is_string($status) && \preg_match('~^HTTP/\d(?:\.\d)? ([45]\d\d)~', $status, $match) === 1
            and throw new \RuntimeException(\sprintf('%s:%d answered %s.', $address, $port, \rtrim(\substr($status, 9))));
        } finally {
            Silently::run(static fn(): bool => \fclose($stream));
        }
    }
}
