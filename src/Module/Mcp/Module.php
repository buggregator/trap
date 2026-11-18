<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Mcp;

use Buggregator\Trap\Module\Mcp\Sender\McpSender;
use Buggregator\Trap\Module\Mcp\Tool\ClearEvents;
use Buggregator\Trap\Module\Mcp\Tool\DeleteEvent;
use Buggregator\Trap\Module\Mcp\Tool\GetEvent;
use Buggregator\Trap\Module\Mcp\Tool\ListEvents;
use Buggregator\Trap\Module\Mcp\Tool\WaitForEvents;
use Buggregator\Trap\Server\Sender\ProvidesSenders;

/**
 * @internal
 */
final class Module implements ProvidesSenders, ProvidesMcpTools
{
    /**
     * The `--mcp` option of the `run` command chooses the `mcp` sender.
     */
    #[\Override]
    public function senders(): array
    {
        return ['mcp' => McpSender::class];
    }

    #[\Override]
    public function mcpTools(): array
    {
        return [ListEvents::class, WaitForEvents::class, GetEvent::class, DeleteEvent::class, ClearEvents::class];
    }
}
