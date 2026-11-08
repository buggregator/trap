<?php

declare(strict_types=1);

namespace Buggregator\Trap\Proto\Frame\Otlp\Trace;

/**
 * @internal
 * @psalm-internal Buggregator
 *
 * @psalm-type SpanStatusData = array{
 *     code: int<0, 2>,
 *     message: string
 * }
 */
final class SpanStatus implements \JsonSerializable
{
    public function __construct(
        public readonly StatusCode $code = StatusCode::Unset,
        public readonly string $message = '',
    ) {}

    /**
     * @param SpanStatusData $data
     */
    public static function fromArray(array $data): self
    {
        return new self(StatusCode::from($data['code']), $data['message']);
    }

    /**
     * @return SpanStatusData
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return [
            'code' => $this->code->value,
            'message' => $this->message,
        ];
    }
}
