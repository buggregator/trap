<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Server\Traffic\Parser;

use Buggregator\Trap\Server\Traffic\Parser\MultipartType;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

#[Covers(MultipartType::class)]
final class MultipartTypeTest
{
    #[Test]
    #[DataSet(['multipart/mixed; boundary=abc', MultipartType::Mixed])]
    #[DataSet(['multipart/alternative; boundary="x"', MultipartType::Alternative])]
    #[DataSet(['multipart/digest', MultipartType::Digest])]
    #[DataSet(['multipart/parallel', MultipartType::Parallel])]
    #[DataSet(['multipart/form-data; boundary=----x', MultipartType::FormData])]
    #[DataSet(['multipart/report; report-type=delivery-status', MultipartType::Report])]
    #[DataSet(['multipart/signed; protocol="application/pgp-signature"', MultipartType::Signed])]
    #[DataSet(['multipart/encrypted', MultipartType::Encrypted])]
    #[DataSet(['multipart/related; type="text/html"', MultipartType::Related])]
    #[DataSet(['multipart/byteranges; boundary=x', MultipartType::Other], 'unknown subtype')]
    #[DataSet(['text/plain', MultipartType::Other], 'not multipart')]
    #[DataSet(['', MultipartType::Other], 'empty')]
    public function takesTheTypeFromTheHeaderLine(string $headerLine, MultipartType $expected): void
    {
        Assert::same(MultipartType::fromHeaderLine($headerLine), $expected);
    }
}
