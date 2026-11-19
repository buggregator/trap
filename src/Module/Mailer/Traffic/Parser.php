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

    /** Charsets of a text already in UTF-8 */
    private const NO_CONVERSION = ['', 'utf-8', 'utf8', 'us-ascii', 'ascii'];

    /** Names mbstring takes for encodings that are not charsets: base64 decoding, HTML unescaping and alike */
    private const NOT_CHARSETS = [
        'base64' => true,
        'uuencode' => true,
        'html-entities' => true,
        'html' => true,
        'quoted-printable' => true,
        'qprint' => true,
        '7bit' => true,
        '8bit' => true,
        'binary' => true,
        'pass' => true,
        'wchar' => true,
        'auto' => true,
    ];

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

    private static function isBodyText(Field $part): bool
    {
        // A part without a Content-Type is text/plain
        $type = $part->getHeaderLine('Content-Type');
        return $type === '' || \preg_match('/^\\s*text\\/(?:plain|html|watch-html)\\b/i', $type) === 1;
    }

    /**
     * Decodes a message body by its `Content-Transfer-Encoding` and converts it to UTF-8 from its charset.
     *
     * The headers of the result describe the decoded value: the decoded transfer encoding is dropped
     * and a converted charset becomes UTF-8, so a consumer of the stored message doesn't decode it twice.
     */
    private static function decodeText(Field $part): Field
    {
        $value = self::decodeTransfer($part->getValue(), $part->getHeaderLine('Content-Transfer-Encoding'));
        $value === null or $part = $part->withValue($value)->withoutHeader('Content-Transfer-Encoding');

        $contentType = $part->getHeaderLine('Content-Type');
        $charset = self::findCharset($contentType);
        $converted = $charset === null ? null : self::toUtf8($part->getValue(), $charset[0]);
        if ($charset === null || $converted === null) {
            return $part;
        }

        return $part->withValue($converted)->withHeader(
            'Content-Type',
            \substr_replace($contentType, '; charset=utf-8', $charset[1], $charset[2]),
        );
    }

    /**
     * Finds the `charset` parameter of a Content-Type, stepping over the quoted values of other parameters.
     *
     * @return array{string, int<0, max>, int<0, max>}|null The charset unquoted, the offset and the length
     *         of the whole parameter with its leading `;`.
     */
    private static function findCharset(string $contentType): ?array
    {
        \preg_match_all(
            '/;\\s*+([^\\s=;"]++)\\s*+=\\s*+("(?:[^"\\\\]|\\\\.)*+"|[^;"]*+)/',
            $contentType,
            $params,
            \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE,
        );

        foreach ($params as $param) {
            if (\strcasecmp($param[1][0], 'charset') !== 0) {
                continue;
            }

            $value = \trim(\rtrim($param[2][0]), "\"'");
            $length = \strlen(\rtrim($param[0][0]));
            /** @var int<0, max> $offset */
            $offset = $param[0][1];
            return [$value, $offset, $length];
        }

        return null;
    }

    /**
     * @return string|null The decoded value, or null if the encoding leaves the value as is:
     *         `7bit`, `8bit`, `binary` or unknown.
     */
    private static function decodeTransfer(string $value, string $encoding): ?string
    {
        return match (\strtolower(\trim($encoding))) {
            'base64' => \base64_decode($value),
            'quoted-printable' => \quoted_printable_decode($value),
            default => null,
        };
    }

    /**
     * Tries mbstring, then iconv: each knows charsets the other lacks, as mbstring lacks windows-1250.
     * Without both, a text in another charset keeps its bytes.
     *
     * @return string|null The converted value, or null if it is UTF-8 already or the charset is unknown.
     */
    private static function toUtf8(string $value, string $charset): ?string
    {
        $charset = \strtolower($charset);
        // mbstring takes a comma-separated list for detecting the charset
        if (\in_array($charset, self::NO_CONVERSION, true)
            || isset(self::NOT_CHARSETS[$charset])
            || \str_contains($charset, ',')
        ) {
            return null;
        }

        // A charset unknown to a converter warns or throws: either means trying the next one
        \set_error_handler(static fn(): bool => true);
        try {
            try {
                $converted = \function_exists('mb_convert_encoding')
                    ? \mb_convert_encoding($value, 'UTF-8', $charset)
                    : false;
            } catch (\ValueError) {
                $converted = false;
            }

            if (!\is_string($converted) && \function_exists('iconv')) {
                $converted = \iconv($charset, 'UTF-8', $value);
                // Not //IGNORE first: glibc's iconv returns false with it whenever it drops bytes
                \is_string($converted) or $converted = \iconv($charset, 'UTF-8//IGNORE', $value);
            }
        } finally {
            \restore_error_handler();
        }

        return \is_string($converted) ? $converted : null;
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
                ['Content-Type' => true, 'Content-Transfer-Encoding' => true],
            ),
            value: $content,
        );

        return $message->withMessages([self::isBodyText($body) ? self::decodeText($body) : $body]);
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
                    ? $texts[] = self::decodeText($part)
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
     * A text part that is not a message body, like a `text/calendar` invitation.
     */
    private function textToFile(Field $part): File
    {
        $contentType = $part->getHeaderLine('Content-Type');
        $fileName = \preg_match('/;\\s*name=(?:"([^"]++)"|([^"\\s;]++))/i', $contentType, $matches) === 1
            ? ($matches[1] ?: $matches[2])
            : match (\strtolower(\trim(\explode(';', $contentType, 2)[0]))) {
                'text/calendar' => 'invite.ics',
                'text/csv' => 'attachment.csv',
                default => 'attachment.txt',
            };

        $value = self::decodeTransfer($part->getValue(), $part->getHeaderLine('Content-Transfer-Encoding'))
            ?? $part->getValue();

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
