<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Service;

use Buggregator\Trap\Server\Cancellable;
use Buggregator\Trap\Server\Config\Files\ObserverConfig as Config;
use Buggregator\Trap\Server\Logger;
use Buggregator\Trap\Server\Processable;
use Buggregator\Trap\Server\Proto\Buffer;
use Buggregator\Trap\Server\Proto\Frame;
use Buggregator\Trap\Server\Service\FilesObserver\Handler;
use Internal\Container\Container;

/**
 * The service orchestrates the process of scanning files in directories.
 *
 * There are {@see Handler} instances for each configuration that are executed in fibers.
 *
 * @internal
 */
final class FilesObserver implements Processable, Cancellable
{
    private bool $cancelled = false;

    /** @var \Fiber[] */
    private array $fibers = [];

    public function __construct(
        private readonly Container $container,
        private readonly Logger $logger,
        private readonly Buffer $buffer,
        Config ...$configs,
    ) {
        foreach ($configs as $config) {
            if (!$config->isValid()) {
                continue;
            }

            $this->fibers[] = new \Fiber(function () use ($config): void {
                foreach ($this->container->make(Handler::class, ['config' => $config]) as $frame) {
                    $this->propagateFrame($frame);
                }
            });
        }
    }

    #[\Override]
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

    #[\Override]
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
