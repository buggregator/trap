<?php

declare(strict_types=1);

namespace Buggregator\Trap\Proto\Frame;

use Buggregator\Trap\Proto\Frame;
use Buggregator\Trap\Proto\Frame\Otlp\OtlpTraces;
use Buggregator\Trap\Proto\Frame\Otlp\Trace\Attributes;
use Buggregator\Trap\ProtoType;

/**
 * OpenTelemetry signals received over OTLP. Each signal is its own subclass,
 * told apart by the `type` field of the serialized payload.
 *
 * @internal
 * @psalm-internal Buggregator
 *
 * @psalm-import-type OtlpTracesData from OtlpTraces
 */
abstract class Otlp extends Frame
{
    public function __construct(\DateTimeImmutable $time = new \DateTimeImmutable())
    {
        parent::__construct(ProtoType::Otlp, $time);
    }

    #[\Override]
    final public static function fromString(string $payload, \DateTimeImmutable $time): static
    {
        static::class === self::class or throw new \LogicException(
            \sprintf('Factory method must be called from %s class.', self::class),
        );

        /** @var array{type: string, ...mixed} $data */
        $data = self::fromJsonObjects(\json_decode($payload, false, 512, \JSON_THROW_ON_ERROR));

        /** @psalm-suppress InvalidArgument */
        $result = match ($data['type']) {
            OtlpTraces::OTLP_FRAME_TYPE => OtlpTraces::fromArray($data, $time),
            default => throw new \InvalidArgumentException('Unknown OTLP frame type.'),
        };

        return $result;
    }

    /**
     * Turns JSON objects into arrays, except inside `attributes`, where maps must stay apart from lists.
     */
    private static function fromJsonObjects(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $result = [];
            /** @var mixed $item */
            foreach (\get_object_vars($value) as $key => $item) {
                /** @psalm-suppress MixedAssignment */
                $result[$key] = $key === 'attributes' ? Attributes::fromJson($item) : self::fromJsonObjects($item);
            }

            return $result;
        }

        return \is_array($value) ? \array_map(self::fromJsonObjects(...), $value) : $value;
    }
}
