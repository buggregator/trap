<?php

declare(strict_types=1);

namespace Buggregator\Client\Sender\Console\Renderer;

use Buggregator\Client\Proto\Frame;
use Buggregator\Client\ProtoType;
use Buggregator\Client\Sender\Console\RendererInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @implements RendererInterface<Frame\Monolog>
 */
final class Monolog implements RendererInterface
{
    public function __construct(
        private readonly TemplateRenderer $renderer,
    ) {
    }

    public function isSupport(Frame $frame): bool
    {
        return $frame->type === ProtoType::Monolog;
    }

    public function render(OutputInterface $output, Frame $frame): void
    {
        $payload = $frame->message;
        $levelColor = match (\strtolower($payload['level_name'])) {
            'notice', 'info' => 'blue',
            'warning' => 'yellow',
            'critical', 'error', 'alert', 'emergency' => 'red',
            default => 'gray'
        };

        $this->renderer->render(
            'monolog',
            [
                'date' => $payload['datetime'],
                'channel' => $payload['channel'] ?? '',
                'level' => $payload['level_name'] . '' ?? 'DEBUG',
                'levelColor' => $levelColor,
                'messages' => \explode("\n", $payload['message']),
            ]
        );

        // It can't be sent to HTML
        if (!empty($payload['context'])) {
            dump($payload['context']);
        }
    }
}
