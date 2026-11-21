<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\XDebug\Mcp;

use Buggregator\Trap\Module\Mcp\WaitingTool;
use Buggregator\Trap\Module\XDebug\Session;

/**
 * @internal
 */
final class Stack extends DebugTool implements WaitingTool
{
    #[\Override]
    public function name(): string
    {
        return 'xdebug_stack';
    }

    #[\Override]
    public function title(): string
    {
        return 'Xdebug stack';
    }

    #[\Override]
    public function description(): string
    {
        return 'The call stack of a paused session, the current frame (level 0) first: function, file and line. '
            . 'The first `limit` frames, 20 by default, and the `total`. Pass a `level` as `frame` to `xdebug_variables`.';
    }

    #[\Override]
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'session_id' => self::SESSION_ID,
                'limit' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Frames to return, 20 by default; 0 for all.'],
            ],
            'additionalProperties' => false,
        ];
    }

    #[\Override]
    public function call(array $arguments): array
    {
        $limit = self::int($arguments, 'limit', 20);

        return $this->onSession($arguments, static function (Session $session) use ($limit): array {
            $frames = $session->read('stack_get')->stack();
            $total = \count($frames);
            $shown = $limit === 0 ? $frames : \array_slice($frames, 0, $limit);
            $result = ['frames' => $shown, 'total' => $total];
            $total > \count($shown) and $result['hint'] = \sprintf(
                '%d outer frames left out: pass a larger `limit`, or 0 for all.',
                $total - \count($shown),
            );

            return [$result, "$total frames"];
        });
    }
}
