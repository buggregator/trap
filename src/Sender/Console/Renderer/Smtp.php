<?php

declare(strict_types=1);

namespace Buggregator\Client\Sender\Console\Renderer;

use Buggregator\Client\Proto\Frame;
use Buggregator\Client\ProtoType;
use Buggregator\Client\Sender\Console\RendererInterface;
use Buggregator\Client\Sender\Console\Support\RenderFile;
use Buggregator\Client\Sender\Console\Support\RenderTable;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @implements RendererInterface<Frame\Smtp>
 */
final class Smtp implements RendererInterface
{
    use RenderTable;
    use RenderFile;

    public function __construct(
        private readonly TemplateRenderer $renderer,
    ) {
    }

    public function isSupport(Frame $frame): bool
    {
        return $frame->type === ProtoType::SMTP;
    }

    public function render(OutputInterface $output, Frame $frame): void
    {
        // $message = $this->parser->parse($frame->message);
        //
        // TODO implement attachments
        //        $attachments = [];
        //        foreach ($message->attachments ?? [] as $attachment) {
        //            $attachments[] = $attachment->;
        //        }
        //
        // $this->renderer->render('smtp', [
        //     'date' => $frame->time->format('Y-m-d H:i:s.u'),
        //     'subject' => $message->subject,
        //     'addresses' => $this->generateAddresses($message),
        //     'body' => $message->textBody,
        //     // 'attachments' => $attachments,
        // ]);


        $message = $frame->message;

        $date = $frame->time->format('Y-m-d H:i:s.u');
        $subject = $message->getHeaderLine('Subject');

        $output->writeln(['', '<fg=white;bg=blue> SMTP </>', '']);
        $this->renderKeyValueTable($output, '', [
            'Time' => $date,
        ]);

        // Protocol fields table
        $output->writeln('');
        $this->renderKeyValueTable(
            $output,
            'Protocol',
            \array_map(static fn (array|string $value): string => \implode(', ', (array) $value), $message->getProtocol()),
        );

        // Headers table
        $output->writeln('');
        $this->renderKeyValueTable(
            $output,
            'Headers',
            \array_map(static fn (array $value): string => \implode(', ', $value), $message->getHeaders()),
        );

        // Subject
        $output->writeln('<fg=white;options=bold>');
        $output->write($subject, true, OutputInterface::OUTPUT_NORMAL);
        $output->writeln('</><fg=gray>---</>');

        // Text body
        foreach ($message->getMessages() as $text) {
            $type = $text->getHeaderLine('Content-Type') ?: 'text/plain';
            $output->writeln(['', "<fg=green>Body </><fg=yellow> $type </>", '']);
            $output->write($text->getValue(), true, OutputInterface::OUTPUT_NORMAL);
        }

        // Attaches
        if (\count($message->getAttaches()) > 0) {
            // Attachments label
            $output->writeln(['', "<bg=white;fg=gray;options=bold> Attached files </>"]);

            foreach ($message->getAttaches() as $attach) {
                $this->renderFile(
                    $output,
                    $attach->getClientFilename(),
                    $attach->getSize(),
                    $attach->getClientMediaType(),
                );
            }
            $output->writeln('');
        }

        // Raw body
        // $output->write((string) $frame->message->getBody(), true, OutputInterface::OUTPUT_RAW);

    }
}
