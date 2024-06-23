<?php

declare(strict_types=1);

namespace Buggregator\Trap\Traffic\Parser;

use Buggregator\Trap\Traffic\StreamClient;
use Buggregator\Trap\Support\StreamHelper;
use Buggregator\Trap\Traffic\Message;
use Buggregator\Trap\Traffic\Message\Multipart\Field;
use Buggregator\Trap\Traffic\Message\Multipart\File;
use Psr\Http\Message\StreamInterface;

/**
 * Todo: parse and decrypt `Content-Transfer-Encoding: base64`, `Content-Transfer-Encoding: 7bit`
 * @internal
 */
final class Smtp
{
    /**
     * @param array<non-empty-string, list<string>> $protocol
     */
    public function parseStream(array $protocol, StreamClient $stream): Message\Smtp
    {
        $headerBlock = Http::getBlock($stream);
        $headers = Http::parseHeaders($headerBlock);
        $fileStream = StreamHelper::createFileStream();
        // Store read headers to the file stream.
        $fileStream->write($headerBlock . "\r\n\r\n");

        // Create message with headers only.
        $message = Message\Smtp::create($protocol, headers: $headers);

        // Defaults
        $endOfStream = ["\r\n.\r\n"];
        $isMultipart = false;

        // Check the message is multipart.
        $contentType = $message->getHeaderLine('Content-Type');
        if (\str_contains($contentType, 'multipart/')
            && \preg_match('/boundary="?([^"\\s;]++)"?/', $contentType, $matches) === 1
        ) {
            $isMultipart = true;
            $endOfStream = ["\r\n--{$matches[1]}--\r\n\r\n"];
            $endOfStream[] = $endOfStream[0] . ".\r\n";
        }

        $stored = $this->storeBody($fileStream, $stream, $endOfStream);
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
     * @param non-empty-array<non-empty-string> $endings
     *
     * @return int<0, max> Number of bytes written to the stream.
     */
    private function storeBody(
        StreamInterface $fileStream,
        StreamClient $stream,
        array $endings = ["\r\n.\r\n"],
    ): int {
        $written = 0;
        $endLen = \min(\array_map('\strlen', $endings));

        /** @var string $chunk */
        foreach ($stream->getIterator() as $chunk) {
            // Write chunk to the file stream.
            $fileStream->write($chunk);
            $written += \strlen($chunk);

            // Check the end of the message.
            if (!$stream->hasData()) {
                if (\strlen($chunk) < $endLen) {
                    $fileStream->seek(-$endLen, \SEEK_CUR);
                    $chunk = $fileStream->read($endLen);
                }

                foreach ($endings as $end) {
                    if (\str_ends_with($chunk, $end)) {
                        return $written;
                    }
                }
            }

            unset($chunk);
            \Fiber::suspend();
        }

        return $written;
    }

    private function processSingleBody(Message\Smtp $message, StreamInterface $stream): Message\Smtp
    {
        $content = \preg_replace(["/^\.([^\r])/m", "/(\r\n\\.\r\n)$/D"], ['$1', ''], $stream->getContents());

        /** @psalm-suppress InvalidArgument */
        $body = new Field(
            headers: \array_intersect_key($message->getHeaders(), ['Content-Type' => true]),
            value: $content,
        );

        return $message->withMessages([$body]);
    }

    private function processMultipartForm(Message\Smtp $message, StreamInterface $stream): Message\Smtp
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
