<?php

declare(strict_types=1);

namespace Buggregator\Trap;

/**
 * @internal
 */
class Info
{
    public const NAME = 'Buggregator Trap';
    public const VERSION = '1.4.1';
    public const LOGO_CLI_COLOR = <<<CONSOLE
        \e[44;97;1m                                    \e[0m
        \e[44;97;1m      ▄█▀                  ▀█▄      \e[0m
        \e[44;97;1m     ▐█▌      ▄█▀▀▀▀█▄      ▐█▌     \e[0m
        \e[44;97;1m     ██   ▀▄ █        █ ▄▀   ██     \e[0m
        \e[44;97;1m    ▐█▌     █▀▀▀▀▀▀▀▀▀▀█     ▐█▌    \e[0m
        \e[44;97;1m   ▄█▀     ▐▌  ▀▄  ▄▀  ▐▌     ▀█▄   \e[0m
        \e[44;97;1m   ▀█▄   ▄▄█     ██     █▄▄   ▄█▀   \e[0m
        \e[44;97;1m    ▐█▌     █  ▄▀  ▀▄  █     ▐█▌    \e[0m
        \e[44;97;1m     ██      █▄      ▄█      ██     \e[0m
        \e[44;97;1m     ▐█▌   ▄▀  ▀▄▄▄▄▀  ▀▄   ▐█▌     \e[0m
        \e[44;97;1m      ▀█▄                  ▄█▀      \e[0m
        \e[44;97;1m                                    \e[0m
        CONSOLE;

    public const TRAP_ROOT = __DIR__ . '/..';
}
