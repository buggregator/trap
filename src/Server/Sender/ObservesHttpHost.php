<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Sender;

/**
 * A sender of an HTTP endpoint that learns the host its port listens on, once at startup.
 * That host may differ from the endpoint's own, see {@see ServesHttp}.
 *
 * @internal
 */
interface ObservesHttpHost extends ServesHttp
{
    /**
     * @param non-empty-string $host
     */
    public function listensOn(string $host): void;
}
