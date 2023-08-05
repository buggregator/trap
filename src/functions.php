<?php

declare(strict_types=1);

use Buggregator\Trap\Support\ProtobufDTO;
use Google\Protobuf\Internal\Message;
use Symfony\Component\VarDumper\Caster\TraceStub;
use Symfony\Component\VarDumper\VarDumper;

if (!function_exists('trap')) {
    /**
     * Configure VarDumper to dump values to the local server.
     * If there are no values - dump stack trace.
     *
     * @param mixed ...$values
     */
    function trap(mixed ...$values): void
    {
        // Set default values if not set
        if (!isset($_SERVER['VAR_DUMPER_FORMAT'], $_SERVER['VAR_DUMPER_SERVER'])) {
            $_SERVER['VAR_DUMPER_FORMAT'] = 'server';
            // todo use the config file in the future
            $_SERVER['VAR_DUMPER_SERVER'] = '127.0.0.1:9912';
        }

        // If there are no values - stack trace
        if ($values === []) {
            // VarDumper::dump(\debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS));
            $cwd = \getcwd();
            $stack = \debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
            // Replace paths with relative paths
            foreach ($stack as $i => $frame) {
                if (isset($frame['file'])) {
                    $stack[$i]['file'] = \str_replace($cwd, '.', $frame['file']);
                }
            }
            VarDumper::dump([
                'cwd' => $cwd,
                'trace' => new TraceStub($stack)
            ]);
            return;
        }

        // Process protobuf classes
        foreach ($values as $key => $value) {
            try {
                if ($value instanceof Message) {
                    $values[$key] = ProtobufDTO::createFromMessage($value);
                }
            } catch (\Throwable) {
                // Ignore
            }
        }

        // Dump single value
        if (array_keys($values) === [0]) {
            VarDumper::dump($values);
            return;
        }

        // Dump sequence of values
        foreach ($values as $key => $value) {
            VarDumper::dump($value, $key);
		}
    }
}

