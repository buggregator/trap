<?php

declare(strict_types=1);

namespace Buggregator\Trap\Support;

use Google\Protobuf\Internal\Descriptor;
use Google\Protobuf\Internal\DescriptorPool;
use Google\Protobuf\Internal\Message;

/**
 * @internal
 */
final class ProtobufDTO
{
    /**
     * @param non-empty-string $name The fully qualified name of the message
     * @param class-string<Message> $class The generated class name of the message
     */
    private function __construct(
        public string $name,
        public string $class,
    ) {
    }

    public mixed $value;

    /**
     * @throws \Throwable
     */
    public static function createFromMessage(Message $message): self
    {
        // Use cached value
        static $cache = [];
        $cacheId = \spl_object_id($message);
        if (\array_key_exists($cacheId, $cache)) {
            return $cache[$cacheId];
        }

        $descriptor = DescriptorPool::getGeneratedPool()->getDescriptorByClassName($message::class);
        \assert($descriptor instanceof Descriptor);

        $dto = new self($descriptor->getFullName(), $message::class);
        $cache[$cacheId] = $dto;

        try {
            // todo optimize this
            $dto->value = \json_decode($message->serializeToJsonString(), true, 512, \JSON_THROW_ON_ERROR);

            return $dto;
        } finally {
            unset($cache[$cacheId]);
        }
    }
}
