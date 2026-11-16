<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Mcp\Stub;

use Buggregator\Trap\Module\Mcp\Tool;

/**
 * A tool without annotations.
 */
final class PlainTool implements Tool
{
    public function name(): string
    {
        return 'plain';
    }

    public function title(): string
    {
        return 'Plain';
    }

    public function description(): string
    {
        return 'Does nothing.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object'];
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
