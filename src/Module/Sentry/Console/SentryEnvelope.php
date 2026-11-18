<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Sentry\Console;

use Buggregator\Trap\Module\Console\Renderer;
use Buggregator\Trap\Module\Console\Support\Common;
use Buggregator\Trap\Module\Sentry\Frame\EnvelopeItem;
use Buggregator\Trap\Module\Sentry\Frame\SentryEnvelope as SentryEnvelopeFrame;
use Buggregator\Trap\Server\Proto\Frame;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @implements Renderer<SentryEnvelopeFrame>
 *
 * @internal
 */
final class SentryEnvelope implements Renderer
{
    #[\Override]
    public function isSupport(Frame $frame): bool
    {
        return $frame instanceof SentryEnvelopeFrame;
    }

    #[\Override]
    public function render(OutputInterface $output, Frame $frame): void
    {
        \assert($frame instanceof SentryEnvelopeFrame);

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
     * Sentry SDKs send `{"values": [...]}`, but some send a bare list or a single exception object.
     */
    private static function exceptions(mixed $exception): array
    {
        if (!\is_array($exception)) {
            return [];
        }

        /** @var mixed $list */
        $list = match (true) {
            \array_key_exists('values', $exception) => $exception['values'],
            \array_key_exists('type', $exception),
            \array_key_exists('value', $exception),
            \array_key_exists('stacktrace', $exception) => [$exception],
            default => \array_is_list($exception) ? $exception : [],
        };

        return \is_array($list) ? \array_values(\array_filter($list, \is_array(...))) : [];
    }

    /**
     * Renders an item whose payload is not an event, e.g. an attachment stream.
     */
    private function renderItemHeaders(OutputInterface $output, EnvelopeItem $item): void
    {
        $meta = [];
        isset($item->headers['filename']) and $meta['Filename'] = (string) $item->headers['filename'];
        isset($item->headers['content_type']) and $meta['Content type'] = (string) $item->headers['content_type'];
        isset($item->headers['attachment_type']) and $meta['Attachment type'] = (string) $item->headers['attachment_type'];
        isset($item->headers['length']) and $meta['Length'] = (string) $item->headers['length'];

        Common::renderMetadata($output, $meta);
    }

    private function renderItem(OutputInterface $output, EnvelopeItem $data): void
    {
        $exceptions = \is_array($data->payload) ? self::exceptions($data->payload['exception'] ?? null) : [];
        if ($exceptions !== []) {
            Exceptions::render($output, $exceptions);
            return;
        }

        $output->writeln(['', '<fg=red>There is no renderer for this item type.</>']);
    }
}
