<?php

declare(strict_types=1);

namespace Buggregator\Client\Traffic\Multipart;

use RuntimeException;

abstract class Part
{
    use Headers;

    protected function __construct(
        array $headers,
        protected ?string $name,
    ) {
        $this->setHeaders($headers);
    }

    public static function create(array $headers): self
    {
        /**
         * Check Content-Disposition header
         *
         * @var string $contentDisposition
         */
        $contentDisposition = $headers['content-disposition'][0]
            ?? throw new RuntimeException('Missing Content-Disposition header.');

        // Get field name and file name
        $name = \preg_match('/\bname=(?:(?<a>[^" ;,]++)|"(?<b>[^"]++)")/', $contentDisposition, $matches) === 1
            ? ($matches['a'] ?: $matches['b'])
            : null;
        // Decode file name
        $fileName = \preg_match('/\bfilename=(?:(?<a>[^" ;,]++)|"(?<b>[^"]++)")/', $contentDisposition, $matches) === 1
            ? ($matches['a'] ?: $matches['b'])
            : null;
        $fileName = $fileName !== null ? \html_entity_decode($fileName) : null;
        $isFile = (string)$fileName !== ''
            || \preg_match('/text\\/.++/', $headers['content-type'][0] ?? 'text/plain') !== 1;

        return match ($isFile) {
            true => new File($headers, $name, $fileName),
            false => new Field($headers, $name),
        };
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function withName(?string $name): static
    {
        $clone = clone $this;
        $clone->name = $name;
        return $clone;
    }

    abstract public function isFile(): bool;
}
