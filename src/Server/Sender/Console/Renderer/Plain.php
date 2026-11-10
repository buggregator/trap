<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Sender\Console\Renderer;

use Buggregator\Trap\Server\Proto\Frame;
use Buggregator\Trap\Server\Sender\Console\Renderer;
use Buggregator\Trap\Server\Sender\Console\Support\Common;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @implements Renderer<Frame>
 *
 * @internal
 */
final class Plain implements Renderer
{
    #[\Override]
    public function isSupport(Frame $frame): bool
    {
        return true;
    }

    #[\Override]
    public function render(OutputInterface $output, Frame $frame): void
    {
        Common::renderHeader1($output, $frame->type);

        Common::renderMetadata($output, [
            'Time' => $frame->time->format('Y-m-d H:i:s.u'),
            'Frame' => $frame::class,
        ]);

        Common::renderHeader2($output, 'Payload:');
        $output->writeln((string) $frame);
    }
}
