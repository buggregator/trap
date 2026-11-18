<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Mcp\Stub;

use Buggregator\Trap\Module\Console\ProvidesRenderers;
use Buggregator\Trap\Module\Console\Renderer\Binary;

/**
 * Gives the console renderer of binary frames, which suspends its fiber while reading the stream.
 */
final class BinaryRendererModule implements ProvidesRenderers
{
    #[\Override]
    public function renderers(): array
    {
        return [Binary::class];
    }
}
