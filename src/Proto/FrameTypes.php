<?php

declare(strict_types=1);

namespace Buggregator\Trap\Proto;

use Buggregator\Trap\Module;

/**
 * Frame types that a receiver decodes, by their wire type.
 *
 * The binary frame is not here: protocol version 1 doesn't carry it.
 *
 * @internal
 * @psalm-internal Buggregator
 */
final class FrameTypes
{
    /**
     * The only place in the protocol that names module frames: a registration point in `structarmed.php`.
     */
    private const TYPES = [
        Module\Mailer\Frame::TYPE => Module\Mailer\Frame::class,
        Module\Monolog\Frame::TYPE => Module\Monolog\Frame::class,
        Module\VarDumper\Frame::TYPE => Module\VarDumper\Frame::class,
        Frame\Http::TYPE => Frame\Http::class,
        Module\Sentry\Frame::TYPE => Module\Sentry\Frame::class,
        Module\Profiler\Frame::TYPE => Module\Profiler\Frame::class,
        Module\Tracing\Frame::TYPE => Module\Tracing\Frame::class,
    ];

    /**
     * @param array<non-empty-string, class-string<Frame>> $types Frame classes by their `TYPE`.
     */
    public function __construct(
        private readonly array $types = self::TYPES,
    ) {}

    /**
     * @return class-string<Frame>|null
     */
    public function classOf(string $type): ?string
    {
        return $this->types[$type] ?? null;
    }
}
