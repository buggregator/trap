<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Mcp\Stub;

use Buggregator\Trap\Module\Mcp\Tool;

/**
 * Returns its arguments; `text` is returned as is, `list` as a list, `fail` throws its value.
 */
final class EchoTool implements Tool
{
    public function name(): string
    {
        return 'echo';
    }

    public function title(): string
    {
        return 'Echo';
    }

    public function description(): string
    {
        return 'Returns the arguments.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass()];
    }

    public function outputSchema(): ?array
    {
        return ['type' => 'object'];
    }

    public function annotations(): array
    {
        return ['readOnlyHint' => true];
    }

    public function call(array $arguments): string|array
    {
        isset($arguments['fail']) and throw new \RuntimeException((string) $arguments['fail']);

        return match (true) {
            isset($arguments['text']) => (string) $arguments['text'],
            isset($arguments['list']) => \array_values((array) $arguments['list']),
            default => $arguments,
        };
    }
}
