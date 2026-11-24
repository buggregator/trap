<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Mailer\Traffic;

use Buggregator\Trap\Module\Mailer\Alternative;
use Buggregator\Trap\Module\Mailer\EncodedWord;
use Buggregator\Trap\Module\Mailer\Message;
use Buggregator\Trap\Server\Traffic\Message\Multipart\Field;
use Buggregator\Trap\Server\Traffic\Message\Multipart\File;
use Buggregator\Trap\Server\Traffic\Message\Multipart\Part;
use Buggregator\Trap\Server\Traffic\Parser\Http;
use Buggregator\Trap\Server\Traffic\StreamClient;
use Buggregator\Trap\Support\Charset;
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

        $message = \stripos($message->getHeaderLine('Content-Type'), 'multipart/') !== false
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
     * A message body decoded from its transfer encoding and converted to UTF-8 from its charset.
     */
    private static function decodeText(Field $part): string
    {
        return ContentType::toUtf8(
            self::decodeTransfer($part->getValue(), $part->getHeaderLine('Content-Transfer-Encoding')),
            $part->getHeaderLine('Content-Type'),
        );
    }

    /**
     * Value of a header parameter, unquoted, with `\"` and `\\` unescaped.
     *
     * Mailers write Windows paths with bare backslashes, as `"C:\dir\"`, so any other backslash stays,
     * and a quoted value that doesn't close after its escapes is taken as is up to the next quote.
     *
     * @return non-empty-string|null Null if the parameter is absent or empty.
     */
    private static function findParameter(string $header, string $parameter): ?string
    {
        $value = ContentType::parameter($header, $parameter);
        if ($value !== null && \str_starts_with($value, '"')) {
            $value = \preg_match('/^"((?:[^"\\\\]|\\\\.)*+)"$/s', $value, $matches) === 1
                ? (string) \preg_replace('/\\\\([\\\\"])/', '$1', $matches[1])
                : \rtrim(\substr($value, 1), '"');
        }

        return $value === null || $value === '' ? null : $value;
    }

    /**
     * The text and the HTML of the message, or another version of its body.
     * A part without a Content-Type is `text/plain`. Some mailers name the message text in its Content-Type,
     * so the first plain or HTML text is the body even when named; a named one after it is a file, as `log.txt`.
     *
     * @param list<Field> $texts The texts of the message found before the part.
     */
    private static function isBodyText(Field $part, array $texts = []): bool
    {
        $type = Alternative::mediaType($part->getHeaderLine('Content-Type'));
        if ($type !== 'text/plain' && $type !== 'text/html') {
            return self::isAlternative($part);
        }

        if (self::findFileName($part) === null) {
            return true;
        }

        foreach ($texts as $text) {
            if (Alternative::mediaType($text->getHeaderLine('Content-Type')) === $type) {
                return false;
            }
        }

        return true;
    }

    /**
     * A version of the body: a part of a type {@see Alternative::isAlternativeType()} takes, shown with the message
     * (RFC 2183: an `inline` disposition or none) and not named. A named part is a file, as mail clients list it.
     */
    private static function isAlternative(Part $part): bool
    {
        return Alternative::isAlternativeType(Alternative::mediaType($part->getHeaderLine('Content-Type')))
            && \preg_match('/^\\s*attachment\\b/i', $part->getHeaderLine('Content-Disposition')) !== 1
            && self::findFileName($part) === null;
    }

    /**
     * A version of the body that came as a file, its content already decoded from the transfer encoding.
     */
    private static function fileToText(File $part): Field
    {
        $stream = $part->getStream();
        $stream->rewind();

        /** @psalm-suppress InvalidArgument */
        return new Field(
            $part->getHeaders(),
            $part->getName(),
            ContentType::toUtf8($stream->getContents(), $part->getHeaderLine('Content-Type')),
        );
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
     * The file with the name {@see findFileName()} gives.
     */
    private static function withFileName(File $file): File
    {
        $fileName = self::findFileName($file);
        if ($fileName === $file->getClientFilename()) {
            return $file;
        }

        /** @psalm-suppress InvalidArgument */
        $named = new File($file->getHeaders(), $file->getName(), $fileName);
        $file->hasStream() and $named->setStream($file->getStream(), $file->getSize());
        return $named;
    }

    /**
     * The file name of a mail part: the `filename` of Content-Disposition, else the `name` of Content-Type,
     * as Outlook and older mailers name attachments. Either may be made of RFC 2047 encoded words.
     *
     * The name {@see Part::create()} gives comes after the own reading of `filename`: it reads quoted values
     * as browsers write them in form data, where a backslash is no escape, yet it finds a name in values
     * the own reading rejects.
     *
     * @return non-empty-string|null
     */
    private static function findFileName(Part $part): ?string
    {
        $disposition = $part->getHeaderLine('Content-Disposition');
        $contentType = $part->getHeaderLine('Content-Type');
        $fileName = Part::findExtendedParameter($disposition, 'filename')
            ?? self::findParameter($disposition, 'filename')
            ?? ($part instanceof File && $part->getClientFilename() !== '' ? $part->getClientFilename() : null)
            ?? Part::findExtendedParameter($contentType, 'name')
            ?? self::findParameter($contentType, 'name');

        $fileName = $fileName === null ? '' : EncodedWord::decode($fileName);
        return $fileName === '' ? null : $fileName;
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

        // The body is named and typed as a part of a multipart message would be
        $headers = [];
        foreach (['Content-Type', 'Content-Transfer-Encoding', 'Content-Disposition'] as $name) {
            $value = $message->getHeader($name);
            $value === [] or $headers[$name] = $value;
        }
        /** @psalm-suppress ArgumentTypeCoercion */
        $body = Part::create($headers);

        if ($body instanceof File && self::isAlternative($body)) {
            /** @psalm-suppress InvalidArgument */
            $body = new Field($body->getHeaders(), $body->getName());
        }

        if ($body instanceof File) {
            $body = self::withFileName($body);
            $file = StreamHelper::createFileStream();
            $file->write(self::decodeTransfer($content, $body->getHeaderLine('Content-Transfer-Encoding')));
            $file->rewind();
            $body->setStream($file);
            return $message->withAttachments([$body]);
        }

        \assert($body instanceof Field);
        $body = $body->withValue($content);
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
                self::isBodyText($part, $texts)
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
            if ($nested === null && $part->hasStream() && self::isAlternative($part)) {
                $texts[] = self::fileToText($part);
                continue;
            }

            if ($nested === null) {
                $attachments[] = self::withFileName($part);
                continue;
            }

            $body = $part->getStream();
            $body->rewind();
            $this->collectParts($body, $nested, $depth + 1, $texts, $attachments);
        }
    }

    /**
     * A text part named only in its Content-Type, like `text/csv; name=report.csv`.
     */
    private function textToFile(Field $part): File
    {
        $fileName = self::findFileName($part);
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
