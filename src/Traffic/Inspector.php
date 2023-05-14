<?php

declare(strict_types=1);

namespace Buggregator\Client\Traffic;

use Buggregator\Client\Logger;
use Buggregator\Client\Proto\Buffer;
use Buggregator\Client\Socket\StreamClient;
use Fiber;
use RuntimeException;

final class Inspector
{
    /** @var Fiber[] */
    private array $fibers = [];
    /** @var Dispatcher[] */
    private array $dispatchers;

    public function __construct(
        private Buffer $buffer,
        Dispatcher ...$dispatchers,
    ) {
        $this->dispatchers = $dispatchers;
    }

    public function addStream(StreamClient $stream): void
    {
        $this->fibers[] = new Fiber(fn() => $this->processStream($stream));
    }

    public function process(): void
    {
        foreach ($this->fibers as $key => $fiber) {
            try {
                $fiber->isStarted() ? $fiber->resume() : $fiber->start();

                if ($fiber->isTerminated()) {
                    throw new \RuntimeException('Stream terminated.');
                }
            } catch (\Throwable $e) {
                Logger::exception($e, 'Fiber failed in the Traffic inspector');
                unset($this->fibers[$key]);
            }
        }
    }

    private function processStream(StreamClient $stream): void
    {
        $dispatchers = $this->dispatchers;

        do {
            foreach ($dispatchers as $key => $dispatcher) {
                $result = $dispatcher->detect($stream->getData());
                if ($result === true) {
                    $dispatchers = [$dispatcher];
                    break 2;
                }
                if ($result === false) {
                    unset($dispatchers[$key]);
                }
            }

            if (!$stream->isFinished()) {
                $dispatchers = [];
                break;
            }

            Fiber::suspend();
        } while (count($dispatchers) > 0);

        if ($dispatchers === []) {
            throw new RuntimeException('Stream data detection failed.');
        }
        $dispatcher = \reset($dispatchers);

        foreach ($dispatcher->dispatch($stream) as $frame) {
            $this->buffer->addFrame($frame);
        }
    }
}
