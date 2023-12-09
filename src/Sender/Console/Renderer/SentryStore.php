<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Console\Renderer;

use Buggregator\Trap\Proto\Frame;
use Buggregator\Trap\ProtoType;
use Buggregator\Trap\Sender\Console\Renderer\Sentry\Exceptions;
use Buggregator\Trap\Sender\Console\Renderer\Sentry\Header;
use Buggregator\Trap\Sender\Console\Renderer;
use Buggregator\Trap\Sender\Console\Support\Common;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @implements Renderer<Frame\Sentry\SentryStore>
 *
 * @internal
 */
final class SentryStore implements Renderer
{
    public function isSupport(Frame $frame): bool
    {
        return $frame->type === ProtoType::Sentry && $frame instanceof Frame\Sentry\SentryStore;
    }

    public function render(OutputInterface $output, Frame $frame): void
    {
        \assert($frame instanceof Frame\Sentry\SentryStore);

        Common::renderHeader1($output, 'SENTRY');

        try {
            Header::renderMessageHeader($output, $frame->message + ['timestamp' => $frame->time->format('U.u')]);
            Exceptions::render($output, $frame->message['exception']['values'] ?? []);
        } catch (\Throwable $e) {
            $output->writeln(['<fg=red>Render error</>', $e->getMessage()]);
        }
    }
}
