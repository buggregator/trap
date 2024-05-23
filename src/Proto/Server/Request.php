<?php

declare(strict_types=1);

namespace Buggregator\Trap\Proto\Server;

/**
 * @internal
 * @psalm-internal Buggregator
 */
final class Request
{
    /**
     * @param positive-int $protocol Protocol version
     * @param non-empty-string $client Client version
     * @param non-empty-string $uuid Storage UUID
     * @param non-empty-string $payload raw payload
     * @param \Closure(non-empty-string): iterable $payloadParser
     */
    public function __construct(
        public readonly int $protocol,
        public readonly string $client,
        public readonly string $uuid,
        public readonly string $payload,
        private readonly \Closure $payloadParser,
    ) {}

    /**
     * @return iterable
     */
    public function getParsedPayload(): iterable
    {
        return ($this->payloadParser)($this->payload);
    }
}
