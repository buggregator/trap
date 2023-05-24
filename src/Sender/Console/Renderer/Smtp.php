<?php

declare(strict_types=1);

namespace Buggregator\Client\Sender\Console\Renderer;

use Buggregator\Client\Proto\Frame;
use Buggregator\Client\ProtoType;
use Buggregator\Client\Sender\Console\RendererInterface;
use Buggregator\Client\Sender\Console\Support\RenderTable;
use Buggregator\Client\Traffic\Smtp\Message;
use Buggregator\Client\Traffic\Smtp\Parser;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @implements RendererInterface<Frame\Smtp>
 */
final class Smtp implements RendererInterface
{
    use RenderTable;

    private readonly Parser $parser;

    public function __construct(
        private readonly TemplateRenderer $renderer,
    ) {
        $this->parser = new Parser();
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

        // Headers table
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
        foreach ($message->getTexts() as $text) {
            $type = $text->getHeaderLine('Content-Type') ?: 'text/plain';
            $output->writeln(['', "<fg=green>Body </><fg=yellow> $type </>", '']);
            $output->write($text->getValue(), true, OutputInterface::OUTPUT_NORMAL);
        }

        // Attaches
        foreach ($message->getAttaches() as $attach) {
            $type = $attach->getHeaderLine('Content-Type');
            $output->writeln(['', "<fg=green>Attached file </><fg=yellow> $type </>"]);
            $output->writeln('Name: ' . $attach->getClientFilename());
            $output->writeln('Size: ' . $attach->getSize());
        }

        // Raw body
        // $output->write((string) $frame->message->getBody(), true, OutputInterface::OUTPUT_RAW);

    }

    private function generateAddresses(Message $message): array
    {
        $addresses = [];
        $data = $message->jsonSerialize();

        foreach (['from', 'to', 'cc', 'bcc', 'reply_to'] as $type) {
            if (($users = $this->prepareUsers($data, $type)) !== []) {
                $addresses[$type] = \array_map(static fn(array $items): string => empty($items['name'])
                    ? $items['email']
                    : $items['name'] . ' [' . $items['email'] . ']', $users);
            }
        }

        return $addresses;
    }

    protected function prepareUsers(array $payload, string $key): array
    {
        $users = [];
        foreach ($payload[$key] as $user) {
            $users[] = $user;
        }

        return $users;
    }
}
