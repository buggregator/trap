<?php

declare(strict_types=1);

namespace Buggregator\Trap\Client\TrapHandle;

use Buggregator\Trap\Client\TrapHandle;

final class StackTrace
{
    /**
     * @param string $baseDir Base directory for relative paths
     * @return array<string, array{
     *     function?: non-empty-string,
     *     line?: int<0, max>,
     *     file?: non-empty-string,
     *     class?: class-string,
     *     object?: object,
     *     type?: non-empty-string,
     *     args?: array
     * }>
     */
    public static function stackTrace(string $baseDir = ''): array
    {
        $dir = $baseDir . \DIRECTORY_SEPARATOR;
        $cwdLen = \strlen($dir);
        $stack = [];
        $internal = false;
        foreach (\debug_backtrace(\DEBUG_BACKTRACE_PROVIDE_OBJECT | \DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            if (\str_starts_with($frame['class'] ?? '', 'Buggregator\\Trap\\Client\\')) {
                $internal = true;
                $stack = [];
                continue;
            }

            if ($internal) {
                // todo check the NoStackTrace attribute

                $internal = false;
            }

            // Convert absolute paths to relative ones
            $cwdLen > 1 && isset($frame['file']) && \str_starts_with($frame['file'], $dir)
            and $frame['file'] = '.' . \DIRECTORY_SEPARATOR . \substr($frame['file'], $cwdLen);

            $stack[] = $frame;
        }

        return $stack;
    }
}
