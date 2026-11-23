<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Sentry\Console;

use Buggregator\Trap\Module\Console\Renderer;
use Buggregator\Trap\Module\Console\Support\Common;
use Buggregator\Trap\Module\Sentry\Frame\EnvelopeItem;
use Buggregator\Trap\Module\Sentry\Frame\SentryEnvelope as SentryEnvelopeFrame;
use Buggregator\Trap\Module\Sentry\Telemetry\FeedbackReader;
use Buggregator\Trap\Server\Proto\Frame;
use Buggregator\Trap\Server\Telemetry\Feedback;
use Symfony\Component\Console\Formatter\OutputFormatter;
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

        if (self::onlyCheckIns($frame)) {
            Common::renderHeader1($output, 'SENTRY', 'CRON');
            Common::renderMetadata($output, ['Time' => $frame->time] + ($frame->project === null ? [] : ['Project' => $frame->project]));
            foreach ($frame->items as $item) {
                \is_array($item->payload) and CheckInLine::render($output, $item->payload, $item->headers);
            }
            return;
        }

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
                TraceLine::render($output, $type, $item->payload) or $this->renderItem($output, $item);
            } catch (\Throwable $e) {
                $output->writeln(['<fg=red>Render error</>', $e->getMessage()]);
                trap($e);
            }
        }
    }

    /**
     * Cron check-ins come in envelopes of their own: one SDK call, or a ping URL.
     */
    private static function onlyCheckIns(SentryEnvelopeFrame $frame): bool
    {
        foreach ($frame->items as $item) {
            if (($item->headers['type'] ?? null) !== 'check_in') {
                return false;
            }
        }

        return $frame->items !== [];
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

    private static function renderFeedback(OutputInterface $output, Feedback $feedback): void
    {
        $from = \trim($feedback->name . ($feedback->email === '' ? '' : " <{$feedback->email}>"));
        $lines = ['', '<fg=cyan>User feedback</> from ' . OutputFormatter::escape($from === '' ? 'anonymous' : $from)];
        $feedback->url === '' or $lines[] = 'On ' . OutputFormatter::escape($feedback->url);
        $lines[] = $feedback->eventId === ''
            ? 'Sent without an error'
            : 'About the error ' . OutputFormatter::escape($feedback->eventId);
        $lines[] = '';
        $lines[] = OutputFormatter::escape($feedback->message);

        $output->writeln($lines);
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
        /** @var mixed $type */
        $type = $data->headers['type'] ?? null;
        if (\is_array($data->payload) && ($type === 'feedback' || $type === 'user_report')) {
            $feedback = FeedbackReader::read('default', $type, $data->payload, null, 0.0);
            if ($feedback === null) {
                $output->writeln(['', '<fg=red>Malformed user feedback: a field has a wrong type.</>']);
                return;
            }

            self::renderFeedback($output, $feedback);
            return;
        }

        if (\is_array($data->payload) && $type === 'check_in') {
            CheckInLine::render($output, $data->payload, $data->headers);
            return;
        }

        $exceptions = \is_array($data->payload) ? self::exceptions($data->payload['exception'] ?? null) : [];
        if ($exceptions !== []) {
            Exceptions::render($output, $exceptions);
            return;
        }

        $output->writeln(['', '<fg=red>There is no renderer for this item type.</>']);
    }
}
