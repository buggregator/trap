<?php

declare(strict_types=1);

namespace Buggregator\Trap\Service;

use Buggregator\Trap\Destroyable;
use Buggregator\Trap\Service\Config\ConfigLoader;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
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

    /** @var array<class-string, array|\Closure(Container): object> */
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
     * @param array $arguments Will be used if the object is created for the first time.
     * @return T
     *
     * @psalm-suppress MoreSpecificImplementedParamType, InvalidReturnType
     */
    public function get(string $id, array $arguments = []): object
    {
        /** @psalm-suppress InvalidReturnStatement */
        return $this->cache[$id] ??= $this->make($id, $arguments);
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
        $binding = $this->factory[$class] ?? null;

        if ($binding instanceof \Closure) {
            $result = $binding($this);
        } else {
            try {
                $result = $this->injector->make($class, \array_merge((array) $binding, $arguments));
            } catch (\Throwable $e) {
                throw new class("Unable to create object of class $class.", previous: $e) extends \RuntimeException implements NotFoundExceptionInterface {};
            }
        }

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
     * Declare a factory or predefined arguments for the specified class.
     *
     * @template T of object
     * @param class-string<T> $id
     * @param array|\Closure(Container): T $binding
     */
    public function bind(string $id, \Closure|array $binding): void
    {
        $this->factory[$id] = $binding;
    }

    public function destroy(): void
    {
        unset($this->cache, $this->factory, $this->injector);
    }
}
