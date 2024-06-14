<?php

declare(strict_types=1);

use Buggregator\Trap\Client\Caster\EnumValue;
use Buggregator\Trap\Client\Caster\ProtobufCaster;
use Buggregator\Trap\Client\Caster\Trace;
use Buggregator\Trap\Client\Caster\TraceCaster;
use Buggregator\Trap\Client\Caster\TraceFile;
use Buggregator\Trap\Client\TrapHandle;
use Buggregator\Trap\Client\TrapHandle\StackTrace;
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

    StackTrace::$facades['trap'] = true;
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
        /** @var int<-1, max> $counter */
        static $counter = -1;
        /** @var float $time */
        static $time = 0.0;

        ++$counter;

        $previous = $time;
        $mem = $time = \microtime(true);
        try {
            if ($values === []) {
                /** @var int<0, max> $memory */
                $memory = \memory_get_usage();
                /** @psalm-suppress InternalMethod */
                return TrapHandle::fromTicker(
                    $counter,
                    $counter === 0 ? 0 : $mem - $previous,
                    $memory,
                )->return();
            }

            /** @psalm-suppress InternalMethod */
            return TrapHandle::fromArray($values)->return();
        } finally {
            $mem === $time and $time = \microtime(true);
        }
    }

    StackTrace::$facades['tr'] = true;

    /**
     * Send values into Buggregator and die.
     *
     * When no arguments passed, it works like {@see tr()}.
     *
     * @param mixed ...$values
     *
     * @codeCoverageIgnore
     */
    function td(mixed ...$values): never
    {
        tr(...$values);
        die;
    }

    StackTrace::$facades['td'] = true;
} catch (\Throwable $e) {
    // do nothing
}

/**
 * Register the var-dump caster for protobuf messages
 */
if (\class_exists(AbstractCloner::class)) {
    /** @psalm-suppress UnsupportedPropertyReferenceUsage */
    $casters = &AbstractCloner::$defaultCasters;
    /**
     * Define var-dump related casters for protobuf messages and traces.
     *
     * @var array<non-empty-string, callable> $casters
     */
    $casters[Message::class] ??= [ProtobufCaster::class, 'cast'];
    $casters[RepeatedField::class] ??= [ProtobufCaster::class, 'castRepeated'];
    $casters[MapField::class] ??= [ProtobufCaster::class, 'castMap'];
    $casters[EnumValue::class] ??= [ProtobufCaster::class, 'castEnum'];
    $casters[Trace::class] = [TraceCaster::class, 'cast'];
    $casters[TraceFile::class] = [TraceCaster::class, 'castLine'];

    unset($casters);
}

\stream_filter_register(
    \Buggregator\Trap\Support\Stream\Base64DecodeFilter::FILTER_NAME,
    \Buggregator\Trap\Support\Stream\Base64DecodeFilter::class,
);
