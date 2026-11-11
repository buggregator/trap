<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Client\Profiler\Stub;

/**
 * An HTTP server in a child process that answers `200 OK` to every request and reports the raw requests.
 *
 * It runs in another process because the sender waits for the response before it returns.
 */
final class HttpCatcher
{
    private const PING = "PING\r\n\r\n";
    private const SERVER = <<<'PHP'
        $server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage) or exit(1);
        echo substr(strrchr(stream_socket_get_name($server, false), ':'), 1), "\n";
        while ($client = @stream_socket_accept($server, -1)) {
            $data = '';
            while (!str_contains($data, "\r\n\r\n") && !feof($client)) {
                $data .= fread($client, 8192);
            }
            [$head, $body] = explode("\r\n\r\n", $data, 2) + ['', ''];
            $length = preg_match('/^Content-Length: (\d+)/mi', $head, $m) ? (int) $m[1] : 0;
            while (strlen($body) < $length && !feof($client)) {
                $body .= fread($client, 8192);
            }
            fwrite($client, "HTTP/1.1 200 OK\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
            fclose($client);
            echo base64_encode("{$head}\r\n\r\n{$body}"), "\n";
        }
        PHP;

    public readonly int $port;

    /** @var resource */
    private $process;

    /** @var resource */
    private $output;

    public function __construct()
    {
        $process = \proc_open([\PHP_BINARY, '-n', '-r', self::SERVER], [1 => ['pipe', 'w']], $pipes);
        $process === false and throw new \RuntimeException('Unable to start the HTTP catcher.');
        $this->process = $process;
        $this->output = $pipes[1];
        $this->port = (int) \fgets($this->output);
        $this->port > 0 or throw new \RuntimeException('The HTTP catcher did not report its port.');
    }

    /**
     * Returns the requests received since the previous call.
     *
     * @return list<string>
     */
    public function requests(): array
    {
        // The server reports requests in the order they come, so the reply to a ping marks the end.
        $client = \stream_socket_client("tcp://127.0.0.1:{$this->port}", $errorCode, $errorMessage, 2);
        $client === false and throw new \RuntimeException("Unable to ping the HTTP catcher: {$errorMessage}");
        \fwrite($client, self::PING);
        \stream_get_contents($client);
        \fclose($client);

        $requests = [];
        while (($line = \fgets($this->output)) !== false) {
            $request = \base64_decode(\trim($line));
            if ($request === self::PING) {
                return $requests;
            }

            $requests[] = $request;
        }

        throw new \RuntimeException('The HTTP catcher has stopped.');
    }

    public function close(): void
    {
        \proc_terminate($this->process);
        \fclose($this->output);
        \proc_close($this->process);
    }
}
