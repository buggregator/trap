<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\XDebug\Mcp;

use Buggregator\Trap\Module\Mcp\WaitingTool;
use Buggregator\Trap\Module\XDebug\DebuggerError;
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
            . 'current one marked with `>` (`source_unavailable` says why when they can\'t be read); at an exception '
            . 'break, the `exception`: `class` and `message`; after `step_out`, `returned_from`: the function that '
            . 'returned, still frame 0 of the stack until the next step.';
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
            [$file, $line] = $session->location();
            $where = self::where($session, $file, $line);
            $from = \max(1, $line - $around);
            try {
                $text = $session->source($file, $from, $line + $around);
                $source = [];
                foreach (\explode("\n", \rtrim($text, "\n")) as $i => $code) {
                    $n = $from + $i;
                    $source[] = \sprintf('%s%d: %s', $n === $line ? '>' : ' ', $n, \rtrim($code, "\r"));
                }
                $source = ['source' => \implode("\n", $source)];
            } catch (DebuggerError $e) {
                // The location answers the call: a source Xdebug and Trap can't read only leaves a note
                \in_array($e->errorCode, ['source_unavailable', 'xdebug_error'], true) or throw $e;
                $source = ['source_unavailable' => $e->getMessage()];
            }

            return [
                ['file' => $file, 'line' => $line, 'where' => $where['where'] ?? ''] + $source
                + self::exception($session) + \array_intersect_key($where, ['returned_from' => true]),
                \basename($file) . ":$line",
            ];
        });
    }
}
