<?php

declare(strict_types=1);

namespace Buggregator\Trap\Support\Spx;

/**
 * Function names of an SPX trace.
 *
 * @internal
 */
final class FunctionName
{
    /** What SPX names code that runs in no file. */
    public const NO_FILE = '[no active file]';

    /**
     * Whether the name is of file-scope code: SPX names it by the path of its file, an included one too.
     */
    public static function isFileScope(string $name): bool
    {
        return $name === self::NO_FILE
            || (!\str_contains($name, '::') && \preg_match('~^(?:/|[a-z]:[\\\\/])|\.(?:php|phar|inc)$~i', $name) === 1);
    }
}
