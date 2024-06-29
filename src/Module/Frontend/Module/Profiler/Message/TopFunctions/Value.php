<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Frontend\Module\Profiler\Message\TopFunctions;

/**
 * @internal
 */
final class Value implements \JsonSerializable
{
    public function __construct(
        public readonly string $key,
        public readonly string $format,
        public readonly ?string $type = null,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'key' => $this->key,
            'format' => $this->format,
            'type' => $this->type,
        ];
    }
}
