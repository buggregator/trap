<?php

declare(strict_types=1);

namespace Buggregator\Trap\Service;

use Buggregator\Trap\Cancellable;
use Buggregator\Trap\Config\FilesObserver as Config;
use Buggregator\Trap\Logger;
use Buggregator\Trap\Processable;
use Buggregator\Trap\Proto\Buffer;
use Buggregator\Trap\Proto\Frame;
use Buggregator\Trap\Service\FilesObserver\Handler;

/**
 * @internal
 */
final class FilesObserver implements Processable, Cancellable
{
    private bool $cancelled = false;

    /** @var \Fiber[] */
    private array $fibers = [];

    public function __construct(
        private readonly Logger $logger,
        private readonly Buffer $buffer,
        Config ...$configs,
    ) {
        foreach ($configs as $config) {
            $this->fibers[] = new \Fiber(function () use ($config): void {
                foreach (Handler::generate($config, $this->logger) as $frame) {
                    $this->propagateFrame($frame);
                }
            });
        }
    }

    public function process(): void
    {
        if ($this->cancelled) {
            return;
        }

        foreach ($this->fibers as $key => $fiber) {
            try {
                $fiber->isStarted() ? $fiber->resume() : $fiber->start();

                if ($fiber->isTerminated()) {
                    unset($this->fibers[$key]);
                }
            } catch (\Throwable $e) {
                $this->logger->exception($e);
                unset($this->fibers[$key]);
            }
        }
    }

    public function cancel(): void
    {
        $this->cancelled = true;
        $this->fibers = [];
    }

    private function propagateFrame(Frame $frame): void
    {
        $this->buffer->addFrame($frame);
    }
}
