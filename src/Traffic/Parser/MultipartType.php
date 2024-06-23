<?php

declare(strict_types=1);

namespace Buggregator\Trap\Traffic\Parser;

/**
 * @internal
 */
enum MultipartType: string
{
    case Mixed = 'mixed';
    case Alternative = 'alternative';
    case Digest = 'digest';
    case Parallel = 'parallel';
    case FormData = 'form-data';
    case Report = 'report';
    case Signed = 'signed';
    case Encrypted = 'encrypted';
    case Related = 'related';
    case Other = 'other';

    public static function fromHeaderLine(string $headerLine): self
    {
        $type = \explode(';', $headerLine, 2)[0];
        return match ($type) {
            'multipart/mixed' => self::Mixed,
            'multipart/alternative' => self::Alternative,
            'multipart/digest' => self::Digest,
            'multipart/parallel' => self::Parallel,
            'multipart/form-data' => self::FormData,
            'multipart/report' => self::Report,
            'multipart/signed' => self::Signed,
            'multipart/encrypted' => self::Encrypted,
            'multipart/related' => self::Related,
            default => self::Other,
        };
    }
}
