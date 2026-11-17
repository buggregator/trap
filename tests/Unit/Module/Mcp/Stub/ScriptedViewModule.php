<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Mcp\Stub;

use Buggregator\Trap\Module\Mcp\ProvidesEventViews;

final class ScriptedViewModule implements ProvidesEventViews
{
    #[\Override]
    public function eventViews(): array
    {
        return [ScriptedView::class];
    }
}
