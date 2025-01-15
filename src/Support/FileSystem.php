<?php

declare(strict_types=1);

namespace Buggregator\Trap\Support;

/**
 * @internal
 * @psalm-internal Buggregator\Trap
 */
final class FileSystem
{
    public static function mkdir(string $path, int $mode = 0777, bool $recursive = true): void
    {
        \is_dir($path) or \mkdir($path, $mode, $recursive) or \is_dir($path) or throw new \RuntimeException(
            \sprintf('Directory "%s" was not created.', $path),
        );
    }
}
