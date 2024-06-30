<?php

declare(strict_types=1);

namespace Buggregator\Trap\Client\Caster;

use Google\Protobuf\Internal\FieldDescriptor;
use Google\Protobuf\Internal\EnumDescriptor;
use Google\Protobuf\Internal\EnumValueDescriptorProto;
use Google\Protobuf\Descriptor as PublicDescriptor;
use Google\Protobuf\Internal\Descriptor as InternalDescriptor;
use Google\Protobuf\Internal\DescriptorPool;
use Google\Protobuf\Internal\GPBType;
use Google\Protobuf\Internal\MapField;
use Google\Protobuf\Internal\Message;
use Google\Protobuf\Internal\RepeatedField;
use Symfony\Component\VarDumper\Caster\Caster;
use Symfony\Component\VarDumper\Cloner\Stub;

/**
 * @internal
 */
final class ProtobufCaster
{
    private const INT_TYPES = [
        GPBType::FIXED32,
        GPBType::FIXED64,
        GPBType::INT64,
        GPBType::UINT64,
        GPBType::INT32,
        GPBType::UINT32,
        GPBType::SFIXED32,
        GPBType::SFIXED64,
        GPBType::SINT32,
        GPBType::SINT64,
    ];

    private const TYPES = [
        GPBType::DOUBLE => 'double',
        GPBType::FLOAT => 'float',
        GPBType::INT64 => 'int64',
        GPBType::UINT64 => 'uint64',
        GPBType::INT32 => 'int32',
        GPBType::FIXED64 => 'fixed64',
        GPBType::FIXED32 => 'fixed32',
        GPBType::BOOL => 'bool',
        GPBType::STRING => 'string',
        GPBType::GROUP => 'group',
        GPBType::MESSAGE => 'message',
        GPBType::BYTES => 'bytes',
        GPBType::UINT32 => 'uint32',
        GPBType::ENUM => 'enum',
        GPBType::SFIXED32 => 'sfixed32',
        GPBType::SFIXED64 => 'sfixed64',
        GPBType::SINT32 => 'sint32',
        GPBType::SINT64 => 'sint64',
    ];

    public static function cast(Message $c, array $a, Stub $stub, bool $isNested): array
    {
        /** @var DescriptorPool $pool */
        $pool = DescriptorPool::getGeneratedPool();
        /** @var PublicDescriptor|InternalDescriptor $descriptor */
        $descriptor = $pool->getDescriptorByClassName($c::class);

        return self::castMessage($c, $descriptor);
    }

    public static function castRepeated(RepeatedField $c, array $a, Stub $stub, bool $isNested): array
    {
        return \iterator_to_array($c);
    }

    public static function castMap(MapField $c, array $a, Stub $stub, bool $isNested): array
    {
        $result = [];
        // todo may be turned on later vua config
        // $result[Caster::PREFIX_VIRTUAL . 'key type'] = self::TYPES[$c->getKeyType()];
        // if ($c->getValueClass() !== null) {
        //     $result[Caster::PREFIX_VIRTUAL . 'class'] = $c->getValueClass();
        // } else {
        //     $result[Caster::PREFIX_VIRTUAL . 'value type'] = self::TYPES[$c->getValueType()];
        // }
        $result[Caster::PREFIX_VIRTUAL . 'values'] = \iterator_to_array($c);
        return $result;
    }

    public static function castEnum(EnumValue $c, array $a, Stub $stub, bool $isNested): array
    {
        $stub->class = $c->class;
        return [
            Caster::PREFIX_VIRTUAL . 'name' => $c->name,
            Caster::PREFIX_VIRTUAL . 'value' => $c->value,
        ];
    }

    private static function castMessage(Message $message, PublicDescriptor|InternalDescriptor $descriptor): array
    {
        return [
            Caster::PREFIX_VIRTUAL . 'message' => $descriptor->getFullName(),
            Caster::PREFIX_VIRTUAL . 'values' => match (true) {
                $descriptor instanceof InternalDescriptor => self::extractViaInternal($message, $descriptor),
                // $descriptor instanceof PublicDescriptor => self::extractViaPublic($message, $descriptor),
                default => self::extractFallback($message),
            },
        ];
    }

    private static function extractFallback(Message $message): mixed
    {
        return \json_decode(
            $message->serializeToJsonString(),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );
    }

    private static function extractViaInternal(Message $message, InternalDescriptor $descriptor): mixed
    {
        /** @var PublicDescriptor $pub */
        $pub = $descriptor->getPublicDescriptor();
        $values = [];

        for ($i = 0; $i < $pub->getFieldCount(); $i++) {
            /** @var FieldDescriptor $fd */
            $fd = $descriptor->getFieldByIndex($i);
            $value = $message->{$fd->getGetter()}();

            // Skip defaults

            if ($value === null) {
                continue;
            }

            if ($value === false && $fd->getType() === GPBType::BOOL) {
                continue;
            }

            if ($value === '' && \in_array($fd->getType(), [GPBType::STRING, GPBType::BYTES], true)) {
                continue;
            }

            if ($value === 0 && \in_array($fd->getType(), self::INT_TYPES, true)) {
                continue;
            }

            if ($fd->isRepeated() && \count($value) === 0) {
                continue;
            }

            if ($fd->isMap() && \count($value) === 0) {
                continue;
            }

            // Wrap ENUM
            if ($fd->getType() === GPBType::ENUM) {
                /** @var EnumDescriptor $ed */
                $ed = $fd->getEnumType();
                /** @var EnumValueDescriptorProto $v */
                $v = $ed->getValueByNumber($value);

                $values[$fd->getName()] = new EnumValue(
                    class: $ed->getClass(),
                    name: $v->getName(),
                    value: $v->getNumber(),
                );

                unset($ed, $v);
                continue;
            }

            $values[$fd->getName()] = $value;
        }

        return $values;
    }
}
