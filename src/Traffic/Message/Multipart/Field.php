<?php

declare(strict_types=1);

namespace Buggregator\Client\Traffic\Message\Multipart;

final class Field extends Part
{
    public function __construct(array $headers, ?string $name = null, private string $value = '')
    {
        parent::__construct($headers, $name);
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function withValue(string $value): static
    {
        $clone = clone $this;
        $clone->value = $value;
        return $clone;
    }

    public function jsonSerialize(): array
    {
        return parent::jsonSerialize() + [
            'value' => $this->value,
        ];
    }
}
