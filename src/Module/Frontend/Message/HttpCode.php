<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Frontend\Message;

/**
 * A message that sets the status of its answer: the HTTP one, and `code` of the WebSocket RPC reply.
 * Other messages answer 200.
 *
 * @internal
 */
interface HttpCode
{
    public function httpCode(): int;
}
