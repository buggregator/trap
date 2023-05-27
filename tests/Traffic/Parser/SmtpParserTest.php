<?php

declare(strict_types=1);

namespace Buggregator\Client\Tests\Traffic\Parser;

use Buggregator\Client\Tests\FiberTrait;
use Buggregator\Client\Traffic\Message;
use Buggregator\Client\Traffic\Parser;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\TestCase;

final class SmtpParserTest extends TestCase
{
    use FiberTrait;

    public function testParse(): void
    {
        $data = <<<SMTP
            From: Some User <someusername@somecompany.ru>\r
            To: User1 <user1@company.tld>\r
            Subject: Very important theme\r
            Content-Type: text/plain\r
            \r
            Hi!\r
            .\r\n
            SMTP;
        $message = $this->parse($data);

        $this->assertSame($data, (string)$message->getBody());
        $this->assertCount(1, $message->getMessages());
        $this->assertSame('Hi!', $message->getMessages()[0]->getValue());
    }

    private function parse(string $body): Message\Smtp
    {
        return $this->runInFiber(static fn() => (new Parser\Smtp)->parseBody(Stream::create($body)));
    }

}
