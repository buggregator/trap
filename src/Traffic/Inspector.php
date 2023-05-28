<?php

declare(strict_types=1);

namespace Buggregator\Client\Traffic;

use Buggregator\Client\Logger;
use Buggregator\Client\Processable;
use Buggregator\Client\Proto\Buffer;
use Buggregator\Client\Socket\StreamClient;
use Buggregator\Client\Traffic\Dispatcher\Binary;
use Fiber;

final class Inspector implements Processable
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
                // todo replace with better exception handling
                if ($e->getMessage() !== 'Stream terminated.') {
                    Logger::exception($e, 'Fiber failed in the Traffic inspector');
                }
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

            if ($stream->isDisconnected()) {
                // todo set not found format dispatcher?
                $dispatchers = [];
                break;
            }

            $stream->waitData();
        } while (count($dispatchers) > 0);

        $dispatcher = $dispatchers === []
            ? new Binary()
            : \reset($dispatchers);

        foreach ($dispatcher->dispatch($stream) as $frame) {
            // Queue frame to send
            $this->buffer->addFrame($frame);
        }
    }
}
