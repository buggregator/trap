<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\XDebug;

use Buggregator\Trap\Module\Frontend\ProvidesUi;
use Buggregator\Trap\Module\Frontend\ProvidesUiPorts;
use Buggregator\Trap\Module\Mcp\ProvidesMcpTools;
use Buggregator\Trap\Module\XDebug\Frontend\ApiController;
use Buggregator\Trap\Module\XDebug\Frontend\Ports;
use Buggregator\Trap\Module\XDebug\Mcp\BreakpointsTool;
use Buggregator\Trap\Module\XDebug\Mcp\Control;
use Buggregator\Trap\Module\XDebug\Mcp\Evaluate;
use Buggregator\Trap\Module\XDebug\Mcp\Location;
use Buggregator\Trap\Module\XDebug\Mcp\Sessions;
use Buggregator\Trap\Module\XDebug\Mcp\Stack;
use Buggregator\Trap\Module\XDebug\Mcp\Variables;
use Buggregator\Trap\Module\XDebug\Mcp\Wait;
use Buggregator\Trap\Module\XDebug\Sender\DebuggerSender;
use Buggregator\Trap\Module\XDebug\Traffic\Dispatcher;
use Buggregator\Trap\Server\Sender\ProvidesSenders;
use Buggregator\Trap\Server\Traffic\ProvidesDispatchers;

/**
 * The Xdebug debugger (DBGp): `--xdebug` listens for Xdebug in debug mode, relays a session to the IDE,
 * or lets the UI and the agents drive it. The dispatcher takes DBGp on any port of Trap.
 *
 * @internal
 */
final class Module implements ProvidesSenders, ProvidesDispatchers, ProvidesUi, ProvidesUiPorts, ProvidesMcpTools
{
    /**
     * The `--xdebug` option of the `run` command chooses the `xdebug` sender.
     */
    #[\Override]
    public function senders(): array
    {
        return ['xdebug' => DebuggerSender::class];
    }

    #[\Override]
    public function dispatchers(): array
    {
        return [Dispatcher::class];
    }

    #[\Override]
    public function uiApiControllers(): array
    {
        return [ApiController::class];
    }

    #[\Override]
    public function uiAssetsControllers(): array
    {
        return [];
    }

    #[\Override]
    public function uiPorts(): string
    {
        return Ports::class;
    }

    #[\Override]
    public function mcpTools(): array
    {
        return [
            Sessions::class,
            Location::class,
            Stack::class,
            Variables::class,
            Evaluate::class,
            Control::class,
            Wait::class,
            BreakpointsTool::class,
        ];
    }
}
