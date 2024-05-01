<?php

declare(strict_types=1);

namespace Buggregator\Trap\Service;

use Buggregator\Trap\Destroyable;
use Buggregator\Trap\Service\Config\ConfigLoader;
use Psr\Container\ContainerInterface;
use Yiisoft\Injector\Injector;

/**
 * Simple Trap container.
 *
 * @internal
 */
final class Container implements ContainerInterface, Destroyable
{
    /** @var array<class-string, object> */
    private array $cache = [];

    /** @var array<class-string, callable(Container): object> */
    private array $factory = [];

    private readonly Injector $injector;

    /**
     * @psalm-suppress PropertyTypeCoercion
     */
    public function __construct()
    {
        $this->injector = (new Injector($this))->withCacheReflections(false);
        $this->cache[Injector::class] = $this->injector;
        $this->cache[self::class] = $this;
        $this->cache[ContainerInterface::class] = $this;
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     *
     * @psalm-suppress MoreSpecificImplementedParamType
     */
    public function get(string $id): object
    {
        /** @psalm-suppress InvalidReturnStatement */
        return $this->cache[$id] ??= $this->make($id);
    }

    /**
     * @param class-string $id
     *
     * @psalm-suppress MoreSpecificImplementedParamType
     */
    public function has(string $id): bool
    {
        return \array_key_exists($id, $this->cache) || \array_key_exists($id, $this->factory);
    }

    /**
     * @template T of object
     * @param T $service
     * @param class-string<T>|null $id
     */
    public function set(object $service, ?string $id = null): void
    {
        \assert($id === null || $service instanceof $id, "Service must be instance of {$id}.");
        $this->cache[$id ?? \get_class($service)] = $service;
    }

    /**
     * Create an object of the specified class without caching.
     *
     * @template T
     * @param class-string<T> $class
     * @return T
     */
    public function make(string $class, array $arguments = []): object
    {
        $result = \array_key_exists($class, $this->factory)
            ? ($this->factory[$class])($this)
            : $this->injector->make($class, $arguments);

        \assert($result instanceof $class, "Created object must be instance of {$class}.");

        // Detect Trap related types

        // Configs
        if (\str_starts_with($class, 'Buggregator\\Trap\\Config\\')) {
            // Hydrate config
            $configLoader = $this->get(ConfigLoader::class);
            $configLoader->hidrate($result);
        }

        return $result;
    }

    /**
     * Declare a factory for the specified class.
     *
     * @template T of object
     * @param class-string<T> $id
     * @param (callable(Container): T) $callback
     */
    public function bind(string $id, callable $callback): void
    {
        $this->factory[$id] = $callback;
    }

    public function destroy(): void
    {
        unset($this->cache, $this->factory, $this->injector);
    }
}
