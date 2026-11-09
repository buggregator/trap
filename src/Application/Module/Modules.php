<?php

declare(strict_types=1);

namespace Buggregator\Trap\Application\Module;

use Internal\Container\Container;

/**
 * The ordered list of the modules of the server.
 *
 * Get it from the container: it is a shared service.
 *
 * @internal
 */
final class Modules
{
    /**
     * The only place that names the modules.
     *
     * The list order is the order of what the modules provide: dispatchers, HTTP middlewares, file observers.
     * The core places its own parts around the modules' ones.
     */
    private const LIST = [
        \Buggregator\Trap\Module\VarDumper\Module::class,
        \Buggregator\Trap\Module\Mailer\Module::class,
        \Buggregator\Trap\Module\Monolog\Module::class,
        \Buggregator\Trap\Module\Ray\Module::class,
        \Buggregator\Trap\Module\Sentry\Module::class,
        \Buggregator\Trap\Module\Profiler\Module::class,
        \Buggregator\Trap\Module\Tracing\Module::class,
    ];

    /** @var list<Module> */
    private readonly array $modules;

    /**
     * @param list<class-string<Module>> $list
     */
    public function __construct(Container $container, array $list = self::LIST)
    {
        $this->modules = \array_map(
            static fn(string $class): Module => $container->get($class),
            $list,
        );
    }

    /**
     * The modules implementing the given provider interface, in the list order.
     *
     * @template T of Module
     *
     * @param class-string<T> $interface
     *
     * @return list<T>
     */
    public function implementing(string $interface): array
    {
        return \array_values(\array_filter(
            $this->modules,
            static fn(Module $module): bool => $module instanceof $interface,
        ));
    }
}
