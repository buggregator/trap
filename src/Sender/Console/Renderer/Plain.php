<?php

declare(strict_types=1);

namespace Buggregator\Client\Sender\Console\Renderer;

use Buggregator\Client\Proto\Frame;
use Buggregator\Client\Sender\Console\RendererInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class Plain implements RendererInterface
{
    public function isSupport(Frame $frame): bool
    {
        return true;
    }

    public function render(OutputInterface $output, Frame $frame): void
    {
        $output->writeln([
            \sprintf(
                'Type: <fg=yellow;options=bold>%s</>',
                $frame->type->value,
            ),
            \sprintf(
                'Time: <fg=green>%s</>',
                $frame->time->format('Y-m-d H:i:s.u'),
            ),
            \sprintf(
                'Payload: <fg=white>%s</>',
                (string) $frame,
            ),
        ]);
    }
}
