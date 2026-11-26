<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\XDebug\Config;

use Buggregator\Trap\Server\Application\Config\Internal\Attribute\Env;
use Buggregator\Trap\Server\Application\Config\Internal\Attribute\InflectableConfig;
use Buggregator\Trap\Server\Application\Config\Internal\Attribute\InputOption;
use Buggregator\Trap\Server\Application\Config\Internal\Attribute\XPath;

/**
 * The Xdebug debugger (DBGp): Xdebug connects to {@see $port}; a session goes on to the IDE at {@see $ideAddr},
 * or is driven from the UI and the MCP tools. On with the `--xdebug` option or `XDEBUG_PROXY_ENABLED` only.
 *
 * @internal
 */
#[InflectableConfig]
final class Debugger
{
    public const MODE_PROXY = 'proxy';
    public const MODE_AUTO = 'auto';
    public const MODE_STANDALONE = 'standalone';
    public const MODES = [self::MODE_PROXY, self::MODE_AUTO, self::MODE_STANDALONE];

    /**
     * @var int<1, 65535> Where Xdebug connects: its `xdebug.client_port`. 9003 is Xdebug's default, so the IDE
     *      moves to {@see $ideAddr}.
     */
    #[Env('TRAP_XDEBUG_PORT')]
    #[InputOption('xdebug')]
    #[XPath('/trap/xdebug/@port')]
    public int $port = 9003;

    /** @var non-empty-string|null Host to listen on; null for `TRAP_TCP_HOST` */
    #[Env('TRAP_XDEBUG_HOST')]
    #[XPath('/trap/xdebug/@host')]
    public ?string $host = null;

    /** @var non-empty-string Where the IDE listens for debug connections, `host:port` */
    #[Env('TRAP_XDEBUG_IDE')]
    #[XPath('/trap/xdebug/@ide')]
    public string $ideAddr = '127.0.0.1:9004';

    /** How a new session is driven: `proxy`, `auto` or `standalone` */
    #[Env('TRAP_XDEBUG_MODE')]
    #[XPath('/trap/xdebug/@mode')]
    public string $mode = self::MODE_AUTO;

    /** Eval from the UI and the MCP tools, and breakpoint conditions: both run PHP code in the debugged script */
    #[Env('TRAP_XDEBUG_ALLOW_EVAL')]
    #[XPath('/trap/xdebug/@allowEval')]
    public bool $allowEval = false;

    /** The MCP tools may step, continue and stop a session */
    #[Env('TRAP_XDEBUG_ALLOW_CONTROL')]
    #[XPath('/trap/xdebug/@allowControl')]
    public bool $allowControl = true;

    /** A session driven here stops at the first line */
    #[Env('TRAP_XDEBUG_PAUSE_ON_FIRST_LINE')]
    #[XPath('/trap/xdebug/@pauseOnFirstLine')]
    public bool $pauseOnFirstLine = false;

    /** Seconds a session paused here waits for a command before it is released; 0 for never */
    #[Env('TRAP_XDEBUG_IDLE_TIMEOUT')]
    #[XPath('/trap/xdebug/@idleTimeout')]
    public int $idleTimeout = 600;

    public function mode(): string
    {
        return \in_array($this->mode, self::MODES, true) ? $this->mode : self::MODE_AUTO;
    }
}
