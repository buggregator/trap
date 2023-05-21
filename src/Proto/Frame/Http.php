<?php

declare(strict_types=1);

namespace Buggregator\Client\Proto\Frame;

use Buggregator\Client\Proto\Frame;
use Buggregator\Client\ProtoType;
use DateTimeImmutable;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\UploadedFile;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

final class Http extends Frame
{
    public function __construct(
        public readonly ServerRequestInterface $request,
        DateTimeImmutable $time = new DateTimeImmutable(),
    ) {
        parent::__construct(type: ProtoType::HTTP, time: $time);
    }

    /**
     * @throws \JsonException
     */
    public function __toString(): string
    {
        return \json_encode([
            'headers' => $this->request->getHeaders(),
            'method' => $this->request->getMethod(),
            'uri' => (string)$this->request->getUri(),
            'body' => (string)$this->request->getBody(),
            'serverParams' => $this->request->getServerParams(),
            'cookies' => $this->request->getCookieParams(),
            'queryParams' => $this->request->getQueryParams(),
            'protocolVersion' => $this->request->getProtocolVersion(),
            'uploadedFiles' => \array_map(
                static fn(UploadedFileInterface $file) => [
                    'clientFilename' => $file->getClientFilename(),
                    'clientMediaType' => $file->getClientMediaType(),
                    'error' => $file->getError(),
                    'size' => $file->getSize(),
                    'content' => (string)$file->getStream(),
                ],
                $this->request->getUploadedFiles()
            ),
        ], JSON_THROW_ON_ERROR);
    }

    static public function fromString(string $payload, DateTimeImmutable $time): Frame
    {
        $payload = \json_decode($payload, true, \JSON_THROW_ON_ERROR);

        $request = new ServerRequest(
            $payload['method'] ?? 'GET',
            $payload['uri'] ?? '/',
            (array)$payload['headers'] ?? [],
            $payload['body'] ?? '',
            $payload['protocolVersion'] ?? '1.1',
            $payload['serverParams'] ?? [],
        );

        return new self(
            $request->withQueryParams($payload['queryParams'] ?? [])
                ->withCookieParams($payload['cookies'] ?? [])
                ->withUploadedFiles(
                    \array_map(
                        static fn(array $file) => new UploadedFile(
                            $file['content'],
                            $file['size'],
                            \UPLOAD_ERR_OK,
                            $file['clientFilename'],
                            $file['clientMediaType'],
                        ),
                        $payload['uploadedFiles'] ?? []
                    )
                ),
            $time
        );
    }
}
