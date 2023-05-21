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

    public function __construct()
    {
        $this->parser = new Parser();
    }

    public function isSupport(Frame $frame): bool
    {
        return $frame->type === ProtoType::SMTP;
    }

    public function render(OutputInterface $output, Frame $frame): void
    {
        $message = $this->parser->parse($frame->message);
        $date = $frame->time->format('Y-m-d H:i:s.u');
        $subject = $message->subject;
        $addresses = $this->generateAddresses($message);

        $output->writeln(['', '<fg=white;bg=blue> SMTP </>', '']);
        $this->renderKeyValueTable($output, '', [
            'Time' => $date,
        ]);

        $output->writeln('');
        $this->renderKeyValueTable(
            $output,
            'Addresses',
            \array_map(static fn (array $items): string => \implode(', ', $items), $addresses),
        );

        $output->writeln(['', "<fg=white;options=bold>$subject</>", '<fg=gray>---</>', '']);
        $output->write($message->textBody, true, OutputInterface::OUTPUT_RAW);
    }

    private function generateAddresses(Message $message): array
    {
        $addresses = [];
        foreach (['from', 'to', 'cc', 'bcc', 'reply_to'] as $type) {
            if (($users = $this->prepareUsers($message->jsonSerialize(), $type)) !== []) {
                $addresses[$type] = \array_map(static fn (array $items): string => empty($items['name'])
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
