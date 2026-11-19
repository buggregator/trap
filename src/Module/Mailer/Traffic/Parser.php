<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Mailer\Traffic;

use Buggregator\Trap\Module\Mailer\Message;
use Buggregator\Trap\Server\Traffic\Message\Multipart\Field;
use Buggregator\Trap\Server\Traffic\Message\Multipart\File;
use Buggregator\Trap\Server\Traffic\Parser\Http;
use Buggregator\Trap\Server\Traffic\StreamClient;
use Buggregator\Trap\Support\StreamHelper;
use Buggregator\Trap\Support\TimeSlice;
use Psr\Http\Message\StreamInterface;

/**
 * @internal
 */
final class Parser
{
    /** Each nesting level of multipart parts copies the part body into a new temporary stream */
    private const MAX_MULTIPART_DEPTH = 16;

    /**
     * @return array<non-empty-string, list<non-empty-string>>
     */
    public static function parseHeaders(string $headersBlock): array
    {
        $result = [];
        $name = null;
        $value = '';
        foreach (\explode("\r\n", $headersBlock) as $line) {
            // Skip empty lines
            if ($line === '') {
                continue;
            }

            // Append to the previous header
            if ($line[0] === ' ' || $line[0] === "\t") {
                if ($name === null) {
                    continue;
                }

                $value .= $line;
                continue;
            }

            // Store previous header
            $name === null or $value === '' or $result[$name][] = $value;

            // New header
            [$name, $value] = \explode(':', $line, 2) + [1 => ''];
            $name = \trim($name);
            $value = \ltrim($value);
            $name === '' || $value === '' and $name = null;
        }

        // Store last header
        $name === null or $value === '' or $result[$name][] = $value;

        return $result;
    }

    /**
     * @param array<non-empty-string, list<string>> $protocol
     */
    public function parseStream(array $protocol, StreamClient $stream): Message
    {
        [$headerBlock, $ended] = self::readHeaderBlock($stream);
        $headers = self::parseHeaders($headerBlock);
        $fileStream = StreamHelper::createFileStream();
        // Store read headers to the file stream.
        $fileStream->write($headerBlock . "\r\n\r\n");

        // Create message with headers only.
        $message = Message::create($protocol, headers: $headers);

        $stored = $ended ? 0 : $this->storeBody($fileStream, $stream);
        $message = $message->withBody($fileStream);
        // Message's body must be seeked to the beginning of the body.
        $fileStream->seek(-$stored, \SEEK_CUR);

        $message = \str_contains($message->getHeaderLine('Content-Type'), 'multipart/')
            ? $this->processMultipartForm($message, $fileStream)
            : $this->processSingleBody($message, $fileStream);

        return $message;
    }

    /**
     * Reads the header block up to the empty line.
     *
     * @return array{string, bool} The block without the trailing line break, and whether the mail data has
     *         ended with it: a message may have no body, and then no empty line before the end of data.
     */
    private static function readHeaderBlock(StreamClient $stream): array
    {
        $block = '';
        while (!$stream->isFinished()) {
            $line = $stream->fetchLine();
            if ($line === "\r\n" || $line === ".\r\n") {
                return [\rtrim($block, "\r\n"), $line === ".\r\n"];
            }

            $block .= $line;
        }

        return [\rtrim($block, "\r\n"), true];
    }

    /**
     * @param int<0, 2> $prefix Leading bytes of the data that are not a part of the message.
     *
     * @return int<0, max> Number of bytes written to the stream.
     */
    private static function writeData(StreamInterface $stream, string $data, int $prefix): int
    {
        $data = \substr(\str_replace("\r\n.", "\r\n", $data), $prefix);
        $data === '' or $stream->write($data);

        return \strlen($data);
    }

    /**
     * A message body decoded from its transfer encoding and converted to UTF-8 from its charset:
     * the console and the web UI show text as is, and the event JSON takes UTF-8 only.
     * The bytes stay as they are in an unknown charset.
     */
    private static function decodeText(Field $part): string
    {
        $value = self::decodeTransfer($part->getValue(), $part->getHeaderLine('Content-Transfer-Encoding'));
        $contentType = $part->getHeaderLine('Content-Type');
        if (\preg_match('/;\\s*charset=(?:"([^"]*+)"|([^\\s;"]++))/i', $contentType, $matches) !== 1) {
            return $value;
        }

        $charset = $matches[1] ?: $matches[2];
        if ($charset === '' || \strcasecmp($charset, 'UTF-8') === 0 || \strcasecmp($charset, 'US-ASCII') === 0) {
            return $value;
        }

        // Names mail clients write for charsets mbstring knows by another name
        $charset = match (\strtolower($charset)) {
            'ks_c_5601-1987' => 'UHC',
            'iso-8859-8-i' => 'ISO-8859-8',
            default => $charset,
        };

        try {
            $converted = \mb_convert_encoding($value, 'UTF-8', $charset);
        } catch (\ValueError) {
            return $value;
        }

        return \is_string($converted) ? $converted : $value;
    }

    /**
     * A part without a Content-Type is `text/plain`; other text types, like `text/calendar`, are attachments.
     */
    private static function isBodyText(Field $part): bool
    {
        $type = $part->getHeaderLine('Content-Type');
        return $type === '' || \preg_match('/^\\s*text\\/(?:plain|html|watch-html)\\b/i', $type) === 1;
    }

    /**
     * Content decoded from `base64` or `quoted-printable`; `7bit`, `8bit` and `binary` content is not encoded.
     */
    private static function decodeTransfer(string $value, string $encoding): string
    {
        return match (\strtolower(\trim($encoding))) {
            'base64' => \base64_decode($value),
            'quoted-printable' => \quoted_printable_decode($value),
            default => $value,
        };
    }

    /**
     * Writes the mail data into the file stream up to the end-of-data line, a single dot, undoing dot-stuffing.
     *
     * Data the client sends after that line in the same read is dropped: the server doesn't announce
     * the PIPELINING extension, so a client waits for the reply before the next command.
     *
     * @return int<0, max> Number of bytes written to the stream.
     */
    private function storeBody(StreamInterface $fileStream, StreamClient $stream): int
    {
        $written = 0;
        // The line break that ended the header block: the data may start with the end-of-data line.
        $buffer = "\r\n";
        $prefix = 2;
        $slice = new TimeSlice(checkEvery: 1);
        $waited = false;

        /** @var string $chunk */
        foreach ($stream->getIterator() as $chunk) {
            // The stream has suspended the fiber while it waited for the chunk
            $waited and $slice->reset();

            $buffer .= $chunk;
            unset($chunk);

            $end = \strpos($buffer, "\r\n.\r\n");
            if ($end !== false) {
                return $written + self::writeData($fileStream, \substr($buffer, 0, $end + 2), $prefix);
            }

            // Kept for the next chunk: the tail may start the end-of-data line, and a line break a stuffed dot.
            $cut = \max(0, \strlen($buffer) - 4);
            $cut > 0 && $buffer[$cut - 1] === "\r" and --$cut;
            $cut > 1 && \substr($buffer, $cut - 2, 2) === "\r\n" and $cut -= 2;
            if ($cut > 0) {
                $written += self::writeData($fileStream, \substr($buffer, 0, $cut), $prefix);
                $buffer = \substr($buffer, $cut);
                $prefix = 0;
            }

            $waited = !$stream->hasData();
            $waited or $slice->tick();
        }

        // The client has disconnected before the end of data
        return $written + self::writeData($fileStream, $buffer, $prefix);
    }

    private function processSingleBody(Message $message, StreamInterface $stream): Message
    {
        $content = $stream->getContents();
        // The line break before the end-of-data line is not a part of the text
        \str_ends_with($content, "\r\n") and $content = \substr($content, 0, -2);

        /** @psalm-suppress InvalidArgument */
        $body = new Field(
            headers: \array_intersect_key(
                $message->getHeaders(),
                ['Content-Type' => true, 'Content-Transfer-Encoding' => true, 'Content-Disposition' => true],
            ),
            value: $content,
        );

        return self::isBodyText($body)
            ? $message->withMessages([$body->withValue(self::decodeText($body))])
            : $message->withAttachments([$this->textToFile($body)]);
    }

    private function processMultipartForm(Message $message, StreamInterface $stream): Message
    {
        $boundary = $this->findBoundary($message->getHeaderLine('Content-Type'));
        if ($boundary === null) {
            return $message;
        }

        $attachments = $texts = [];
        $this->collectParts($stream, $boundary, 1, $texts, $attachments);

        return $message->withMessages($texts)->withAttachments($attachments);
    }

    /**
     * Collect the leaf parts of a multipart body, descending into nested multipart parts.
     * A part nested deeper than {@see MAX_MULTIPART_DEPTH} stays an attachment.
     *
     * @param non-empty-string $boundary
     * @param int<1, max> $depth Nesting level of the body, 1 for the message body.
     * @param list<Field> $texts
     * @param list<File> $attachments
     */
    private function collectParts(
        StreamInterface $stream,
        string $boundary,
        int $depth,
        array &$texts,
        array &$attachments,
    ): void {
        foreach (Http::parseMultipartBody($stream, $boundary) as $part) {
            if ($part instanceof Field) {
                self::isBodyText($part)
                    ? $texts[] = $part->withValue(self::decodeText($part))
                    : $attachments[] = $this->textToFile($part);
                continue;
            }

            if (!$part instanceof File) {
                continue;
            }

            $nested = $depth < self::MAX_MULTIPART_DEPTH && $part->hasStream()
                ? $this->findBoundary($part->getHeaderLine('Content-Type'))
                : null;
            if ($nested === null) {
                $attachments[] = $part;
                continue;
            }

            $body = $part->getStream();
            $body->rewind();
            $this->collectParts($body, $nested, $depth + 1, $texts, $attachments);
        }
    }

    /**
     * A part that is not a message body: a text part like a `text/calendar` invitation,
     * or the body of a single-part message that is not text.
     */
    private function textToFile(Field $part): File
    {
        $contentType = $part->getHeaderLine('Content-Type');
        $mediaType = \strtolower(\trim(\explode(';', $contentType, 2)[0]));
        $param = '(?:"([^"]++)"|([^"\\s;]++))';
        $fileName = \preg_match("/;\\s*filename={$param}/i", $part->getHeaderLine('Content-Disposition'), $matches) === 1
            || \preg_match("/;\\s*name={$param}/i", $contentType, $matches) === 1
            ? ($matches[1] ?: $matches[2])
            : match ($mediaType) {
                'text/calendar' => 'invite.ics',
                'text/csv' => 'attachment.csv',
                default => \str_starts_with($mediaType, 'text/') ? 'attachment.txt' : 'attachment',
            };

        $value = self::decodeTransfer($part->getValue(), $part->getHeaderLine('Content-Transfer-Encoding'));

        $stream = StreamHelper::createFileStream();
        $stream->write($value);
        $stream->rewind();
        /** @psalm-suppress InvalidArgument */
        $file = new File($part->getHeaders(), $part->getName(), $fileName);
        $file->setStream($stream);

        return $file;
    }

    /**
     * @return non-empty-string|null Boundary of a `multipart/*` content type.
     */
    private function findBoundary(string $contentType): ?string
    {
        if (\preg_match('/^\\s*multipart\\//i', $contentType) !== 1
            || \preg_match('/;\\s*boundary=(?:"([^"]++)"|([^"\\s;]++))/i', $contentType, $matches) !== 1
        ) {
            return null;
        }

        /** @var non-empty-string $boundary */
        $boundary = $matches[1] ?: $matches[2];
        return $boundary;
    }
}
