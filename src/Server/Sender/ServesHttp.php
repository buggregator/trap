<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Sender;

/**
 * A sender with its own HTTP endpoint, such as the web UI.
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
