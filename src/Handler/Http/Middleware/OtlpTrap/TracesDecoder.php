<?php

declare(strict_types=1);

namespace Buggregator\Trap\Handler\Http\Middleware\OtlpTrap;

use Buggregator\Trap\Proto\Frame\Otlp\OtlpTraces;
use Psr\Http\Message\ResponseInterface;

/**
 * Decodes an `ExportTraceServiceRequest` body of one OTLP encoding (JSON, protobuf).
 *
 * @internal
 * @psalm-internal Buggregator\Trap\Handler\Http\Middleware
 */
interface TracesDecoder
{
    /**
     * @param string $payload Uncompressed request body.
     *
     * @throws \InvalidArgumentException|\JsonException When the payload is malformed.
     */
    public function decode(string $payload, \DateTimeImmutable $time): OtlpTraces;

    /**
     * An empty `ExportTraceServiceResponse` in the encoding of the request, as OTLP/HTTP requires.
     */
    public function createSuccessResponse(): ResponseInterface;
}
