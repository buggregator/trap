<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\XDebug\Mcp;

use Buggregator\Trap\Module\Mcp\WaitingTool;
use Buggregator\Trap\Module\XDebug\Session;

/**
 * @internal
 */
final class Location extends DebugTool implements WaitingTool
{
    #[\Override]
    public function name(): string
    {
        return 'xdebug_location';
    }

    #[\Override]
    public function title(): string
    {
        return 'Xdebug location';
    }

    #[\Override]
    public function description(): string
    {
        return 'Where a paused session stopped: file and line, the function, and the source lines around it, the '
            . 'current one marked with `>`; at an exception break, the `exception`: `class` and `message`.';
    }

    #[\Override]
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'session_id' => self::SESSION_ID,
                'context_lines' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 50, 'description' => 'Lines before and after, 5 by default.'],
            ],
            'additionalProperties' => false,
        ];
    }

    #[\Override]
    public function call(array $arguments): array
    {
        $around = \min(50, self::int($arguments, 'context_lines', 5));

        return $this->onSession($arguments, static function (Session $session) use ($around): array {
            $top = $session->read('stack_get')->stack()[0] ?? null;
            [$file, $line] = $top === null ? $session->location() : [$top['file'], $top['line']];
            $from = \max(1, $line - $around);
            $text = $session->source($file, $from, $line + $around);
            $source = [];
            foreach (\explode("\n", \rtrim($text, "\n")) as $i => $code) {
                $n = $from + $i;
                $source[] = \sprintf('%s%d: %s', $n === $line ? '>' : ' ', $n, \rtrim($code, "\r"));
            }

            return [
                ['file' => $file, 'line' => $line, 'where' => $top['where'] ?? '', 'source' => \implode("\n", $source)]
                + self::exception($session),
                \basename($file) . ":$line",
            ];
        });
    }
}
