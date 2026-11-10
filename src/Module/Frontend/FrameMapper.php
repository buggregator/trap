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

    /** @var array<class-string<Frame>, class-string<EventMapper>|null> Mappers resolved by the exact frame class */
    private array $resolved = [];

    /**
     * @param Container $container Creates the mappers, so they share its services, such as the {@see EventStorage}.
     */
    public function __construct(
        Modules $modules,
        private readonly Container $container,
    ) {
        /** @var array<class-string<Frame>, class-string<EventMapper>|null> $mappers */
        $mappers = [Frame\Http::class => Mapper\HttpRequest::class];
        /** @var array<class-string<Frame>, class-string> $owners Who gave the mapper of each frame class */
        $owners = [Frame\Http::class => self::class];
        foreach ($modules->implementing(ProvidesUi::class) as $module) {
            foreach ($module->uiEventMappers() as $frameClass => $mapper) {
                isset($owners[$frameClass]) and throw new \LogicException(\sprintf(
                    'The UI event mapper of %s from %s is already given by %s.',
                    $frameClass,
                    $module::class,
                    $owners[$frameClass],
                ));
                $owners[$frameClass] = $module::class;
                $mappers[$frameClass] = $mapper;
            }
        }

        $this->mappers = $mappers;
    }

    /**
     * @return Event|null Null for frames the Buggregator UI has no event type for.
     */
    public function map(Frame $frame): ?Event
    {
        $class = $this->mapperOf($frame);

        return $class === null ? null : $this->container->get($class)->map($frame);
    }

    /**
     * The mapper of the frame class, or else of the first mapped class the frame extends.
     *
     * @return class-string<EventMapper>|null
     */
    private function mapperOf(Frame $frame): ?string
    {
        if (\array_key_exists($frame::class, $this->resolved)) {
            return $this->resolved[$frame::class];
        }

        if (\array_key_exists($frame::class, $this->mappers)) {
            return $this->resolved[$frame::class] = $this->mappers[$frame::class];
        }

        foreach ($this->mappers as $frameClass => $mapper) {
            if (\is_a($frame, $frameClass)) {
                return $this->resolved[$frame::class] = $mapper;
            }
        }

        throw new \InvalidArgumentException('Unknown frame type ' . $frame::class);
    }
}
