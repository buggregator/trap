<?php

declare(strict_types=1);

namespace Buggregator\Client\Traffic\Smtp;

use Buggregator\Client\Socket\StreamClient;
use Buggregator\Client\Support\StreamHelper;
use Buggregator\Client\Traffic\Http;
use Buggregator\Client\Traffic\Multipart\Field;
use Buggregator\Client\Traffic\Multipart\File;
use Psr\Http\Message\StreamInterface;

/**
 * Todo: parse and decrypt `Content-Transfer-Encoding: base64`, `Content-Transfer-Encoding: 7bit`
 */
final class Parser
{
    public function parseStream(StreamClient $stream): Message
    {
        $headerBlock = Http\Parser::getBlock($stream);
        $headers = Http\Parser::parseHeaders($headerBlock);
        $fileStream = StreamHelper::createFileStream();
        // Store read headers to the file stream.
        $fileStream->write($headerBlock . "\r\n\r\n");

        // Create message with headers only.
        $message = Message::create(headers: $headers);

        // Defaults
        $boundary = "\r\n.\r\n";
        $isMultipart = false;

        // Check the message is multipart.
        $contentType = $message->getHeaderLine('Content-Type');
        if (\str_contains($contentType, 'multipart/')
            && \preg_match('/boundary="?([^"\\s;]++)"?/', $contentType, $matches) === 1
        ) {
            $isMultipart = true;
            $boundary = "\r\n--{$matches[1]}--\r\n\r\n";
        }

        $stored = $this->storeBody($fileStream, $stream, $boundary);
        $message = $message->withBody($fileStream);
        // Message's body must be seeked to the beginning of the body.
        $fileStream->seek(-$stored, \SEEK_CUR);

        $message = $isMultipart
            ? $this->processMultipartForm($message, $fileStream)
            : $this->processSingleBody($message, $fileStream);



        return $message;
    }

    /**
     * Flush stream data into PSR stream.
     * Note: there can be read more data than {@see $limit} bytes but write only {@see $limit} bytes.
     *
     * @return int Number of bytes written to the stream.
     */
    private function storeBody(
        StreamInterface $fileStream,
        StreamClient $stream,
        string $end = "\r\n.\r\n",
    ): int {
        $written = 0;

        foreach ($stream->getIterator() as $chunk) {
            // Check trailed double \r\n
            if (!$stream->hasData() && \str_ends_with($chunk, $end)) {
                // Remove transparent dot and write to stream
                $fileStream->write(\substr($chunk, 0, -5));
                return $written + \strlen($chunk) - 5;
            }

            // Remove transparent dot
            $fileStream->write($chunk);
            $written += \strlen($chunk);
            unset($chunk);
            \Fiber::suspend();
        }

        return $written;
    }

    private function processSingleBody(Message $message, StreamInterface $stream): Message
    {
        $content = \preg_replace("/^\.([^\r])/m", '$1', $stream->getContents());

        $body = new Field(
            headers: \array_intersect_key($message->getHeaders(), ['Content-Type' => true]),
            value: $content,
        );

        return $message->withTexts([$body]);
    }

    private function processMultipartForm(Message $message, StreamInterface $stream): Message
    {
        if (\preg_match('/boundary="?([^"\\s;]++)"?/', $message->getHeaderLine('Content-Type'), $matches) !== 1) {
            return $message;
        }

        $boundary = $matches[1];
        $parts = Http\Parser::parseMultipartBody($stream, $boundary);
        $attaches = $texts = [];
        foreach ($parts as $part) {
            if ($part instanceof Field) {
                $texts[] = $part;
            } elseif ($part instanceof File) {
                $attaches[] = $part;
            }
        }

        return $message->withTexts($texts)->withAttaches($attaches);
    }
}
