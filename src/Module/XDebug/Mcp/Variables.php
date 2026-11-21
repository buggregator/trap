<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\XDebug\Mcp;

use Buggregator\Trap\Module\Mcp\WaitingTool;
use Buggregator\Trap\Module\XDebug\Dbgp\Protocol;
use Buggregator\Trap\Module\XDebug\Session;

/**
 * @internal
 */
final class Variables extends DebugTool implements WaitingTool
{
    #[\Override]
    public function name(): string
    {
        return 'xdebug_variables';
    }

    #[\Override]
    public function title(): string
    {
        return 'Xdebug variables';
    }

    #[\Override]
    public function description(): string
    {
        return 'Variables of a paused session: without `name`, the variables of a stack frame one level deep '
            . '(context 0 locals, 1 superglobals, 2 constants); with `name` (as `$order` or `$order->items[0]`), '
            . 'that value with its children. Arrays and objects show 100 children per `page`; strings are cut at 64 KB.';
    }

    #[\Override]
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'session_id' => self::SESSION_ID,
                'name' => ['type' => 'string', 'description' => 'A variable or a path into it, as PHP writes it.'],
                'frame' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Stack level, 0 by default.'],
                'context' => ['type' => 'integer', 'minimum' => 0, 'description' => '0 locals (default), 1 superglobals, 2 constants.'],
                'page' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Page of the children of `name`.'],
            ],
            'additionalProperties' => false,
        ];
    }

    #[\Override]
    public function call(array $arguments): array
    {
        $frame = self::int($arguments, 'frame', 0);
        $context = self::int($arguments, 'context', 0);
        $page = self::int($arguments, 'page', 0);
        /** @var mixed $name */
        $name = $arguments['name'] ?? '';
        \is_string($name) && !\str_contains($name, "\0") or throw new \InvalidArgumentException('`name` must be a string.');

        return $this->onSession($arguments, static function (Session $session) use ($frame, $context, $page, $name): array {
            if ($name === '') {
                $vars = $session->read('context_get', "-d $frame -c $context")->properties();
                return [['variables' => $vars], \count($vars) . ' variables'];
            }

            $var = $session->read('property_get', "-d $frame -c $context -p $page -n " . Protocol::quote($name))
                ->properties()[0] ?? null;

            return [['variable' => $var], $name];
        });
    }
}
