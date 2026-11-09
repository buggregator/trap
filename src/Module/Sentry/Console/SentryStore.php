<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Sentry\Console;

use Buggregator\Trap\Module\Sentry\Frame\SentryStore as SentryStoreFrame;
use Buggregator\Trap\Proto\Frame;
use Buggregator\Trap\Sender\Console\Renderer;
use Buggregator\Trap\Sender\Console\Support\Common;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @implements Renderer<SentryStoreFrame>
 *
 * @internal
 */
final class SentryStore implements Renderer
{
    #[\Override]
    public function isSupport(Frame $frame): bool
    {
        return $frame instanceof SentryStoreFrame;
    }

    #[\Override]
    public function render(OutputInterface $output, Frame $frame): void
    {
        \assert($frame instanceof SentryStoreFrame);

        Common::renderHeader1($output, 'SENTRY');

        try {
            Header::renderMessageHeader($output, $frame->message + ['timestamp' => $frame->time->format('U.u')]);
            Exceptions::render($output, $frame->message['exception']['values'] ?? []);
        } catch (\Throwable $e) {
            $output->writeln(['<fg=red>Render error</>', $e->getMessage()]);
        }
    }
}
