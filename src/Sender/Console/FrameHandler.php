<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Console;

use Buggregator\Trap\Proto\Frame;
use Buggregator\Trap\Sender\FrameHandler as HandlerInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @internal
 */
final class FrameHandler implements HandlerInterface
{
    /**
     * @var Renderer[]
     */
    private array $renderers = [];

    public function __construct(
        private readonly OutputInterface $output,
    ) {}

    public function handle(Frame $frame): void
    {
        $buffer = new BufferedOutput(
            $this->output->getVerbosity(),
            $this->output->isDecorated(),
            $this->output->getFormatter(),
        );

        foreach ($this->renderers as $renderer) {
            if ($renderer->isSupport($frame)) {
                $renderer->render($buffer, $frame);

                $this->output->write($buffer->fetch(), false, OutputInterface::OUTPUT_RAW);

                return;
            }
        }
    }

    public function register(Renderer $renderer): void
    {
        $this->renderers[] = $renderer;
    }
}
