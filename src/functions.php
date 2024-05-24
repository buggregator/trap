<?php

declare(strict_types=1);

use Buggregator\Trap\Client\TrapHandle;
use Buggregator\Trap\Support\Caster\EnumValue;
use Buggregator\Trap\Support\Caster\ProtobufCaster;
use Buggregator\Trap\Support\Caster\TickerCaster;
use Buggregator\Trap\Support\Tick;
use Google\Protobuf\Internal\MapField;
use Google\Protobuf\Internal\Message;
use Google\Protobuf\Internal\RepeatedField;
use Symfony\Component\VarDumper\Cloner\AbstractCloner;

try {
    /**
     * Configure VarDumper to dump values to the local server.
     * If there are no values - dump stack trace.
     *
     * @param mixed ...$values
     */
    function trap(mixed ...$values): TrapHandle
    {
        /** @psalm-suppress InternalMethod */
        return TrapHandle::fromArray($values);
    }
} catch (\Throwable $e) {
    // do nothing
}

try {
    /**
     * Send values into Buggregator and return the first value.
     *
     * When arguments are passed it equals to {@see trap()} with `->return()` method call.
     * When no arguments passed, it calculates ticks, time between the last `tr()` call and memory usage.
     *
     * @param mixed ...$values
     */
    function tr(mixed ...$values): mixed
    {
        static $counter = -1;
        static $time = 0;

        ++$counter;

        try {
            if ($values === []) {
                $previous = $time;
                $mem = $time = \microtime(true);
                return TrapHandle::fromTicker(
                    $counter,
                    $counter === 0 ? 0 : $mem - $previous,
                    memory_get_usage(),
                )->return();
            }

            $mem = $time = \microtime(true);
            /** @psalm-suppress InternalMethod */
            return TrapHandle::fromArray($values)->return();
        } finally {
            $mem === $time and $time = \microtime(true);
        }
    }
} catch (\Throwable $e) {
    // do nothing
}

/**
 * Register the var-dump caster for protobuf messages
 */
if (class_exists(AbstractCloner::class)) {
    /** @psalm-suppress MixedAssignment */
    AbstractCloner::$defaultCasters[Message::class] ??= [ProtobufCaster::class, 'cast'];
    /** @psalm-suppress MixedAssignment */
    AbstractCloner::$defaultCasters[RepeatedField::class] ??= [ProtobufCaster::class, 'castRepeated'];
    /** @psalm-suppress MixedAssignment */
    AbstractCloner::$defaultCasters[MapField::class] ??= [ProtobufCaster::class, 'castMap'];
    /** @psalm-suppress MixedAssignment */
    AbstractCloner::$defaultCasters[EnumValue::class] ??= [ProtobufCaster::class, 'castEnum'];
    /** @psalm-suppress MixedAssignment */
    AbstractCloner::$defaultCasters[Tick::class] ??= [TickerCaster::class, 'cast'];
}
