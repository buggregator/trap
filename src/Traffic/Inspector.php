<?php

declare(strict_types=1);

namespace Buggregator\Trap\Traffic;

use Buggregator\Trap\Logger;
use Buggregator\Trap\Processable;
use Buggregator\Trap\Proto\Buffer;
use Buggregator\Trap\Support\Timer;
use Buggregator\Trap\Traffic\Dispatcher\Binary;

/**
 * @internal
 */
final class Inspector implements Processable
{
    /** @var \Fiber[] */
    private array $fibers = [];

    /** @var Dispatcher[] */
    private array $dispatchers;

    public function __construct(
        private Buffer $buffer,
        private readonly Logger $logger,
        Dispatcher ...$dispatchers,
    ) {
        $this->dispatchers = $dispatchers;
    }

    public function addStream(StreamClient $stream): void
    {
        $this->fibers[] = new \Fiber(fn() => $this->processStream($stream));
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
                    $this->logger->exception($e, 'Fiber failed in the Traffic inspector');
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
                $result = $dispatcher->detect($stream->getData(), $stream->getCreatedAt());
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

            $stream->waitData(new Timer(0.1));
        } while (\count($dispatchers) > 0);

        $dispatcher = $dispatchers === []
            ? new Binary()
            : \reset($dispatchers);

        foreach ($dispatcher->dispatch($stream) as $frame) {
            // Queue frame to send
            $this->buffer->addFrame($frame);
        }
    }
}
