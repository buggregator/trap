<?php

declare(strict_types=1);

namespace Buggregator\Trap\Client\Profiler\Internal;

/**
 * A minimal HTTP/1.1 client that posts a body and waits for the status line, swallowing every error.
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
     */
    public function post(string $scheme, string $host, int $port, string $target, array $headers, string $body): void
    {
        try {
            $this->doPost($scheme, $host, $port, $target, $headers, $body);
        } catch (\Throwable) {
            // Profiling must never break the application.
        }
    }

    /**
     * @param 'http'|'https' $scheme
     * @param array<non-empty-string, string> $headers
     */
    private function doPost(string $scheme, string $host, int $port, string $target, array $headers, string $body): void
    {
        $address = \str_contains($host, ':') ? "[{$host}]" : $host;
        $context = \stream_context_create(['ssl' => ['peer_name' => $host, 'SNI_enabled' => true]]);
        $stream = @\stream_socket_client(
            ($scheme === 'https' ? 'tls' : 'tcp') . "://{$address}:{$port}",
            $errorCode,
            $errorMessage,
            $this->timeout,
            \STREAM_CLIENT_CONNECT,
            $context,
        );
        if ($stream === false) {
            return;
        }

        try {
            $microseconds = (int) ($this->timeout * 1_000_000.0);
            \stream_set_timeout($stream, \intdiv($microseconds, 1_000_000), $microseconds % 1_000_000);

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
                $written = @\fwrite($stream, \substr($request, $offset));
                if ($written === false || $written === 0) {
                    return;
                }
            }

            // Closing the socket before the server has read the body may reset the connection and lose the
            // request, so wait for the status line.
            @\fgets($stream);
        } finally {
            @\fclose($stream);
        }
    }
}
