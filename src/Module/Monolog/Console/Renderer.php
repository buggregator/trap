<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Monolog\Console;

use Buggregator\Trap\Module\Console\Renderer as RendererInterface;
use Buggregator\Trap\Module\Console\Support\Color;
use Buggregator\Trap\Module\Console\Support\Common;
use Buggregator\Trap\Module\Monolog\Frame as MonologFrame;
use Buggregator\Trap\Server\Proto\Frame;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @implements RendererInterface<MonologFrame>
 *
 * @internal
 */
final class Renderer implements RendererInterface
{
    #[\Override]
    public function isSupport(Frame $frame): bool
    {
        return $frame instanceof MonologFrame;
    }

    #[\Override]
    public function render(OutputInterface $output, Frame $frame): void
    {
        \assert($frame instanceof MonologFrame);

        $payload = $frame->message;
        $level = (string) ($payload['level_name'] ?? 'DEBUG');

        $levelColor = match (\strtolower($level)) {
            'notice', 'info' => Color::Blue,
            'warning' => Color::Yellow,
            'critical', 'error', 'alert', 'emergency' => Color::Red,
            default => Color::Gray,
        };

        Common::renderHeader1($output, 'MONOLOG', ...[$levelColor->value => $level]);

        $messages = \explode("\n", (string) ($payload['message'] ?? ''));
        unset($payload['message']);

        $metadata = \array_merge(['Time' => $frame->time], $payload);

        Common::renderMetadata($output, $metadata);

        foreach ($messages as $message) {
            $output->writeln(\sprintf('<fg=%s;options=bold>%s</>', $levelColor->value, $message));
        }
    }
}
