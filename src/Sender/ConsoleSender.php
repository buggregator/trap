<?php

declare(strict_types=1);

namespace Buggregator\Client\Sender;

use Buggregator\Client\Proto\Frame;
use Buggregator\Client\Sender;
use Buggregator\Client\Sender\Console\ConsoleRenderer;
use Buggregator\Client\Sender\Console\HandlerInterface;
use Buggregator\Client\Sender\Console\Renderer;
use Symfony\Component\Console\Output\OutputInterface;
use Termwind\HtmlRenderer;
use Termwind\Termwind;

final class ConsoleSender implements Sender
{
    public static function create(OutputInterface $output): self
    {
        Termwind::renderUsing($output);

        $htmlRenderer = new HtmlRenderer();

        // Configure renderer
        $renderer = new ConsoleRenderer($output);
        $renderer->register(new Renderer\VarDumper());
        $renderer->register(new Renderer\Monolog($htmlRenderer));
        $renderer->register(new Renderer\Http());
        $renderer->register(new Renderer\Smtp());
        $renderer->register(new Renderer\Plain($htmlRenderer));

        return new self($renderer);
    }

    public function __construct(
        private readonly HandlerInterface $handler,
    ) {
    }

    /**
     * @param iterable<int, Frame> $frames
     */
    public function send(iterable $frames): void
    {
        foreach ($frames as $frame) {
            $this->handler->handle($frame);
        }
    }
}
