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

    public static function version(): string
    {
        $versionPath = self::TRAP_ROOT . '/src/version.json';
        if (!file_exists($versionPath)) {
            throw new \RuntimeException('Version file not found.');
        }

        /** @var array $versionData */
        $versionData = json_decode(file_get_contents($versionPath), true);

        if (!isset($versionData['.']) || !is_string($versionData['.'])) {
            throw new \RuntimeException('Version key not found or is not a string in JSON.');
        }
        return $versionData['.'];
    }
}
