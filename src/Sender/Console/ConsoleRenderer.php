<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Console;

use Buggregator\Trap\Proto\Frame;
use Symfony\Component\Console\Output\BufferedOutput;
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
        $buffer = new BufferedOutput(
            $this->output->getVerbosity(),
            $this->output->isDecorated(),
            $this->output->getFormatter()
        );

        foreach ($this->renderers as $renderer) {
            if ($renderer->isSupport($frame)) {
                $renderer->render($buffer, $frame);

                $this->output->write($buffer->fetch(), false, OutputInterface::OUTPUT_RAW);

                return;
            }
        }
    }

    public function register(RendererInterface $renderer): void
    {
        $this->renderers[] = $renderer;
    }
}
