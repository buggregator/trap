<?php

declare(strict_types=1);

namespace Buggregator\Trap\Support;

/**
 * @internal
 * @psalm-internal Buggregator\Trap
 */
final class TemplateEngine
{
    public function __construct(
        private readonly string $templateDir,
    ) {}

    public function render(string $template, array $data = []): string
    {
        $templatePath = $this->templateDir . '/' . $template . '.php';

        if (!\file_exists($templatePath)) {
            throw new \RuntimeException('Template not found: ' . $template);
        }
        \ob_start();

        \extract($data); // Extract the variables from the data array
        include $templatePath;

        return \ob_get_clean();
    }
}
