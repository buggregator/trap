<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\XDebug\Link;

use Buggregator\Trap\Support\Timer;

/**
 * The IDE side: a connection Trap opens to the IDE, non-blocking, so the other fibers run meanwhile.
 *
 * @internal
 */
final class SocketLink implements Link
{
    /** @var resource|null */
    private $socket;

    /**
     * @param resource $socket
     */
    private function __construct($socket)
    {
        $this->socket = $socket;
    }

    /**
     * Connects in the current fiber, letting the others run while the connection is made.
     *
     * @param non-empty-string $address `host:port`
     * @param float|null $timeout Seconds; null for 0.5 on the loopback and 2 elsewhere.
     *
     * @return self|null Null when nothing accepts the connection in time.
     */
    public static function connect(string $address, ?float $timeout = null): ?self
    {
        // A refused connection on Windows takes the whole timeout: a local IDE answers far sooner
        $timeout ??= \preg_match('/^(127\.|localhost:|\[::1\]:)/i', $address) === 1 ? 0.5 : 2.0;
        $socket = @\stream_socket_client(
            'tcp://' . $address,
            $code,
            $message,
            $timeout,
            \STREAM_CLIENT_CONNECT | \STREAM_CLIENT_ASYNC_CONNECT,
        );
        if ($socket === false) {
            return null;
        }

        $timer = new Timer($timeout);
        while (true) {
            $read = [];
            $write = $except = [$socket];
            if (@\stream_select($read, $write, $except, 0) > 0) {
                break;
            }
            if ($timer->isReady() || \Fiber::getCurrent() === null) {
                \fclose($socket);
                return null;
            }
            \Fiber::suspend();
        }

        // A refused connection is writable too: no peer name tells it apart
        if ($except !== [] || \stream_socket_get_name($socket, true) === false) {
            \fclose($socket);
            return null;
        }
        \stream_set_blocking($socket, false);

        return new self($socket);
    }

    #[\Override]
    public function send(string $bytes): bool
    {
        while ($bytes !== '') {
            $written = $this->socket === null ? false : @\fwrite($this->socket, $bytes);
            if ($written === false) {
                return false;
            }
            $bytes = \substr($bytes, $written);
            $written === 0 && \Fiber::getCurrent() !== null and \Fiber::suspend();
        }

        return true;
    }

    #[\Override]
    public function receive(): ?string
    {
        $socket = $this->socket;
        if ($socket === null) {
            return null;
        }
        $data = @\fread($socket, 65536);
        if ($data === false || ($data === '' && \feof($socket))) {
            return null;
        }

        return $data;
    }

    #[\Override]
    public function close(): void
    {
        $socket = $this->socket;
        $this->socket = null;
        $socket === null or \fclose($socket);
    }
}
