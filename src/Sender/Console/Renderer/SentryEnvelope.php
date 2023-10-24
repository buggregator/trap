<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Console\Renderer;

use Buggregator\Trap\Proto\Frame;
use Buggregator\Trap\ProtoType;
use Buggregator\Trap\Sender\Console\RendererInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @internal
 */
final class SentryEnvelope implements RendererInterface
{
    public function isSupport(Frame $frame): bool
    {
        return $frame->type === ProtoType::Sentry && $frame instanceof Frame\SentryEnvelope;
    }

    /**
     * @param Frame\SentryEnvelope $frame
     * @throws \JsonException
     */
    public function render(OutputInterface $output, Frame $frame): void
    {
        $output->writeln(
            \sprintf(
                '<fg=red>%s</>',
                'Sentry envelope renderer is not implemented yet.',
            )
        );

        $output->writeln(
            \sprintf(
                '<fg=gray>%s</>',
                \sprintf(
                    'Envelope items count: %d',
                    \count($frame->items),
                ),
            )
        );
    }
}
