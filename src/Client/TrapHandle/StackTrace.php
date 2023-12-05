<?php

declare(strict_types=1);

namespace Buggregator\Trap\Client\TrapHandle;

/**
 *
 * @psalm-type StackTraceWithObjects = list<array{
 *        function?: string,
 *        line?: int,
 *        file?: string,
 *        class?: class-string,
 *        type?: string,
 *        object?: object,
 *        args?: list<mixed>
 *    }>
 * @psalm-type SimpleStackTrace = list<array{
 *        function?: string,
 *        line?: int,
 *        file?: string,
 *        class?: class-string,
 *        type?: string,
 *        args?: list<mixed>
 *    }>
 */
final class StackTrace
{
    /**
     * Returns a backtrace as an array.
     * Removes the internal frames and the first next frames after them.
     *
     * @param string $baseDir Base directory for relative paths
     * @param bool $provideObjects Whether to provide objects in the stack trace
     *
     * @return ($provideObjects is true ? StackTraceWithObjects : SimpleStackTrace)
     */
    public static function stackTrace(string $baseDir = '', bool $provideObjects = false): array
    {
        $dir = $baseDir . \DIRECTORY_SEPARATOR;
        $cwdLen = \strlen($dir);
        $stack = [];
        $internal = false;
        foreach (
            \debug_backtrace(
                ($provideObjects ? \DEBUG_BACKTRACE_PROVIDE_OBJECT : 0) | \DEBUG_BACKTRACE_IGNORE_ARGS
            ) as $frame
        ) {
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
