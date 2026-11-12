<?php

declare(strict_types=1);

namespace Buggregator\Trap\Client\Profiler\Internal;

/**
 * @internal
 */
final class Env
{
    public static function get(string $name): ?string
    {
        if (\array_key_exists($name, $_ENV)) {
            // Code may put anything into `$_ENV`
            /** @var mixed $value */
            $value = $_ENV[$name];
            return \is_scalar($value) ? (string) $value : null;
        }

        $value = \getenv($name, true);
        if ($value !== false) {
            return $value;
        }

        $value = \getenv($name);
        return $value === false ? null : $value;
    }
}
