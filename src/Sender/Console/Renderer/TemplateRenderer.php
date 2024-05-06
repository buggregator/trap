<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Console\Renderer;

use Buggregator\Trap\Support\TemplateEngine;
use Termwind\HtmlRenderer;

/**
 * @internal
 */
final class TemplateRenderer
{
    public function __construct(
        private readonly HtmlRenderer $renderer,
        private readonly TemplateEngine $templateEngine,
    ) {}

    public function render(string $template, array $data = []): void
    {
        /** @psalm-suppress InternalMethod */
        $this->renderer->render(
            $this->templateEngine->render($template, $data),
            0,
        );
    }
}
