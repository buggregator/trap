<?php

declare(strict_types=1);

namespace Buggregator\Client\Proto\Frame;

use Buggregator\Client\Proto\Frame;
use Buggregator\Client\ProtoType;
use DateTimeImmutable;
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
            'parsedBody' => $this->request->getParsedBody(),
            'attributes' => $this->request->getAttributes(),
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
}
