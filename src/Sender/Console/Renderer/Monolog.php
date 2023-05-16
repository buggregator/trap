<?php

declare(strict_types=1);

namespace Buggregator\Client\Sender\Console\Renderer;

use Buggregator\Client\Proto\Frame;
use Buggregator\Client\ProtoType;
use Buggregator\Client\Sender\Console\RendererInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Termwind\HtmlRenderer;

final class Monolog implements RendererInterface
{
    public function __construct(
        private readonly HtmlRenderer $renderer,
    ) {
    }

    public function isSupport(Frame $frame): bool
    {
        return $frame->type === ProtoType::Monolog;
    }

    /**
     * @param Frame\Monolog $frame
     */
    public function render(OutputInterface $output, Frame $frame): void
    {
        $payload = $frame->message;
        $levelColor = match (\strtolower($payload['level_name'])) {
            'notice', 'info' => 'blue',
            'warning' => 'yellow',
            'critical', 'error', 'alert', 'emergency' => 'red',
            default => 'gray'
        };

        $date = $payload['datetime'];
        $channel = $payload['channel'] ?? '';
        $level = $payload['level_name'] . '' ?? 'DEBUG';
        $messages = \array_map(
            static fn(string $message): string => \sprintf("<div class=\"font-bold text-%s-500\">%s</div>", $levelColor, $message),
            \explode("\n", $payload['message'])
        );
        $messages = \implode("", $messages);

        $this->renderer->render(
            <<<HTML
            <div class="mt-2">
                <table>
                    <tr>
                        <th>date</th>
                        <td>$date</td>
                    </tr>
                    <tr>
                        <th>channel</th>
                        <td>$channel</td>
                    </tr>
                </table>

                <h1 class="font-bold text-white my-1">
                    <span class="bg-blue px-1">MONOLOG</span>
                    <span class="px-1 bg-$levelColor">$level</span>
                </h1>

                <div class="mb-1">
                    $messages
                </div>
            </div>
            HTML
            ,
            0
        );

        // It can't be sent to HTML
        if (!empty($payload['context'])) {
            dump($payload['context']);
        }
    }
}
