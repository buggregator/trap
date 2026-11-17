<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Mcp\Stub;

use Buggregator\Trap\Module\Mcp\WaitingTool;

final class WaitingEchoTool implements WaitingTool
{
    public function name(): string
    {
        return 'wait';
    }

    public function title(): string
    {
        return 'Wait';
    }

    public function description(): string
    {
        return 'Returns the arguments at once.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object'];
    }

    public function outputSchema(): ?array
    {
        return null;
    }

    public function annotations(): array
    {
        return [];
    }

    public function call(array $arguments): string|array
    {
        return 'done';
    }
}
