<?php

declare(strict_types=1);

namespace Buggregator\Trap\Traffic\Message\Multipart;

/**
 * @psalm-type FieldDataArray = array{
 *     headers: array<array-key, non-empty-list<string>>,
 *     name?: string,
 *     value: string
 * }
 *
 * @internal
 */
final class Field extends Part
{
    /**
     * @param array<array-key, non-empty-list<string>> $headers
     */
    public function __construct(array $headers, ?string $name = null, private string $value = '')
    {
        parent::__construct($headers, $name);
    }

    /**
     * @param FieldDataArray $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['headers'], $data['name'] ?? null, $data['value']);
    }

    /**
     * @return FieldDataArray
     * @psalm-suppress ImplementedReturnTypeMismatch
     */
    public function jsonSerialize(): array
    {
        return parent::jsonSerialize() + [
            'value' => $this->value,
        ];
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
}
