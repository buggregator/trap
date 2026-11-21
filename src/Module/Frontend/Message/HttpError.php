<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Frontend\Message;

/**
 * A failed request the UI must see as one: the HTTP router answers with its status, as a client error,
 * without logging it.
 *
 * @internal
 */
final class HttpError implements \JsonSerializable
{
    /**
     * @param int<400, 599> $status
     */
    public function __construct(
        public readonly int $status,
        public readonly string $message,
    ) {}

    #[\Override]
    public function jsonSerialize(): array
    {
        return ['message' => $this->message, 'code' => $this->status];
    }
}
