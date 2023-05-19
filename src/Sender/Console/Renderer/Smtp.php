<?php

declare(strict_types=1);

namespace Buggregator\Client\Sender\Console\Renderer;

use Buggregator\Client\Proto\Frame;
use Buggregator\Client\ProtoType;
use Buggregator\Client\Sender\Console\RendererInterface;
use Buggregator\Client\Traffic\Smtp\Message;
use Buggregator\Client\Traffic\Smtp\Parser;
use Symfony\Component\Console\Output\OutputInterface;
use Termwind\HtmlRenderer;

final class Smtp implements RendererInterface
{
    private readonly Parser $parser;

    public function __construct(
        private readonly HtmlRenderer $renderer,
    ) {
        $this->parser = new Parser();
    }

    public function isSupport(Frame $frame): bool
    {
        return $frame->type === ProtoType::SMTP;
    }

    /**
     * @param Frame\Smtp $frame
     */
    public function render(OutputInterface $output, Frame $frame): void
    {
        $message = $this->parser->parse($frame->message);
        $date = $frame->time->format('Y-m-d H:i:s.u');
        $subject = $message->subject;

        $addresses = $this->generateAddresses($message);

        $body = \htmlspecialchars(\trim($message->textBody));


        $this->renderer->render(
            <<<HTML
            <div class="mt-2">
                <table>
                    <tr>
                        <th>date</th>
                        <td>$date</td>
                    </tr>
                </table>

                <h1 class="font-bold bg-blue text-white my-1 px-1">SMTP</h1>
                <h2 class="font-bold mb-1">$subject</h2>

                <div class="mb-1">$addresses</div>
                <code>$body</code>
            </div>
            HTML
            ,
            0
        );
    }

    private function generateAddresses(Message $message): string
    {
        $addresses = [];
        foreach (['from', 'to', 'cc', 'bcc', 'reply_to'] as $type) {
            if (($users = $this->prepareUsers($message->jsonSerialize(), $type)) !== []) {
                $addresses[$type] = $users;
            }
        }

        if ($addresses === []) {
            return '';
        }

        $html = '<table>';
        $html .= '<thead title="Addresses"></thead>';

        $m = 1;
        foreach ($addresses as $type => $users) {
            $lastRow = ($m === count($addresses));
            $html .= '<tbody><tr><th colspan="2">' . $type . '</th></tr>';

            $i = 1;
            foreach ($users as $user) {
                $last = ($i === count($users));
                $html .= '<tr ' . ($last && !$lastRow ? 'border="1"' : '') . '><th>' . $i++ . '.</th><td>';

                if (!empty($user['name'])) {
                    $html .= $user['name'] . ' [' . $user['email'] . ']';
                } else {
                    $html .= $user['email'];
                }

                $html .= '</td></tr>';
            }

            $m++;
            $html .= '</tbody>';
        }

        $html .= '</table>';

        return $html;
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
