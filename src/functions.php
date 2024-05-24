<?php

declare(strict_types=1);

use Buggregator\Trap\Client\TrapHandle;
use Buggregator\Trap\Support\Caster\EnumValue;
use Buggregator\Trap\Support\ProtobufCaster;
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
}
