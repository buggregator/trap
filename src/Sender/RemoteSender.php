<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender;

use Buggregator\Trap\Info;
use Buggregator\Trap\Proto\Frame;
use Buggregator\Trap\Support\Uuid;

/**
 * OTLP frames are not sent: a receiver of protocol version 1 that does not know the `otlp` type
 * rejects the whole package, the other frames in it included.
 *
 * @internal
 */
final class RemoteSender extends SocketSender
{
    private string $uuid;
    private readonly string $clientVersion;

    public function __construct(
        ?string $uuid = null,
        string $host = '127.0.0.1',
        int $port = 9912,
    ) {
        $this->clientVersion = Info::version();
        $this->uuid = $uuid ?? Uuid::generate();

        parent::__construct($host, $port);
    }

    #[\Override]
    public function send(iterable $frames): void
    {
        $frames = \array_values(\array_filter(
            \is_array($frames) ? $frames : \iterator_to_array($frames, false),
            static fn(Frame $frame): bool => !$frame instanceof Frame\Otlp,
        ));

        $frames === [] or parent::send($frames);
    }

    #[\Override]
    protected function makePackage(string $payload): string
    {
        return "1|$this->clientVersion|$this->uuid|$payload\n";
    }
}
