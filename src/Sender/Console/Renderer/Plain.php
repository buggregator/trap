<?php

declare(strict_types=1);

namespace Buggregator\Client\Sender\Console\Renderer;

use Buggregator\Client\Proto\Frame;
use Buggregator\Client\Sender\Console\RendererInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @implements RendererInterface<Frame>
 */
final class Plain implements RendererInterface
{
    public function __construct(
        private readonly TemplateRenderer $renderer,
    ) {
    }

    public function isSupport(Frame $frame): bool
    {
        return true;
    }

    public function render(OutputInterface $output, Frame $frame): void
    {
        $this->renderer->render(
            'plain',
            [
                'date' => $frame->time->format('Y-m-d H:i:s.u'),
                'channel' => \strtoupper($frame->type->value),
                'body' => \htmlspecialchars((string)$frame),
            ]
        );
    }
}
