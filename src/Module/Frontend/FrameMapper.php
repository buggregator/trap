<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Frontend;

use Buggregator\Trap\Application\Module\Modules;
use Buggregator\Trap\Proto\Frame;
use Internal\Container\Container;

/**
 * @internal
 */
final class FrameMapper
{
    /** @var array<class-string<Frame>, class-string<EventMapper>|null> */
    private readonly array $mappers;

    /**
     * @param Container $container Creates the mappers: the Ray mapper adds payloads to the stored
     *        {@see EventStorage}, the one of the container.
     */
    public function __construct(
        private readonly Container $container,
    ) {
        /** @var array<class-string<Frame>, class-string<EventMapper>|null> $mappers */
        $mappers = [Frame\Http::class => Mapper\HttpRequest::class];
        foreach ($container->get(Modules::class)->implementing(ProvidesUi::class) as $module) {
            $mappers = [...$mappers, ...$module->uiEventMappers()];
        }

        $this->mappers = $mappers;
    }

    /**
     * @return Event|null Null for frames the Buggregator UI has no event type for.
     */
    public function map(Frame $frame): ?Event
    {
        \array_key_exists($frame::class, $this->mappers) or throw new \InvalidArgumentException(
            'Unknown frame type ' . $frame::class,
        );
        $class = $this->mappers[$frame::class];

        return $class === null ? null : $this->container->get($class)->map($frame);
    }
}
