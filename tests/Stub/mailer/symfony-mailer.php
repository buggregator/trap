<?php

/**
 * Sends mail with Symfony Mailer from `tools/mailer`, for the acceptance tests.
 *
 * Usage: php symfony-mailer.php <dsn> < mails.json
 * The input is a list of mails, sent one after another over one transport. A mail holds `from`,
 * `to`, `cc`, `bcc`, `subject`, `text`, `html` and `attachments` as a list of `{name, content, type}`.
 * Prints `{"sent": int, "error": ?string, "debug": string}`: the number of mails sent, the error
 * that stopped sending and the SMTP transcript.
 */

declare(strict_types=1);

use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;

require \dirname(__DIR__, 3) . '/tools/mailer/vendor/autoload.php';

/** @var list<array{from: string, to?: list<string>, cc?: list<string>, bcc?: list<string>, subject: string, text?: string, html?: string, attachments?: list<array{name: string, content: string, type: string}>}> $mails */
$mails = \json_decode((string) \stream_get_contents(\STDIN), true, 512, \JSON_THROW_ON_ERROR);

$result = ['sent' => 0, 'error' => null, 'debug' => ''];
try {
    $transport = Transport::fromDsn($argv[1]);
    foreach ($mails as $mail) {
        $email = (new Email())
            ->from($mail['from'])
            ->to(...$mail['to'] ?? [])
            ->cc(...$mail['cc'] ?? [])
            ->bcc(...$mail['bcc'] ?? [])
            ->subject($mail['subject']);
        isset($mail['text']) and $email->text($mail['text']);
        isset($mail['html']) and $email->html($mail['html']);
        foreach ($mail['attachments'] ?? [] as $attachment) {
            $email->attach($attachment['content'], $attachment['name'], $attachment['type']);
        }

        $result['debug'] .= $transport->send($email)?->getDebug() ?? '';
        ++$result['sent'];
    }
} catch (TransportExceptionInterface $e) {
    $result['error'] = $e::class . ': ' . $e->getMessage();
    $result['debug'] .= $e->getDebug();
}

echo \json_encode($result, \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE);
