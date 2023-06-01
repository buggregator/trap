<?php

declare(strict_types=1);

namespace Buggregator\Client\Sender\Console\Renderer;

use Buggregator\Client\Proto\Frame;
use Buggregator\Client\ProtoType;
use Buggregator\Client\Sender\Console\RendererInterface;
use Buggregator\Client\Sender\Console\Support\Common;
use Buggregator\Client\Sender\Console\Support\Files;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @implements RendererInterface<Frame\Smtp>
 */
final class Smtp implements RendererInterface
{
    public function isSupport(Frame $frame): bool
    {
        return $frame->type === ProtoType::SMTP;
    }

    public function render(OutputInterface $output, Frame $frame): void
    {
        Common::renderHeader1($output, 'SMTP');
        Common::renderMetadata($output, [
            'Time' => $frame->time->format('Y-m-d H:i:s.u'),
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
            Common::renderHeader3($output, sprintf('Body %s', ++$i > 1 ? $i : ''));
            if (\count($message->getMessages()) > 1) {
                Common::renderHeaders($output, $text->getHeaders());
                $output->writeln('');
            }
            $output->write($text->getValue(), true, OutputInterface::OUTPUT_NORMAL);
        }

        // Attaches
        if (\count($message->getAttaches()) > 0) {
            Common::renderHeader3($output, 'Attached files');
            foreach ($message->getAttaches() as $attach) {
                Files::renderFile(
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
