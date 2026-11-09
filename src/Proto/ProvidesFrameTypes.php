<?php

declare(strict_types=1);

namespace Buggregator\Trap\Proto;

use Buggregator\Trap\Application\Module\Module;

/**
 * A module whose frames a receiver decodes from protocol version 1 packages.
 *
 * @internal
 */
interface ProvidesFrameTypes extends Module
{
    /**
     * The class gets the serialized frame in its `fromString()`: an abstract class may decode it
     * into one of its subclasses.
     *
     * @return array<non-empty-string, class-string<Frame>> Frame classes by their wire type, the `TYPE` constant.
     */
    public function frameTypes(): array;
}
