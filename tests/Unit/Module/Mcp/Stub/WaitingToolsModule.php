<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Mcp\Stub;

use Buggregator\Trap\Module\Mcp\ProvidesMcpTools;

final class WaitingToolsModule implements ProvidesMcpTools
{
    public function mcpTools(): array
    {
        return [WaitingEchoTool::class, EchoTool::class];
    }
}
