<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Support;

use Buggregator\Trap\Server\Info;
use Buggregator\Trap\Support\BinaryFormat;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

#[Covers(BinaryFormat::class)]
#[Test]
final class BinaryFormatTest
{
    #[DataSet(["PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n\x00\x00\x12\x04", 'http2'])]
    #[DataSet(["PROXY TCP4 10.0.0.1 10.0.0.2 51000 80\r\n", 'proxy'])]
    #[DataSet(["\x00\x00\x00\x08\x04\xd2\x16\x2f", 'postgres'])]
    #[DataSet(["\x00\x00\x00\x29\x00\x03\x00\x00user\x00app\x00\x00", 'postgres'])]
    #[DataSet(["\x01\x01\x00\x01\x00\x08\x00\x00\x00\x01\x00\x00\x00\x00\x00\x00", 'fastcgi'])]
    #[DataSet(["*1\r\n\$4\r\nPING\r\n", 'redis'])]
    #[DataSet(["SSH-2.0-OpenSSH_9.6\r\n", 'ssh'])]
    #[DataSet(["AMQP\x00\x00\x09\x01", 'amqp'])]
    #[DataSet(["\x1f\x8b\x08\x00\x00\x00\x00\x00", 'gzip'])]
    #[DataSet(["\x28\xb5\x2f\xfd\x04\x00", 'zstd'])]
    #[DataSet(["PK\x03\x04\x14\x00", 'zip'])]
    #[DataSet(["\xff\xd8\xff\xe0\x00\x10JFIF", 'jpeg'])]
    #[DataSet(["%PDF-1.7\n", 'pdf'])]
    #[DataSet(['a:1:{s:3:"foo";i:1;}', 'php-serialize'])]
    #[DataSet(['O:8:"stdClass":0:{}', 'php-serialize'])]
    #[DataSet(["\xac\xed\x00\x05sr", 'java-serialized'])]
    #[DataSet(['{"message": "hello", "level": 200}', 'json'])]
    #[DataSet(["hello world\r\n", 'text'])]
    #[DataSet(["héllo wörld\n", 'text'])]
    #[DataSet(["\x82\xa4name\xa3bob\xa3age\x1e", 'msgpack'])]
    #[DataSet(["\x08\x96\x01\x12\x07testing", 'protobuf'])]
    public function testGuessesFormatFromStart(string $head, string $id): void
    {
        Assert::same(BinaryFormat::guess($head)?->id, $id);
    }

    public function testGuessesPngOfTestCommand(): void
    {
        $png = (string) \file_get_contents(Info::TRAP_ROOT . '/resources/payloads/logo.png');

        Assert::same(BinaryFormat::guess(\substr($png, 0, BinaryFormat::HEAD))?->extension, 'png');
    }

    /**
     * Only a full head may end in a cut character; shorter data ends where the sender stopped.
     */
    #[DataSet(["abc\x80\x81"])]
    #[DataSet(["hello world\xff\xfe\xfd"])]
    public function testShortDataNotUtf8IsNotText(string $head): void
    {
        Assert::notSame(BinaryFormat::guess($head)?->id, 'text');
    }

    public function testFullHeadWithCutCharacterIsText(): void
    {
        $head = \str_repeat('a', BinaryFormat::HEAD - 1) . "\xd0";

        Assert::same(BinaryFormat::guess($head)?->id, 'text');
    }

    #[DataSet([''])]
    #[DataSet(["\x00\x01binary"])]
    #[DataSet(["\x05\x00\xff\xfe\x01"])]
    // Fields of a short message that runs past its end
    #[DataSet(["\x08\x96\x01\x12\x20short"])]
    public function testGuessesNothingForUnknownBytes(string $head): void
    {
        Assert::null(BinaryFormat::guess($head));
    }

    public function testNamesHostOfTlsClientHello(): void
    {
        $format = BinaryFormat::guess(self::clientHello('api.example.com'));

        Assert::same($format?->id, 'tls');
        Assert::same($format->name, 'TLS ClientHello for api.example.com');
    }

    public function testTlsClientHelloWithoutServerName(): void
    {
        Assert::same(BinaryFormat::guess(self::clientHello(null))?->name, 'TLS ClientHello');
    }

    public function testTlsServerNameOfOtherCharactersLeftOut(): void
    {
        Assert::same(BinaryFormat::guess(self::clientHello('<b>evil</b>'))?->name, 'TLS ClientHello');
    }

    public function testTlsHandshakeCutAnywhereIsStillTls(): void
    {
        $hello = self::clientHello('api.example.com');
        for ($length = 6; $length < \strlen($hello); ++$length) {
            Assert::same(BinaryFormat::guess(\substr($hello, 0, $length))?->id, 'tls');
        }
    }

    public function testEntropy(): void
    {
        Assert::same(BinaryFormat::entropy(''), 0.0);
        Assert::same(BinaryFormat::entropy(\str_repeat('a', 100)), 0.0);
        Assert::same(BinaryFormat::entropy('abab'), 1.0);
        Assert::same(\round(BinaryFormat::entropy(\implode('', \array_map(\chr(...), \range(0, 255)))), 6), 8.0);
    }

    /**
     * A ClientHello as a browser starts a handshake, with a `server_name` extension when a host is given.
     */
    private static function clientHello(?string $host): string
    {
        $extensions = "\x00\x17\x00\x00";
        if ($host !== null) {
            $name = "\x00" . \pack('n', \strlen($host)) . $host;
            $list = \pack('n', \strlen($name)) . $name;
            $extensions = "\x00\x00" . \pack('n', \strlen($list)) . $list . $extensions;
        }

        $body = "\x03\x03" . \str_repeat("\x42", 32) . "\x00" . "\x00\x02\x13\x01" . "\x01\x00"
            . \pack('n', \strlen($extensions)) . $extensions;
        $handshake = "\x01" . \substr(\pack('N', \strlen($body)), 1) . $body;

        return "\x16\x03\x01" . \pack('n', \strlen($handshake)) . $handshake;
    }
}
