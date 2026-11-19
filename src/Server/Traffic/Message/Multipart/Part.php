<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Traffic\Message\Multipart;

use Buggregator\Trap\Server\Traffic\Message\Headers;

/**
 * @internal
 */
abstract class Part implements \JsonSerializable
{
    use Headers;

    /**
     * @param array<array-key, non-empty-list<string>> $headers
     */
    protected function __construct(
        array $headers,
        protected ?string $name,
    ) {
        $this->setHeaders($headers);
    }

    /**
     * @param array<array-key, non-empty-list<non-empty-string>> $headers
     */
    public static function create(array $headers): Part
    {
        $contentDisposition = self::findHeader($headers, 'Content-Disposition')[0] ?? '';

        $name = $fileName = null;

        if ($contentDisposition !== '') {
            // Get field name and file name
            $name = \preg_match('/\bname=(?:(?<a>[^" ;,]++)|"(?<b>[^"]++)")/', $contentDisposition, $matches) === 1
                ? ($matches['a'] ?: $matches['b'])
                : null;

            // Decode file name
            $fileName = \preg_match(
                '/\bfilename=(?:(?<a>[^" ;,]++)|"(?<b>[^"]++)")/',
                $contentDisposition,
                $matches,
            ) === 1 ? ($matches['a'] ?: $matches['b']) : null;
        }

        $fileName = $fileName !== null ? \html_entity_decode($fileName) : null;
        $fileName = self::findExtendedParameter($contentDisposition, 'filename') ?? $fileName;
        $isFile = (string) $fileName !== ''
            || \preg_match('/^\\s*attachment\\b/i', $contentDisposition) === 1
            || \preg_match('/^\\s*text\\//i', self::findHeader($headers, 'Content-Type')[0] ?? 'text/plain') !== 1;

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

    /**
     * @return array{
     *      headers: array<array-key, non-empty-list<string>>,
     *      name?: string
     *  }
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        $data = [
            'headers' => $this->headers,
        ];

        if ($this->name !== null) {
            $data['name'] = $this->name;
        }

        return $data;
    }

    /**
     * Value of a parameter in the `name*=charset'lang'%XX` form or split into `name*0*=`, `name*1*=`...:
     * mail clients write non-ASCII and long file names so.
     *
     * @return non-empty-string|null UTF-8 value, or null if the parameter is absent or empty.
     */
    private static function findExtendedParameter(string $header, string $parameter): ?string
    {
        $count = \preg_match_all(
            '/;\\s*' . \preg_quote($parameter, '/') . '\\*(\\d*+)(\\*?)=(?:"([^"]*+)"|([^\\s;"]*+))/i',
            $header,
            $matches,
            \PREG_SET_ORDER,
        );
        if ($count === 0 || $count === false) {
            return null;
        }

        $charset = 'UTF-8';
        $pieces = [];
        foreach ($matches as $match) {
            $index = (int) $match[1];
            $value = ($match[3] ?? '') . ($match[4] ?? '');
            // `name*=` and `name*0*=` are percent-encoded, `name*0=` is not
            if ($match[1] === '' || $match[2] === '*') {
                if ($index === 0 && \preg_match("/^([^']*+)'[^']*+'(.*)$/s", $value, $prefix) === 1) {
                    $prefix[1] === '' or $charset = $prefix[1];
                    $value = $prefix[2];
                }
                $value = \rawurldecode($value);
            }
            $pieces[$index] = $value;
        }
        \ksort($pieces);
        $result = \implode('', $pieces);

        if (\strcasecmp($charset, 'UTF-8') !== 0 && \strcasecmp($charset, 'US-ASCII') !== 0) {
            try {
                $converted = \mb_convert_encoding($result, 'UTF-8', $charset);
                \is_string($converted) and $result = $converted;
            } catch (\ValueError) {
                // Unknown charset: keep the bytes
            }
        }

        return $result === '' ? null : $result;
    }
}
