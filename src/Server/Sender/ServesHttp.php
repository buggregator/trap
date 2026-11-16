<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Sender;

/**
 * A sender with its own HTTP endpoint, such as the web UI.
 *
 * Endpoints of several senders on one port are merged: their middlewares and handlers are chained
 * in the sender order. An endpoint without a host takes the host of the others; two different hosts
 * on one port fail, unless the port is one Trap already listens on.
 *
 * When the endpoint port is one Trap already listens on, the endpoint middlewares go first in that
 * port's pipeline and its handlers take the place of the default ones. Otherwise Trap listens on the
 * port for the endpoint alone.
 *
 * @internal
 */
interface ServesHttp
{
    public function httpEndpoint(): HttpEndpoint;
}
