<?php

declare(strict_types=1);

namespace Buggregator\Trap\Service;

use Buggregator\Trap\Cancellable;
use Buggregator\Trap\Config\FilesObserver as Config;
use Buggregator\Trap\Processable;
use Buggregator\Trap\Proto\Buffer;
use Buggregator\Trap\Proto\Frame\Profile\File as FileFrame;
use Buggregator\Trap\Service\FilesObserver\FileInfo;
use Buggregator\Trap\Service\FilesObserver\Handler;
use Fiber;

/**
 * @internal
 */
final class FilesObserver implements Processable, Cancellable
{
    private bool $cancelled = false;
    /** @var Fiber[] */
    private array $fibers = [];

    public function __construct(
        private readonly Buffer $buffer,
        Config ...$configs,
    ) {
        foreach ($configs as $config) {
            $this->fibers[] = new Fiber(function () use ($config) {
                foreach (Handler::generate($config) as $fileInfo) {
                    $this->propagateFrame($fileInfo);
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

    private function propagateFrame(FileInfo $info): void
    {
        $frame = new FileFrame($info);
        $this->buffer->addFrame($frame);
    }
}
