<?php

declare(strict_types=1);

namespace Buggregator\Trap\Client\TrapHandle;

/**
 * @internal
 *
 * @psalm-internal Buggregator\Trap\Client
 *
 * @psalm-import-type SimpleStackTrace from StackTrace
 * @psalm-import-type StackTraceWithObjects from StackTrace
 */
final class StaticState
{
    /**
     * @var array<array-key, mixed>
     */
    public array $dataContext = [];

    private static ?StaticState $value = null;

    /**
     * @param SimpleStackTrace $stackTrace simple stack trace without arguments and objects
     * @param StackTraceWithObjects $stackTraceWithObjects stack trace without arguments but with objects
     */
    private function __construct(
        public array $stackTrace = [],
        public array $stackTraceWithObjects = [],
    ) {}

    /**
     * @param null|SimpleStackTrace $stackTrace
     * @param null|StackTraceWithObjects $stackTraceWithObjects
     */
    public static function new(
        ?array $stackTrace = null,
        ?array $stackTraceWithObjects = null,
    ): self {
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
