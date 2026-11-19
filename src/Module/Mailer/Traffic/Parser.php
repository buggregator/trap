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
 * Todo: parse and decrypt `Content-Transfer-Encoding: base64`, `Content-Transfer-Encoding: 7bit`
 * @internal
 */
final class Parser
{
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
            headers: \array_intersect_key($message->getHeaders(), ['Content-Type' => true]),
            value: $content,
        );

        return $message->withMessages([$body]);
    }

    private function processMultipartForm(Message $message, StreamInterface $stream): Message
    {
        if (\preg_match('/boundary="?([^"\\s;]++)"?/', $message->getHeaderLine('Content-Type'), $matches) !== 1) {
            return $message;
        }

        /** @var non-empty-string $boundary */
        $boundary = $matches[1];
        $parts = Http::parseMultipartBody($stream, $boundary);
        $attachments = $texts = [];
        foreach ($parts as $part) {
            if ($part instanceof Field) {
                $texts[] = $part;
            } elseif ($part instanceof File) {
                $attachments[] = $part;
            }
        }

        return $message->withMessages($texts)->withAttachments($attachments);
    }
}
