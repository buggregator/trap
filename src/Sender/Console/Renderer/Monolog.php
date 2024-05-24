<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Console\Renderer;

use Buggregator\Trap\Proto\Frame;
use Buggregator\Trap\ProtoType;
use Buggregator\Trap\Sender\Console\Renderer;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @implements Renderer<Frame\Monolog>
 *
 * @internal
 */
final class Monolog implements Renderer
{
    public function __construct(
        private readonly TemplateRenderer $renderer,
    ) {}

    public function isSupport(Frame $frame): bool
    {
        return $frame->type === ProtoType::Monolog;
    }

    public function render(OutputInterface $output, Frame $frame): void
    {
        \assert($frame instanceof Frame\Monolog);

        $payload = $frame->message;
        $levelColor = match (\strtolower($payload['level_name'])) {
            'notice', 'info' => 'blue',
            'warning' => 'yellow',
            'critical', 'error', 'alert', 'emergency' => 'red',
            default => 'gray',
        };

        $this->renderer->render(
            'monolog',
            [
                'date' => $payload['datetime'],
                'channel' => $payload['channel'] ?? '',
                'level' => $payload['level_name'] ?? 'DEBUG',
                'levelColor' => $levelColor,
                'messages' => \explode("\n", $payload['message']),
            ],
        );

        // It can't be sent to HTML
        if (!empty($payload['context'])) {
            dump($payload['context']);
        }
    }
}
