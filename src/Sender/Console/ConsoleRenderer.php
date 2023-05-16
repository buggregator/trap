<?php

declare(strict_types=1);

namespace Buggregator\Client\Sender\Console;

use Buggregator\Client\Proto\Frame;
use Symfony\Component\Console\Output\OutputInterface;

final class ConsoleRenderer implements HandlerInterface
{
    /**
     * @var RendererInterface[]
     */
    private array $renderers = [];

    public function __construct(
        private readonly OutputInterface $output,
    ) {
    }

    public function handle(Frame $frame): void
    {
        foreach ($this->renderers as $renderer) {
            if ($renderer->isSupport($frame)) {
                $renderer->render($this->output, $frame);
            }
        }
    }

    public function register(RendererInterface $renderer): void
    {
        $this->renderers[] = $renderer;
    }
}
