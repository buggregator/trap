<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Sender;

use Buggregator\Trap\Proto\Frame\Smtp as SmtpFrame;
use Buggregator\Trap\Sender\MailToFileSender;
use Buggregator\Trap\Traffic\Message\Smtp as SmtpMessage;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Buggregator\Trap\Sender\MailToFileSender
 */
final class MailToFileSenderTest extends TestCase
{
    private array $cleanupFolders = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanupFolders as $folder) {
            \array_map('unlink', glob("$folder/*.*"));
            \rmdir($folder);
        }
    }

    public function testForSmtp(): void
    {
        $this->cleanupFolders[] = $root = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . \uniqid('trap_mail_');

        $message = SmtpMessage::create(
            protocol: [
                'FROM' => ['<someusername@foo.bar>'],
                'BCC' => ['<user1@company.tld>', '<user2@company.tld>'],
            ],
            headers: [
                'From' => ['Some User <someusername@somecompany.ru>'],
                'To' => ['User1 <user1@company.tld>'],
                'Subject' => ['Very important theme'],
                'Content-Type' => ['text/plain'],
            ],
        );
        $frame = new SmtpFrame($message);
        $sender = new MailToFileSender($root);
        $sender->send([$frame]);

        $this->assertRecipient("$root/user1@company.tld");
        $this->assertRecipient("$root/user2@company.tld");
    }

    private function assertRecipient(string $folder): void
    {
        self::assertTrue(\file_exists($folder));
        self::assertTrue(\is_dir($folder));
        $files = glob("$folder/*.json");
        self::assertCount(1, $files);
        $arr = \json_decode(\file_get_contents($files[0]), true, \JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('protocol', $arr);
        self::assertArrayHasKey('headers', $arr);
        self::assertArrayHasKey('messages', $arr);
        self::assertArrayHasKey('attachments', $arr);
    }
}
