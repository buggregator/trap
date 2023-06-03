<?php

declare(strict_types=1);

namespace Buggregator\Client\Handler;

use Closure;

/**
 * Pipeline is a processor for middlewares chain.
 * The class allows to build a chain of any middleware types and execute them with a custom argument set.
 *
 * @template TMiddleware of object
 * @template TReturn of mixed
 *
 * @psalm-type TLast = Closure(mixed ...): mixed
 *
 * @psalm-immutable
 * @internal
 * @psalm-internal Buggregator\Client
 */
final class Pipeline
{
    /** @var list<TMiddleware> */
    private array $middlewares = [];

    /** @var int<0, max> Current middleware key */
    private int $current = 0;

    /**
     * @param iterable<TMiddleware> $middlewares
     * @param non-empty-string $method
     * @param Closure(): TReturn $last
     * @param class-string<TReturn>|string $returnType
     */
    private function __construct(
        iterable $middlewares,
        private readonly string $method,
        private readonly Closure $last,
        private readonly string $returnType = 'mixed',
    ) {
        // Reset keys
        foreach ($middlewares as $middleware) {
            $this->middlewares[] = $middleware;
        }
    }

    /**
     * Make sure that middlewares implement the same interface.
     * @template TMw of object
     * @template TRet of object
     *
     * @param iterable<TMw> $middlewares
     * @param non-empty-string $method Method name of the all middlewares.
     * @param callable(): TRet $last
     * @param class-string<TRet>|string $returnType Middleware and last handler return type.
     *
     * @return self<TMw, TRet>
     */
    public static function build(
        iterable $middlewares,
        string $method,
        callable $last,
        string $returnType = 'mixed',
    ): self {
        return new self($middlewares, $method, $last(...), $returnType);
    }

    /**
     * @param mixed ...$arguments
     *
     * @return TReturn
     */
    public function __invoke(mixed ...$arguments): mixed
    {
        $middleware = $this->middlewares[$this->current] ?? null;

        if ($middleware === null) {
            return ($this->last)(...$arguments);
        }

        try {
            $next = $this->next();
            $arguments[] = $next;

            return $middleware->{$this->method}(...$arguments);
        } catch (\Throwable $e) {
            // throw new MiddlewareCallException(previous: $e);
            throw $e;
        }
    }

    private function next(): self
    {
        $new = clone $this;
        ++$new->current;

        return $new;
    }
}
