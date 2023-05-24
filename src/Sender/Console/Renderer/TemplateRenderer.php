<?php

declare(strict_types=1);

namespace Buggregator\Client\Sender\Console\Renderer;

use Buggregator\Client\Support\TemplateEngine;
use Termwind\HtmlRenderer;

final class TemplateRenderer
{
    public function __construct(
        private readonly HtmlRenderer $renderer,
        private readonly TemplateEngine $templateEngine,
    ) {
    }

    public function render(string $template, array $data = []): void
    {
        $this->renderer->render(
            $this->templateEngine->render($template, $data),
            0
        );
    }
}
