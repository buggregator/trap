<?php

declare(strict_types=1);

// The class of ext-blackfire in the global namespace, for the tests of a PHP without the probe.
if (!\class_exists(\BlackfireProbe::class, false)) {
    final class BlackfireProbe
    {
        public static bool $enabled = false;

        public static function isEnabled(): bool
        {
            return self::$enabled;
        }
    }
}
