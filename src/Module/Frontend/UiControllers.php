<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Frontend;

use Buggregator\Trap\Module\Mailer\Frontend\ApiController as MailerApiController;
use Buggregator\Trap\Module\Mailer\Frontend\AssetsController as MailerAssetsController;
use Buggregator\Trap\Module\Profiler\Frontend\ApiController as ProfilerApiController;
use Internal\Container\Container;

/**
 * The controllers of the UI endpoints, in route matching order.
 *
 * Controllers are container singletons: the HTTP router and the WebSocket RPC share them.
 *
 * @internal
 */
final class UiControllers
{
    private const API = [
        Service::class,
        ProfilerApiController::class,
        MailerApiController::class,
    ];
    private const ASSETS = [
        MailerAssetsController::class,
    ];

    public function __construct(
        private readonly Container $container,
    ) {}

    /**
     * Routes answered with a JSON message, over HTTP and the WebSocket RPC.
     *
     * @return list<object>
     */
    public function api(): array
    {
        return $this->controllers(self::API);
    }

    /**
     * HTTP-only routes answered with a ready response: HTML, files.
     *
     * @return list<object>
     */
    public function assets(): array
    {
        return $this->controllers(self::ASSETS);
    }

    /**
     * @param list<class-string> $classes
     *
     * @return list<object>
     */
    private function controllers(array $classes): array
    {
        return \array_map(fn(string $class): object => $this->container->get($class), $classes);
    }
}
