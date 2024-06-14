<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Console\Renderer;

use Buggregator\Trap\Proto\Frame;
use Buggregator\Trap\ProtoType;
use Buggregator\Trap\Sender\Console\Renderer;
use Buggregator\Trap\Sender\Console\Support\Common;
use Buggregator\Trap\Sender\Console\Support\Files;
use Buggregator\Trap\Support\Measure;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @implements Renderer<Frame\Smtp>
 *
 * @internal
 */
final class Smtp implements Renderer
{
    public function isSupport(Frame $frame): bool
    {
        return $frame->type === ProtoType::SMTP;
    }

    public function render(OutputInterface $output, Frame $frame): void
    {
        \assert($frame instanceof Frame\Smtp);

        Common::renderHeader1($output, 'SMTP');
        Common::renderMetadata($output, [
            'Time' => $frame->time,
        ]);
        $message = $frame->message;

        // Protocol fields table
        Common::renderHeader3($output, 'Protocol');
        Common::renderHeaders($output, $message->getProtocol());

        // Headers table
        Common::renderHeader3($output, 'Headers');
        Common::renderHeaders($output, $message->getHeaders());

        // Text body
        $i = 0;
        foreach ($message->getMessages() as $text) {
            Common::renderHeader3($output, \sprintf('Body %s', ++$i > 1 ? $i : ''));
            if (\count($message->getMessages()) > 1) {
                Common::renderHeaders($output, $text->getHeaders());
                $output->writeln('');
            }
            $output->write($text->getValue(), true, OutputInterface::OUTPUT_NORMAL);
        }

        /** @var list<\Buggregator\Trap\Traffic\Message\Multipart\File> $attachments */
        /** @var list<\Buggregator\Trap\Traffic\Message\Multipart\File> $embeddings */
        $attachments = $embeddings = [];
        foreach ($message->getAttachments() as $attach) {
            if ($attach->isEmbedded()) {
                $embeddings[] = $attach;
            } else {
                $attachments[] = $attach;
            }
        }

        // Attachments
        if ($attachments !== []) {
            Common::renderHeader3($output, 'Attached files');

            foreach ($attachments as $attach) {
                Files::renderFile(
                    $output,
                    $attach->getClientFilename() ?? '',
                    $attach->getSize(),
                    $attach->getClientMediaType() ?? '',
                );
            }
            $output->writeln('');
        }

        // Embeddings
        if ($embeddings !== []) {
            Common::renderHeader3($output, 'Embedded files');

            \Buggregator\Trap\Sender\Console\Support\Tables::renderMultiColumnTable(
                $output,
                '',
                \array_map(static fn($attach) => [
                    'CID' => $attach->getEmbeddingId(),
                    'Name' => $attach->getClientFilename(),
                    'Size' => $attach->getSize() === null ? 'unknown size' : Measure::memory($attach->getSize()),
                    'MIME' => $attach->getClientMediaType(),
                ], $embeddings),
                'compact',
            );
            $output->writeln('');
        }

        // Raw body
        // $output->write((string) $frame->message->getBody(), true, OutputInterface::OUTPUT_RAW);
    }
}
