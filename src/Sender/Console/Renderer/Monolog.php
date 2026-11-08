<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Console\Renderer;

use Buggregator\Trap\Proto\Frame;
use Buggregator\Trap\ProtoType;
use Buggregator\Trap\Sender\Console\Renderer;
use Buggregator\Trap\Sender\Console\Support\Common;
use Buggregator\Trap\Sender\Console\Support\Color;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @implements Renderer<Frame\Monolog>
 *
 * @internal
 */
final class Monolog implements Renderer
{
    #[\Override]
    public function isSupport(Frame $frame): bool
    {
        return $frame->type === ProtoType::Monolog;
    }

    #[\Override]
    public function render(OutputInterface $output, Frame $frame): void
    {
        \assert($frame instanceof Frame\Monolog);

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
