<?php

declare(strict_types=1);

namespace Buggregator\Trap\Proto;

use Buggregator\Trap\Application\Module\Modules;

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
    /** @var array<non-empty-string, class-string<Frame>> */
    private readonly array $types;

    public function __construct(Modules $modules)
    {
        $types = [Frame\Http::TYPE => Frame\Http::class];
        foreach ($modules->implementing(ProvidesFrameTypes::class) as $module) {
            foreach ($module->frameTypes() as $type => $class) {
                isset($types[$type]) and throw new \LogicException(
                    \sprintf('Frame type `%s` of %s is already taken by %s.', $type, $class, $types[$type]),
                );
                $types[$type] = $class;
            }
        }

        $this->types = $types;
    }

    /**
     * @return class-string<Frame>|null
     */
    public function classOf(string $type): ?string
    {
        return $this->types[$type] ?? null;
    }
}
