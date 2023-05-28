<?php

declare(strict_types=1);

namespace Buggregator\Client\Sender;

use Buggregator\Client\Info;
use Buggregator\Client\Sender;
use Buggregator\Client\Sender\Console\ConsoleRenderer;
use Buggregator\Client\Sender\Console\HandlerInterface;
use Buggregator\Client\Sender\Console\Renderer;
use Buggregator\Client\Sender\Console\Renderer\TemplateRenderer;
use Buggregator\Client\Support\TemplateEngine;
use Symfony\Component\Console\Output\OutputInterface;
use Termwind\HtmlRenderer;
use Termwind\Termwind;

final class ConsoleSender implements Sender
{
    public static function create(OutputInterface $output): self
    {
        Termwind::renderUsing($output);

        $templateRenderer = new TemplateRenderer(
            new HtmlRenderer(),
            new TemplateEngine(Info::TRAP_ROOT . '/resources/templates')
        );
        // Configure renderer
        $renderer = new ConsoleRenderer($output);
        $renderer->register(new Renderer\VarDumper());
        $renderer->register(new Renderer\SentryStore($templateRenderer));
        $renderer->register(new Renderer\Monolog($templateRenderer));
        $renderer->register(new Renderer\Smtp($templateRenderer));
        $renderer->register(new Renderer\Http());
        $renderer->register(new Renderer\Binary());
        $renderer->register(new Renderer\Plain($templateRenderer));

        return new self($renderer);
    }

    public function __construct(
        private readonly HandlerInterface $handler,
    ) {
    }

    public function send(iterable $frames): void
    {
        foreach ($frames as $frame) {
            $this->handler->handle($frame);
        }
    }
}
