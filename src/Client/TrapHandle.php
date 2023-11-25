<?php

declare(strict_types=1);

namespace Buggregator\Trap\Client;

use Symfony\Component\VarDumper\Caster\TraceStub;
use Symfony\Component\VarDumper\VarDumper;

/**
 * @internal
 * @psalm-internal Buggregator\Trap
 */
final class TrapHandle
{
    private array $values;
    private bool $haveToSend = true;

    public static function fromArray(array $array): self
    {
        $new = new self();
        $new->values = $array;
        return $new;
    }

    /**
     * Dump only if the condition is true.
     */
    public function if(bool|callable $condition): self
    {
        if (\is_callable($condition)) {
            $condition = $condition();
        }

        $this->haveToSend = $condition;
        return $this;
    }

    public function __destruct()
    {
        $this->haveToSend and $this->sendUsingDump();
    }

    private function sendUsingDump(): void
    {
        \class_exists(VarDumper::class) or throw new \RuntimeException(
            'VarDumper is not installed. Please install symfony/var-dumper package.'
        );

        // Set default values if not set
        if (!isset($_SERVER['VAR_DUMPER_FORMAT'], $_SERVER['VAR_DUMPER_SERVER'])) {
            $_SERVER['VAR_DUMPER_FORMAT'] = 'server';
            // todo use the config file in the future
            $_SERVER['VAR_DUMPER_SERVER'] = '127.0.0.1:9912';
        }

        // If there are no values - stack trace
        if ($this->values === []) {
            VarDumper::dump([
                'cwd' => \getcwd(),
                'trace' => new TraceStub(($this->stackTrace(\getcwd()))),
            ]);
            return;
        }

        // Dump single value
        if (\array_keys($this->values) === [0]) {
            VarDumper::dump($this->values[0]);
            return;
        }

        // Dump sequence of values
        foreach ($this->values as $key => $value) {
            /** @psalm-suppress TooManyArguments */
            VarDumper::dump($value, $key);
        }
    }

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
    private function stackTrace(string $baseDir): array
    {
        $dir = \getcwd() . \DIRECTORY_SEPARATOR;
        $cwdLen = \strlen($dir);
        // Replace paths with relative paths
        $stack = [];
        foreach (\debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            if (($frame['class'] ?? null) === __CLASS__) {
                continue;
            }

            // Convert absolute paths to relative ones
            isset($frame['file']) && \str_starts_with($frame['file'], $dir)
                and $frame['file'] = '.' . \DIRECTORY_SEPARATOR . \substr($frame['file'], $cwdLen);

            $stack[] = $frame;
        }

        return $stack;
    }
}
