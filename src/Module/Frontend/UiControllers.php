<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Frontend;

use Buggregator\Trap\Application\Module\Modules;
use Internal\Container\Container;

/**
 * The controllers of the UI endpoints, in route matching order: the Frontend's own first,
 * then the modules' ones in the module list order.
 *
 * Controllers are container singletons: the HTTP router and the WebSocket RPC share them.
 *
 * @internal
 */
final class UiControllers
{
    public function __construct(
        private readonly Modules $modules,
        private readonly Container $container,
    ) {}

    /**
     * Routes answered with a JSON message, over HTTP and the WebSocket RPC.
     *
     * @return list<object>
     */
    public function api(): array
    {
        $classes = [Service::class];
        foreach ($this->modules() as $module) {
            $classes = [...$classes, ...$module->uiApiControllers()];
        }

        return $this->controllers($classes);
    }

    /**
     * HTTP-only routes answered with a ready response: HTML, files.
     *
     * @return list<object>
     */
    public function assets(): array
    {
        $classes = [];
        foreach ($this->modules() as $module) {
            $classes = [...$classes, ...$module->uiAssetsControllers()];
        }

        return $this->controllers($classes);
    }

    /**
     * @return list<ProvidesUi>
     */
    private function modules(): array
    {
        return $this->modules->implementing(ProvidesUi::class);
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
