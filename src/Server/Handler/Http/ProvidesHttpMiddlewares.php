<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Handler\Http;

use Buggregator\Trap\Server\Application\Module\Module;

/**
 * A module that handles HTTP requests received on the socket servers.
 *
 * @internal
 */
interface ProvidesHttpMiddlewares extends Module
{
    /**
     * Middlewares are container singletons, shared by all the HTTP pipelines.
     *
     * @return list<class-string<Middleware>>
     */
    public function httpMiddlewares(): array;
}
