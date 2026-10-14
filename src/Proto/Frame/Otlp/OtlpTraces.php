<?php

declare(strict_types=1);

namespace Buggregator\Trap\Proto\Frame\Otlp;

use Buggregator\Trap\Proto\Frame;
use Buggregator\Trap\Proto\Frame\Otlp\Trace\ResourceSpans;
use Buggregator\Trap\Proto\Frame\Otlp\Trace\ScopeSpans;
use Buggregator\Trap\Proto\Frame\Otlp\Trace\Span;
use Buggregator\Trap\Support\Json;

/**
 * One OTLP trace export request.
 *
 * Serialized as a single JSON line: `{"type": "otlp.traces", "resourceSpans": [...]}`.
 * The layout follows `ExportTraceServiceRequest`, except that IDs are lowercase hex,
 * timestamps are integers, and attributes are plain `{"key": value}` maps.
 *
 * @internal
 * @psalm-internal Buggregator
 *
 * @psalm-import-type ResourceSpansData from ResourceSpans
 * @psalm-type OtlpTracesData = array{
 *     type: OtlpTraces::OTLP_FRAME_TYPE,
 *     resourceSpans: list<ResourceSpansData>
 * }
 */
final class OtlpTraces extends Frame\Otlp
{
    public const OTLP_FRAME_TYPE = 'otlp.traces';

    /**
     * @param list<ResourceSpans> $resourceSpans
     */
    public function __construct(
        public readonly array $resourceSpans,
        \DateTimeImmutable $time = new \DateTimeImmutable(),
    ) {
        parent::__construct($time);
    }

    /**
     * @param OtlpTracesData $data
     */
    public static function fromArray(array $data, \DateTimeImmutable $time): self
    {
        return new self(\array_map(ResourceSpans::fromArray(...), $data['resourceSpans']), $time);
    }

    /**
     * @return \Generator<int, array{ResourceSpans, ScopeSpans, Span}, mixed, void>
     */
    public function iterateSpans(): \Generator
    {
        foreach ($this->resourceSpans as $resourceSpans) {
            foreach ($resourceSpans->scopeSpans as $scopeSpans) {
                foreach ($scopeSpans->spans as $span) {
                    yield [$resourceSpans, $scopeSpans, $span];
                }
            }
        }
    }

    /**
     * @return int<0, max>
     */
    public function countSpans(): int
    {
        $count = 0;
        foreach ($this->resourceSpans as $resourceSpans) {
            foreach ($resourceSpans->scopeSpans as $scopeSpans) {
                $count += \count($scopeSpans->spans);
            }
        }

        return $count;
    }

    /**
     * @throws \JsonException
     */
    public function __toString(): string
    {
        // Without the zero fraction a double attribute like 1.0 would read back as an int.
        return Json::encode([
            'type' => self::OTLP_FRAME_TYPE,
            'resourceSpans' => $this->resourceSpans,
        ], \JSON_PRESERVE_ZERO_FRACTION);
    }
}
