<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Mailer\Console;

use Buggregator\Trap\Module\Console\Renderer as RendererInterface;
use Buggregator\Trap\Module\Console\Support\Common;
use Buggregator\Trap\Module\Console\Support\Files;
use Buggregator\Trap\Module\Mailer\Alternative;
use Buggregator\Trap\Module\Mailer\Calendar;
use Buggregator\Trap\Module\Mailer\EncodedWord;
use Buggregator\Trap\Module\Mailer\Frame as MailerFrame;
use Buggregator\Trap\Server\Proto\Frame;
use Buggregator\Trap\Support\Measure;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @implements RendererInterface<MailerFrame>
 * @psalm-import-type Invite from Calendar
 *
 * @internal
 */
final class Renderer implements RendererInterface
{
    #[\Override]
    public function isSupport(Frame $frame): bool
    {
        return $frame instanceof MailerFrame;
    }

    #[\Override]
    public function render(OutputInterface $output, Frame $frame): void
    {
        \assert($frame instanceof MailerFrame);

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
        Common::renderHeaders($output, \array_map(
            static fn(array $values): array => \array_map(EncodedWord::decode(...), $values),
            $message->getHeaders(),
        ));

        [$calendar, $calendarSource] = $message->getInvite() ?? [null, null];
        $alternatives = $message->getAlternatives();

        // Text body; the invite section below stands for the calendar version it is read from
        $i = 0;
        foreach ($message->getMessages() as $text) {
            if ($text === $calendarSource) {
                continue;
            }

            $type = Alternative::mediaType($text->getHeaderLine('Content-Type'));
            Common::renderHeader3(
                $output,
                \sprintf('Body %s', ++$i > 1 ? $i : ''),
                ...(\in_array($text, $alternatives, true)
                    ? [OutputFormatter::escape(Alternative::LABELS[$type] ?? $type)]
                    : []),
            );
            if (\count($message->getMessages()) > 1) {
                Common::renderHeaders($output, $text->getHeaders());
                $output->writeln('');
            }
            $output->write($text->getValue(), true, OutputInterface::OUTPUT_NORMAL);
        }

        $calendar === null or self::renderInvite($output, $calendar);

        /** @var list<\Buggregator\Trap\Server\Traffic\Message\Multipart\File> $attachments */
        /** @var list<\Buggregator\Trap\Server\Traffic\Message\Multipart\File> $embeddings */
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

            \Buggregator\Trap\Module\Console\Support\Tables::renderMultiColumnTable(
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

    /**
     * The invite as written: times in the zone of the sender.
     *
     * @param Invite $invite
     */
    private static function renderInvite(OutputInterface $output, array $invite): void
    {
        Common::renderHeader3($output, 'Invite', Calendar::state($invite));

        $end = $invite['end'] ?? '';
        if (isset($invite['all_day'], $invite['start']) && $end !== '') {
            // The end date of an all-day event is exclusive
            $last = \DateTimeImmutable::createFromFormat('!Y-m-d', $end);
            $end = $last === false ? $end : $last->modify('-1 day')->format('Y-m-d');
            $end > $invite['start'] or $end = '';
        }
        $when = ($invite['start'] ?? '') . ($end === '' ? '' : " – $end");
        $when === '' or $when .= isset($invite['all_day']) ? ', all day' : (isset($invite['tz']) ? " ({$invite['tz']})" : '');
        $guests = \array_map(
            static fn(array $guest): string => \trim(self::person($guest) . (isset($guest['status']) ? ": {$guest['status']}" : '')),
            $invite['attendees'] ?? [],
        );

        // Lists: the console escapes their items, which come from the email
        Common::renderMetadata($output, \array_filter([
            'Summary' => [$invite['summary']],
            'When' => $when === '' ? [] : [$when],
            'Where' => isset($invite['location']) ? [$invite['location']] : [],
            'Organizer' => isset($invite['organizer']) ? [self::person($invite['organizer'])] : [],
            'Guests' => $guests,
            'Repeats' => isset($invite['rrule']) ? [$invite['rrule']] : [],
        ]));
        $output->writeln('');
    }

    /**
     * @param array{name?: string, email?: string, status?: string, role?: string} $person
     */
    private static function person(array $person): string
    {
        return isset($person['name'], $person['email']) && $person['name'] !== $person['email']
            ? "{$person['name']} <{$person['email']}>"
            : ($person['email'] ?? $person['name'] ?? '');
    }
}
