<?php

declare(strict_types=1);

namespace Buggregator\Trap;

/**
 * @internal
 */
class Info
{
    public const NAME = 'Buggregator Trap';
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
    private const VERSION = 'experimental';

    public static function version(): string
    {
        $versionPath = self::TRAP_ROOT . '/src/version.json';
        if (!file_exists($versionPath)) {
            return self::VERSION;
        }

        /** @var array $versionData */
        $versionData = json_decode(file_get_contents($versionPath), true);

        if (!isset($versionData['.']) || !is_string($versionData['.'])) {
            return self::VERSION;
        }
        return $versionData['.'];
    }
}
