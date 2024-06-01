<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender;

use Buggregator\Trap\Info;
use Buggregator\Trap\Sender;
use Buggregator\Trap\Sender\Console\FrameHandler;
use Buggregator\Trap\Sender\Console\Renderer;
use Buggregator\Trap\Sender\Console\Renderer\TemplateRenderer;
use Buggregator\Trap\Support\TemplateEngine;
use Symfony\Component\Console\Output\OutputInterface;
use Termwind\HtmlRenderer;
use Termwind\Termwind;

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
        /** @psalm-suppress InternalMethod, InternalClass */
        Termwind::renderUsing($output);

        /** @psalm-suppress InternalClass */
        $templateRenderer = new TemplateRenderer(
            new HtmlRenderer(),
            new TemplateEngine(Info::TRAP_ROOT . '/resources/templates'),
        );
        // Configure renderer
        $renderer = new FrameHandler($output);
        $renderer->register(new Renderer\VarDumper());
        $renderer->register(new Renderer\SentryStore());
        $renderer->register(new Renderer\SentryEnvelope());
        $renderer->register(new Renderer\Monolog($templateRenderer));
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
