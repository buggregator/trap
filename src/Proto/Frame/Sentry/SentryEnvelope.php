<?php

declare(strict_types=1);

namespace Buggregator\Trap\Proto\Frame\Sentry;

use Buggregator\Trap\Proto\Frame;
use Buggregator\Trap\ProtoType;
use Buggregator\Trap\Support\Json;

/**
 * @internal
 * @psalm-internal Buggregator
 *
 * @psalm-type SentryEnvelopeMessage = array{
 *     type: SentryEnvelope::SENTRY_FRAME_TYPE,
 *     items: array<array-key, array{array<array-key, mixed>, mixed}>,
 *     headers: array<string, string>
 * }
 */
final class SentryEnvelope extends Frame\Sentry
{
    public const SENTRY_FRAME_TYPE = 'envelope';

    /**
     * @param array<string, string> $headers
     * @param list<Frame\Sentry\EnvelopeItem> $items
     */
    public function __construct(
        public readonly array $headers,
        public readonly array $items,
        \DateTimeImmutable $time = new \DateTimeImmutable(),
    ) {
        parent::__construct(ProtoType::Sentry, $time);
    }

    /**
     * @psalm-assert SentryEnvelopeMessage $data
     *
     * @param SentryEnvelopeMessage $data
     */
    public static function fromArray(array $data, \DateTimeImmutable $time): static
    {
        $items = [];
        foreach ($data['items'] as $item) {
            $items[] = new EnvelopeItem(...$item);
        }

        return new self(
            $data['headers'],
            $items,
            $time,
        );
    }

    /**
     * @throws \JsonException
     */
    public function __toString(): string
    {
        return Json::encode(
            ['headers' => $this->headers, 'items' => $this->items],
        );
    }
}
