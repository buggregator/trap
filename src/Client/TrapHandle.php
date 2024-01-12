<?php

declare(strict_types=1);

namespace Buggregator\Trap\Client;

use Buggregator\Trap\Client\TrapHandle\Counter;
use Buggregator\Trap\Client\TrapHandle\Dumper as VarDumper;
use Buggregator\Trap\Client\TrapHandle\StaticState;
use Symfony\Component\VarDumper\Caster\TraceStub;

/**
 * @internal
 */
final class TrapHandle
{
    private bool $haveToSend = true;
    private int $times = 0;
    private string $timesCounterKey = '';
    private int $depth = 0;
    private StaticState $staticState;

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
            try {
                $condition = (bool)$condition();
            } catch (\Throwable $e) {
                $this->values[] = $e;

                return $this;
            }
        }

        $this->haveToSend = $condition;
        return $this;
    }

    /**
     * Set max depth for the dump.
     *
     * @param int<0, max> $depth If 0 - no limit.
     */
    public function depth(int $depth): self
    {
        $this->depth = $depth;
        return $this;
    }

    /**
     * Dump only $times times.
     * The counter isn't incremented if the dump is not sent (any other condition is not met).
     * It might be useful for debugging in loops, recursive or just multiple function calls.
     *
     * @param positive-int $times Zero means no limit.
     * @param bool $fullStack If true, the counter is incremented for each stack trace, not for the line.
     */
    public function times(int $times, bool $fullStack = false): self
    {
        $this->times = $times;
        $this->timesCounterKey = \sha1(\serialize(
            $fullStack
                ? $this->staticState->stackTrace
                : $this->staticState->stackTrace[0]
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
        $this->haveToSend() and $this->sendDump();
    }

    private function sendDump(): void
    {
        $staticState = StaticState::getValue();
        // todo resolve race condition with fibers
        StaticState::setState($this->staticState);

        try {
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
                    // todo StackTrace::stackTrace(\getcwd()) - add CWD
                    'trace' => new TraceStub($this->staticState->stackTrace),
                ], depth: $this->depth);
                return;
            }

            // Dump single value
            if (\array_keys($this->values) === [0]) {
                VarDumper::dump($this->values[0], depth: $this->depth);
                return;
            }

            // Dump sequence of values
            /**
             * @var string|int $key
             * @var mixed $value
             */
            foreach ($this->values as $key => $value) {
                /** @psalm-suppress TooManyArguments */
                VarDumper::dump($value, label: $key, depth: $this->depth);
            }
        } finally {
            StaticState::setState($staticState);
        }
    }

    private function __construct(
        private array $values,
    ) {
        $this->staticState = StaticState::new();
    }

    private function haveToSend(): bool
    {
        if (!$this->haveToSend) {
            return false;
        }

        if ($this->times > 0) {
            \assert($this->timesCounterKey !== '');
            return Counter::checkAndIncrement($this->timesCounterKey, $this->times);
        }

        return true;
    }
}
