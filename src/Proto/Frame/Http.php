<?php

declare(strict_types=1);

namespace Buggregator\Trap\Proto\Frame;

use Buggregator\Trap\Proto\FilesCarrier;
use Buggregator\Trap\Proto\Frame;
use Buggregator\Trap\ProtoType;
use DateTimeImmutable;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\UploadedFile;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @internal
 * @psalm-internal Buggregator
 */
final class Http extends Frame implements FilesCarrier
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
            'uploadedFiles' => $this->request->getUploadedFiles(),
        ], JSON_THROW_ON_ERROR);
    }

    public static function fromString(string $payload, DateTimeImmutable $time): Frame
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

    public function getSize(): int
    {
        return $this->request->getBody()->getSize() + \array_reduce(
                $this->request->getUploadedFiles(),
                static fn(int $carry, array $file): int => $carry + $file['size'],
                0
            );
    }

    public function hasFiles(): bool
    {
        return \count($this->request->getUploadedFiles()) > 0;
    }

    public function getFiles(): array
    {
        return $this->request->getUploadedFiles();
    }
}
