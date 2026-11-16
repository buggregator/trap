<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Mcp\Stub;

use Buggregator\Trap\Module\Mcp\ProvidesMcpTools;

final class BrokenToolsModule implements ProvidesMcpTools
{
    public function mcpTools(): array
    {
        return [BrokenTool::class];
    }
}
