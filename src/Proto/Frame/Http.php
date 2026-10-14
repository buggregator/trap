<?php

declare(strict_types=1);

namespace Buggregator\Trap\Proto\Frame;

use Buggregator\Trap\Proto\FilesCarrier;
use Buggregator\Trap\Proto\Frame;
use Buggregator\Trap\ProtoType;
use Buggregator\Trap\Support\Json;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface as PsrUploadedFile;

/**
 * @internal
 * @psalm-internal Buggregator
 */
final class Http extends Frame implements FilesCarrier, \Buggregator\Trap\Proto\StreamCarrier
{
    /** @var int<0, max> */
    private readonly int $cachedSize;

    public function __construct(
        public readonly ServerRequestInterface $request,
        \DateTimeImmutable $time = new \DateTimeImmutable(),
    ) {
        $this->cachedSize = \max(0, (int) $request->getBody()->getSize() + \array_reduce(
            \iterator_to_array($this->iterateUploadedFiles(), false),
            static fn(int $carry, PsrUploadedFile $file): int => $carry + (int) $file->getSize(),
            0,
        ));
        parent::__construct(type: ProtoType::HTTP, time: $time);
    }

    #[\Override]
    public static function fromString(string $payload, \DateTimeImmutable $time): static
    {
        /**
         * @var array{
         *     method?: string,
         *     uri?: string,
         *     headers?: array<string, list<string>>,
         *     body?: string,
         *     protocolVersion?: string,
         *     serverParams?: array,
         *     queryParams?: array,
         *     cookies?: array,
         *     uploadedFiles?: array,
         * } $payload
         */
        $payload = \json_decode($payload, true, 64, \JSON_THROW_ON_ERROR);

        $request = new ServerRequest(
            $payload['method'] ?? 'GET',
            $payload['uri'] ?? '/',
            (array) ($payload['headers'] ?? []),
            $payload['body'] ?? '',
            $payload['protocolVersion'] ?? '1.1',
            $payload['serverParams'] ?? [],
        );

        return new self(
            $request->withQueryParams($payload['queryParams'] ?? [])
                ->withCookieParams($payload['cookies'] ?? [])
                ->withUploadedFiles(self::decodeFiles($payload['uploadedFiles'] ?? [])),
            $time,
        );
    }

    #[\Override]
    public function getSize(): int
    {
        return $this->cachedSize;
    }

    #[\Override]
    public function hasFiles(): bool
    {
        return \count($this->request->getUploadedFiles()) > 0;
    }

    /**
     * @psalm-suppress MixedReturnTypeCoercion Bug #233: PSR-7 uploaded files may be nested arrays, but a flat list is declared
     */
    #[\Override]
    public function getFiles(): array
    {
        return $this->request->getUploadedFiles();
    }

    /**
     * @return \Iterator<int, PsrUploadedFile>
     */
    public function iterateUploadedFiles(): \Iterator
    {
        /** @var \Closure(array): \Iterator<int, PsrUploadedFile> $generator */
        $generator = static function (array $files) use (&$generator): \Generator {
            /** @var PsrUploadedFile|array<PsrUploadedFile> $file */
            foreach ($files as $file) {
                if (\is_array($file)) {
                    /** @var PsrUploadedFile $subFile */
                    foreach ($generator($file) as $subFile) {
                        yield $subFile;
                    }
                    continue;
                }

                \assert($file instanceof PsrUploadedFile);
                yield $file;
            }
        };

        return $generator($this->request->getUploadedFiles());
    }

    #[\Override]
    public function getStream(): StreamInterface
    {
        return $this->request->getBody();
    }

    /**
     * @throws \JsonException
     */
    public function __toString(): string
    {
        return Json::encode([
            'headers' => $this->request->getHeaders(),
            'method' => $this->request->getMethod(),
            'uri' => (string) $this->request->getUri(),
            'body' => (string) $this->request->getBody(),
            'serverParams' => $this->request->getServerParams(),
            'cookies' => $this->request->getCookieParams(),
            'queryParams' => $this->request->getQueryParams(),
            'protocolVersion' => $this->request->getProtocolVersion(),
            'uploadedFiles' => self::encodeFiles($this->request->getUploadedFiles()),
        ]);
    }

    /**
     * Keeps the nesting of PSR-7 uploaded files and replaces each file with its data.
     */
    private static function encodeFiles(array $files): array
    {
        $result = [];
        /** @var mixed $file */
        foreach ($files as $key => $file) {
            if (\is_array($file)) {
                $result[$key] = self::encodeFiles($file);
            } elseif ($file instanceof PsrUploadedFile) {
                $error = $file->getError();
                $result[$key] = [
                    'contents' => $error === \UPLOAD_ERR_OK ? \base64_encode((string) $file->getStream()) : null,
                    'size' => $file->getSize(),
                    'error' => $error,
                    'clientFilename' => $file->getClientFilename(),
                    'clientMediaType' => $file->getClientMediaType(),
                ];
            }
        }

        return $result;
    }

    /**
     * A file is a node with a scalar `contents` key; any other array is a level of nesting.
     * Nodes without file data, like the empty objects older versions wrote for each file, are dropped.
     */
    private static function decodeFiles(array $nodes): array
    {
        $result = [];
        /** @var mixed $node */
        foreach ($nodes as $key => $node) {
            if (!\is_array($node)) {
                continue;
            }

            if (\array_key_exists('contents', $node) && !\is_array($node['contents'])) {
                $contents = \base64_decode(\is_string($node['contents']) ? $node['contents'] : '');
                $result[$key] = new UploadedFile(
                    Stream::create($contents),
                    isset($node['size']) && \is_int($node['size']) ? $node['size'] : \strlen($contents),
                    isset($node['error']) && \is_int($node['error']) ? $node['error'] : \UPLOAD_ERR_OK,
                    isset($node['clientFilename']) && \is_string($node['clientFilename']) ? $node['clientFilename'] : null,
                    isset($node['clientMediaType']) && \is_string($node['clientMediaType']) ? $node['clientMediaType'] : null,
                );
                continue;
            }

            $nested = self::decodeFiles($node);
            $nested === [] or $result[$key] = $nested;
        }

        return $result;
    }
}
