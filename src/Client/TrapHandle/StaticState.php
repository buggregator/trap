<?php

declare(strict_types=1);

namespace Buggregator\Trap\Client\TrapHandle;

/**
 * @internal
 * @psalm-internal Buggregator\Trap\Client
 */
final class StaticState
{
    private function __construct(
        /**
         * Simple stack trace without arguments and objects.
         *
         * @var list<array{
         *      function?: string,
         *      line?: int,
         *      file?: string,
         *      class?: class-string,
         *      type?: string,
         *      args?: list<mixed>
         *  }>
         */
        public array $stackTrace = [],

        /**
         * Stack trace without arguments but with objects.
         *
         * @var list<array{
         *      function?: string,
         *      line?: int,
         *      file?: string,
         *      class?: class-string,
         *      type?: string,
         *      object?: object,
         *      args?: list<mixed>
         *  }>
         */
        public array $stackTraceWithObjects = [],
    ) {
    }

    private static ?StaticState $value = null;

    public static function new(
        array $stackTrace = null,
        array $stackTraceWithObjects = null,
    ): self
    {
        $new = new self(
            $stackTrace ?? StackTrace::stackTrace(provideObjects: false),
            $stackTraceWithObjects ?? StackTrace::stackTrace(provideObjects: true),
        );
        self::setState($new);
        return $new;
    }

    public static function setState(?self $state): void
    {
        self::$value = $state;
    }

    public static function getValue(): ?StaticState
    {
        return self::$value;
    }
}
