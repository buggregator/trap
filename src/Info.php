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

    private static ?string $cachedVersion = null;

    public static function version(): string
    {
        if (self::$cachedVersion !== null) {
            return self::$cachedVersion;
        }

        $versionPath = self::TRAP_ROOT . '/src/version.json';
        $versionContents = file_get_contents($versionPath);

        if ($versionContents === false) {
            self::$cachedVersion = self::VERSION;
            return self::$cachedVersion;
        }

        $versionData = json_decode($versionContents, true);

        if (!is_array($versionData) || !isset($versionData['.']) || !is_string($versionData['.'])) {
            self::$cachedVersion = self::VERSION;
            return self::$cachedVersion;
        }

        self::$cachedVersion = $versionData['.'];

        return self::$cachedVersion;
    }
}
