<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Console;

use Buggregator\Trap\Server\Application\Module\Module;

/**
 * A module that prints its frames in the console.
 *
 * @internal
 */
interface ProvidesRenderers extends Module
{
    /**
     * A frame is printed by the first renderer that supports it. Each renderer is created anew
     * for every console sender.
     *
     * @return list<class-string<Renderer>>
     */
    public function renderers(): array;
}
