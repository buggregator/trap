<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender;

use Buggregator\Trap\Sender;
use Buggregator\Trap\Sender\Console\FrameHandler;
use Buggregator\Trap\Sender\Console\Renderer;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @internal
 */
final class ConsoleSender implements Sender
{
    public function __construct(
        private readonly FrameHandler $handler,
    ) {}

    public static function create(OutputInterface $output): self
    {
        // Configure renderer
        $renderer = new FrameHandler($output);
        $renderer->register(new Renderer\VarDumper());
        $renderer->register(new Renderer\SentryStore());
        $renderer->register(new Renderer\SentryEnvelope());
        $renderer->register(new Renderer\Monolog());
        $renderer->register(new Renderer\Smtp());
        $renderer->register(new Renderer\Http());
        $renderer->register(new Renderer\Profiler());
        $renderer->register(new Renderer\Binary());
        $renderer->register(new Renderer\Plain());

        return new self($renderer);
    }

    public function send(iterable $frames): void
    {
        foreach ($frames as $frame) {
            $this->handler->handle($frame);
        }
    }
}
