<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\XDebug\Link;

use Buggregator\Trap\Server\Socket\SocketStream;
use Buggregator\Trap\Server\Traffic\StreamClient;

/**
 * The engine side: the connection Xdebug opened to Trap.
 *
 * @internal
 */
final class StreamLink implements Link
{
    public function __construct(
        private readonly StreamClient $stream,
        private readonly bool $loopback = false,
    ) {}

    public static function of(StreamClient $stream): self
    {
        return new self($stream, self::isLoopbackAddress($stream instanceof SocketStream ? $stream->peerAddress : null));
    }

    public static function isLoopbackAddress(?string $address): bool
    {
        return $address !== null && \preg_match('/^(?:127\.|::1$|::ffff:127\.)/', $address) === 1;
    }

    #[\Override]
    public function send(string $bytes): bool
    {
        return $this->stream->sendData($bytes);
    }

    #[\Override]
    public function receive(): ?string
    {
        if ($this->stream->hasData()) {
            // The stream has data: its iterator yields a chunk without suspending
            return (string) $this->stream->getIterator()->current();
        }

        return $this->stream->isDisconnected() ? null : '';
    }

    #[\Override]
    public function close(): void
    {
        $this->stream->disconnect();
    }

    #[\Override]
    public function isLoopback(): bool
    {
        return $this->loopback;
    }
}
