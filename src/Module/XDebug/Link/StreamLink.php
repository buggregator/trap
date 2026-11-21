<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\XDebug\Link;

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
    ) {}

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
}
