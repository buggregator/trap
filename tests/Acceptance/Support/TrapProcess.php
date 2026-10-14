<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Acceptance\Support;

use Buggregator\Trap\Info;

/**
 * Runs `bin/trap` as a subprocess in its own temporary working directory with a clean environment.
 */
final class TrapProcess
{
    private const START_TIMEOUT = 10.0;

    /** @var resource|null */
    private $process;

    private int $pid = 0;

    /**
     * @param non-empty-string $workDir
     */
    private function __construct(
        public readonly string $workDir,
    ) {}

    /**
     * Starts `bin/trap` with the given arguments and waits until every port in `$waitPorts` accepts connections.
     *
     * @param non-empty-string $name Name of the working directory under `runtime/tests/acceptance`.
     * @param list<string> $args
     * @param array<non-empty-string, string|null> $env Added to the clean environment; null removes a variable.
     * @param list<int> $waitPorts
     */
    public static function start(string $name, array $args, array $env = [], array $waitPorts = []): self
    {
        $self = new self(self::createWorkDir($name));
        $self->process = \proc_open(
            [\PHP_BINARY, self::trapBin(), ...$args],
            [
                0 => ['pipe', 'r'],
                1 => ['file', $self->workDir . '/stdout.log', 'w'],
                2 => ['file', $self->workDir . '/stderr.log', 'w'],
            ],
            $pipes,
            $self->workDir,
            self::environment($self->workDir, $env),
        ) ?: throw new \RuntimeException('Unable to start bin/trap.');
        \fclose($pipes[0]);
        $self->pid = (int) \proc_get_status($self->process)['pid'];

        try {
            $deadline = \microtime(true) + self::START_TIMEOUT;
            foreach ($waitPorts as $port) {
                while (!self::isListening($port)) {
                    $self->isRunning() or throw new \RuntimeException(
                        "bin/trap exited before listening on port $port:\n" . $self->output(),
                    );
                    \microtime(true) < $deadline or throw new \RuntimeException(
                        "bin/trap does not listen on port $port:\n" . $self->output(),
                    );
                    \usleep(50_000);
                }
            }
        } catch (\Throwable $e) {
            // The caller gets no instance to clean up.
            $self->stop();
            $self->removeWorkDir();
            throw $e;
        }

        return $self;
    }

    /**
     * Runs `bin/trap` to completion.
     *
     * @param list<string> $args
     * @param array<non-empty-string, string|null> $env Added to the clean environment; null removes a variable.
     * @return array{int, string} Exit code and the combined stdout and stderr.
     */
    public static function run(string $name, array $args, array $env = [], float $timeout = 10.0): array
    {
        $self = new self(self::createWorkDir($name));
        try {
            $self->process = \proc_open(
                [\PHP_BINARY, self::trapBin(), ...$args],
                [
                    0 => ['pipe', 'r'],
                    1 => ['file', $self->workDir . '/stdout.log', 'w'],
                    2 => ['file', $self->workDir . '/stderr.log', 'w'],
                ],
                $pipes,
                $self->workDir,
                self::environment($self->workDir, $env),
            ) ?: throw new \RuntimeException('Unable to start bin/trap.');
            \fclose($pipes[0]);
            $self->pid = (int) \proc_get_status($self->process)['pid'];

            $deadline = \microtime(true) + $timeout;
            do {
                $status = \proc_get_status($self->process);
                if (!$status['running']) {
                    \proc_close($self->process);
                    $self->process = null;
                    return [$status['exitcode'], $self->output()];
                }
                \usleep(20_000);
            } while (\microtime(true) < $deadline);

            throw new \RuntimeException("bin/trap did not exit in {$timeout}s:\n" . $self->output());
        } finally {
            $self->stop();
            $self->removeWorkDir();
        }
    }

    /**
     * Binds port 0 to let the OS pick a free port. Another process may take it before trap does,
     * which is unlikely enough on a developer machine or a CI runner.
     *
     * @return int<1, 65535>
     */
    public static function freePort(): int
    {
        // Not stream_socket_server(): closing a listening stream takes half a second on Windows.
        $socket = \socket_create(\AF_INET, \SOCK_STREAM, \SOL_TCP)
            ?: throw new \RuntimeException('Unable to create a socket.');
        try {
            \socket_bind($socket, '127.0.0.1', 0) && \socket_getsockname($socket, $address, $port)
                or throw new \RuntimeException('Unable to bind a free port: ' . \socket_strerror(\socket_last_error($socket)));
        } finally {
            \socket_close($socket);
        }

        /** @var int<1, 65535> $port */
        return $port;
    }

    public static function isListening(int $port): bool
    {
        $socket = @\stream_socket_client("tcp://127.0.0.1:$port", $errno, $error, 0.2);
        if ($socket === false) {
            return false;
        }

        \fclose($socket);
        return true;
    }

    /**
     * Polls `$probe` until it returns a non-null value.
     *
     * @template T
     * @param callable(): (T|null) $probe
     * @return T
     */
    public static function waitFor(callable $probe, string $what, float $timeout = 5.0): mixed
    {
        $deadline = \microtime(true) + $timeout;
        do {
            $result = $probe();
            if ($result !== null) {
                return $result;
            }
            \usleep(50_000);
        } while (\microtime(true) < $deadline);

        throw new \RuntimeException("Timed out waiting for $what.");
    }

    /**
     * Writes raw bytes to the port and reads the answer until the server closes the connection.
     */
    public static function exchange(int $port, string $data, float $timeout = 5.0): string
    {
        $socket = \stream_socket_client("tcp://127.0.0.1:$port", $errno, $error, $timeout)
            ?: throw new \RuntimeException("Unable to connect to port $port: $error");
        try {
            \stream_set_timeout($socket, (int) \ceil($timeout));
            self::write($socket, $data);

            $response = '';
            $deadline = \microtime(true) + $timeout;
            while (!\feof($socket) && \microtime(true) < $deadline) {
                $chunk = \fread($socket, 65536);
                if ($chunk === false) {
                    break;
                }
                $response .= $chunk;
            }

            return $response;
        } finally {
            \fclose($socket);
        }
    }

    /**
     * Sends an HTTP/1.1 request and returns the status code, the headers and the body of the response.
     *
     * @param array<non-empty-string, string> $headers
     * @return array{int, string, string}
     */
    public static function http(int $port, string $method, string $uri, string $body = '', array $headers = []): array
    {
        $headers = ['Host' => "127.0.0.1:$port", 'Connection' => 'close'] + $headers;
        $body === '' && $method === 'GET' or $headers['Content-Length'] = (string) \strlen($body);

        $request = "$method $uri HTTP/1.1\r\n";
        foreach ($headers as $name => $value) {
            $request .= "$name: $value\r\n";
        }

        return self::parseResponse(self::exchange($port, $request . "\r\n" . $body));
    }

    /**
     * @return array{int, string, string}
     */
    public static function parseResponse(string $response): array
    {
        [$head, $body] = \explode("\r\n\r\n", $response, 2) + [1 => ''];
        \preg_match('#^HTTP/1\.\d (\d{3})#', $head, $matches) or throw new \RuntimeException(
            'Not an HTTP response: ' . \var_export(\substr($response, 0, 200), true),
        );

        return [(int) $matches[1], $head, $body];
    }

    /**
     * @param resource $socket
     */
    public static function write($socket, string $data): void
    {
        while ($data !== '') {
            $written = \fwrite($socket, $data);
            $written === false || $written === 0 and throw new \RuntimeException('Unable to write to the socket.');
            $data = \substr($data, $written);
        }
    }

    /**
     * Lines written by the `file` sender, in the order of the files.
     *
     * @return list<string>
     */
    public function dumpedLines(): array
    {
        $files = \glob($this->workDir . '/runtime/dump-*.log') ?: [];
        \sort($files);

        $lines = [];
        foreach ($files as $file) {
            foreach (\explode("\n", (string) \file_get_contents($file)) as $line) {
                $line === '' or $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * Waits for a line of the `file` sender that `$match` accepts and returns what it returned.
     *
     * @template T
     * @param callable(string): (T|null) $match
     * @return T
     */
    public function waitForDumpedLine(callable $match, string $what): mixed
    {
        try {
            return self::waitFor(function () use ($match): mixed {
                foreach ($this->dumpedLines() as $line) {
                    $result = $match($line);
                    if ($result !== null) {
                        return $result;
                    }
                }

                return null;
            }, $what);
        } catch (\RuntimeException $e) {
            throw new \RuntimeException(
                $e->getMessage() . "\nDumped lines:\n" . \implode("\n", $this->dumpedLines()) . "\n" . $this->output(),
                previous: $e,
            );
        }
    }

    /**
     * Waits for the web UI on `$uiPort` to list an event of the type and returns it.
     *
     * @return array<array-key, mixed>
     */
    public function waitForUiEvent(int $uiPort, string $type): array
    {
        return self::waitFor(static function () use ($uiPort, $type): ?array {
            [$status, , $body] = self::http($uiPort, 'GET', '/api/events');
            if ($status !== 200) {
                return null;
            }

            /** @var array{data: list<array{type: string, ...}>} $events */
            $events = \json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
            foreach ($events['data'] as $event) {
                if ($event['type'] === $type) {
                    return $event;
                }
            }

            return null;
        }, "a `$type` event in the web UI");
    }

    public function output(): string
    {
        return \implode("\n", \array_map(
            fn(string $stream): string => "[$stream] " . @\file_get_contents("$this->workDir/$stream.log"),
            ['stdout', 'stderr'],
        ));
    }

    public function isRunning(): bool
    {
        return $this->process !== null && \proc_get_status($this->process)['running'];
    }

    public function stop(): void
    {
        if ($this->process === null) {
            return;
        }

        if ($this->isRunning()) {
            if (\PHP_OS_FAMILY === 'Windows') {
                // proc_terminate() would leave children of the process alive.
                \exec("taskkill /F /T /PID {$this->pid} 2>NUL");
            } else {
                \proc_terminate($this->process, 15);
                $deadline = \microtime(true) + 2.0;
                while ($this->isRunning() && \microtime(true) < $deadline) {
                    \usleep(20_000);
                }
                $this->isRunning() and \proc_terminate($this->process, 9);
            }
        }

        \proc_close($this->process);
        $this->process = null;
    }

    /**
     * Removes the working directory. Retries for a moment, since Windows releases the files
     * of a killed process with a delay.
     */
    public function removeWorkDir(): void
    {
        $deadline = \microtime(true) + 2.0;
        while (\is_dir($this->workDir)) {
            try {
                self::removeDir($this->workDir);
            } catch (\Throwable $e) {
                \microtime(true) < $deadline or throw $e;
                \usleep(50_000);
            }
        }
    }

    /**
     * @return non-empty-string
     */
    private static function createWorkDir(string $name): string
    {
        $dir = Info::TRAP_ROOT . '/runtime/tests/acceptance/' . $name;
        \is_dir($dir) and self::removeDir($dir);
        \mkdir($dir, 0777, true);

        return \realpath($dir) ?: $dir;
    }

    private static function trapBin(): string
    {
        return (string) \realpath(Info::TRAP_ROOT . '/bin/trap');
    }

    /**
     * Only what PHP needs to start, plus every `TRAP_*` variable the server reads, set to a harmless value.
     *
     * @param array<non-empty-string, string|null> $override
     * @return array<non-empty-string, string>
     */
    private static function environment(string $workDir, array $override = []): array
    {
        $keep = \PHP_OS_FAMILY === 'Windows'
            // Winsock does not initialize without SystemRoot.
            ? ['PATH', 'SystemRoot', 'SystemDrive', 'windir', 'TEMP', 'TMP', 'ComSpec', 'PATHEXT']
            : ['PATH', 'HOME', 'LANG', 'TMPDIR'];

        $env = [];
        foreach ($keep as $name) {
            $value = \getenv($name);
            $value === false or $env[$name] = $value;
        }

        $env = $override + $env + [
            // Never the default ports, which a real trap or Buggregator may listen on.
            'TRAP_TCP_PORTS' => (string) self::freePort(),
            'TRAP_UI_PORT' => (string) self::freePort(),
            'TRAP_TCP_HOST' => '127.0.0.1',
            'TRAP_TCP_POLLING_INTERVAL' => '1000',
            'TRAP_MAIN_LOOP_INTERVAL' => '100',
            'TRAP_TRACES_MAX_SPANS' => '10000',
            'TRAP_TRACES_TTL' => '600',
            'TRAP_UI_HOST' => '127.0.0.1',
            'TRAP_XHPROF_PATH' => $workDir . '/xhprof',
            'TRAP_XHPROF_SORT' => '3',
            'XDEBUG_MODE' => 'off',
        ];

        return \array_filter($env, static fn(?string $value): bool => $value !== null);
    }

    private static function removeDir(string $dir): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            \assert($item instanceof \SplFileInfo);
            $ok = $item->isDir() && !$item->isLink() ? @\rmdir($item->getPathname()) : @\unlink($item->getPathname());
            $ok or throw new \RuntimeException("Unable to remove {$item->getPathname()}.");
        }
        @\rmdir($dir) or throw new \RuntimeException("Unable to remove $dir.");
    }
}
