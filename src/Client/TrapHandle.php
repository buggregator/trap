<?php

declare(strict_types=1);

namespace Buggregator\Trap\Client;

use Buggregator\Trap\Client\TrapHandle\Counter;
use Symfony\Component\VarDumper\Caster\TraceStub;
use Symfony\Component\VarDumper\VarDumper;

/**
 * @internal
 */
final class TrapHandle
{
    private bool $haveToSend = true;
    private int $times = 0;
    private string $timesCounterKey = '';

    public static function fromArray(array $array): self
    {
        return new self($array);
    }

    /**
     * Dump only if the condition is true.
     * The check is performed immediately upon declaration.
     */
    public function if(bool|callable $condition): self
    {
        if (\is_callable($condition)) {
            $condition = $condition();
        }

        $this->haveToSend = $condition;
        return $this;
    }

    /**
     * Dump only $times times.
     * The counter isn't incremented if the dump is not sent (any other condition is not met).
     * It might be useful for debugging in loops, recursive or just multiple function calls.
     *
     * @param positive-int $times
     * @param bool $fullStack If true, the counter is incremented for each stack trace, not for the line.
     */
    public function times(int $times, bool $fullStack = false): self
    {
        $this->times = $times;
        $this->timesCounterKey = (\serialize(
            $fullStack
                ? $this->stackTrace()
                : $this->stackTrace()[0]
        ));
        return $this;
    }

    /**
     * Dump values only once.
     */
    public function once(): self
    {
        return $this->times(1);
    }

    public function __destruct()
    {
        $this->haveToSend() and $this->sendUsingDump();
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
    private function stackTrace(string $baseDir = ''): array
    {
        $dir = $baseDir . \DIRECTORY_SEPARATOR;
        $cwdLen = \strlen($dir);
        $stack = [];
        foreach (\debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            if (($frame['file'] ?? null) === __FILE__) {
                continue;
            }

            // Convert absolute paths to relative ones
            $cwdLen > 1 && isset($frame['file']) && \str_starts_with($frame['file'], $dir)
                and $frame['file'] = '.' . \DIRECTORY_SEPARATOR . \substr($frame['file'], $cwdLen);

            $stack[] = $frame;
        }

        return $stack;
    }

    private function __construct(
        private array $values,
    ) {
    }

    private function haveToSend(): bool
    {
        if (!$this->haveToSend) {
            return false;
        }

        if ($this->times > 0) {
            return Counter::checkAndIncrement($this->timesCounterKey, $this->times);
        }

        return true;
    }
}
