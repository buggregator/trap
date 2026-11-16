<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Mcp\Stub;

use Buggregator\Trap\Module\Mcp\Tool;

/**
 * Fails to describe itself.
 */
final class BrokenTool implements Tool
{
    public function name(): string
    {
        return 'broken';
    }

    public function title(): string
    {
        return 'Broken';
    }

    public function description(): string
    {
        throw new \LogicException('No description.');
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
        return '';
    }
}
