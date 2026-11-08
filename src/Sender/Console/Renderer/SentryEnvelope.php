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
 * @implements Renderer<Frame\Sentry\SentryEnvelope>
 *
 * @internal
 */
final class SentryEnvelope implements Renderer
{
    #[\Override]
    public function isSupport(Frame $frame): bool
    {
        return $frame->type === ProtoType::Sentry && $frame instanceof Frame\Sentry\SentryEnvelope;
    }

    #[\Override]
    public function render(OutputInterface $output, Frame $frame): void
    {
        \assert($frame instanceof Frame\Sentry\SentryEnvelope);

        Common::renderHeader1($output, 'SENTRY', 'ENVELOPE');
        Header::renderMessageHeader($output, $frame->headers + ['timestamp' => $frame->time->format('U.u')]);

        $i = 0;
        foreach ($frame->items as $item) {
            ++$i;
            try {
                $type = (string) ($item->headers['type'] ?? null);
                Common::renderHeader2($output, "Item $i", green: $type);

                if (!\is_array($item->payload)) {
                    $this->renderItemHeaders($output, $item);
                    continue;
                }

                Header::renderMessageHeader($output, $item->payload);
                $this->renderItem($output, $item);
            } catch (\Throwable $e) {
                $output->writeln(['<fg=red>Render error</>', $e->getMessage()]);
                trap($e);
            }
        }
    }

    /**
     * Renders an item whose payload is not an event, e.g. an attachment stream.
     */
    private function renderItemHeaders(OutputInterface $output, Frame\Sentry\EnvelopeItem $item): void
    {
        $meta = [];
        isset($item->headers['filename']) and $meta['Filename'] = (string) $item->headers['filename'];
        isset($item->headers['content_type']) and $meta['Content type'] = (string) $item->headers['content_type'];
        isset($item->headers['attachment_type']) and $meta['Attachment type'] = (string) $item->headers['attachment_type'];
        isset($item->headers['length']) and $meta['Length'] = (string) $item->headers['length'];

        Common::renderMetadata($output, $meta);
    }

    private function renderItem(OutputInterface $output, Frame\Sentry\EnvelopeItem $data): void
    {
        if (\is_array($data->payload) && isset($data->payload['exceptions'])) {
            Exceptions::render($output, $data->payload['exceptions']);
            return;
        }

        $output->writeln(['', '<fg=red>There is no renderer for this item type.</>']);
    }
}
