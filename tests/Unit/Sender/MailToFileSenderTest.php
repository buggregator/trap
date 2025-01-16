<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Sender;

use Buggregator\Trap\Info;
use Buggregator\Trap\Proto\Frame\Smtp as SmtpFrame;
use Buggregator\Trap\Sender\MailToFileSender;
use Buggregator\Trap\Traffic\Message\Smtp as SmtpMessage;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Buggregator\Trap\Sender\MailToFileSender
 */
final class MailToFileSenderTest extends TestCase
{
    /** @var list<non-empty-string> */
    private array $cleanupFolders = [];

    public function testForSmtp(): void
    {
        $this->cleanupFolders[] = $root = Info::TRAP_ROOT . '/runtime/tests/mail-to-file-sender';

        $message = SmtpMessage::create(
            protocol: [
                'FROM' => ['<someusername@foo.bar>'],
                'BCC' => [
                    '<user1@company.tld>',
                    '<user2@company.tld>',
                ],
            ],
            headers: [
                'From' => ['Some User <someusername@somecompany.ru>'],
                'To' => [
                    'User1 <user1@company.tld>',
                    'user2@company.tld',
                    'User without email', // no email
                    'User3 <user3@inline.com>, User4 <user4@inline.com>, user5@inline.com',
                ],
                'Subject' => ['Very important theme'],
                'Content-Type' => ['text/plain'],
            ],
        );
        $frame = new SmtpFrame($message);
        $sender = new MailToFileSender($root);
        $sender->send([$frame]);

        $this->assertRecipient("$root/user1[at]company.tld");
        $this->assertRecipient("$root/user2[at]company.tld");
        $this->assertRecipient("$root/user3[at]inline.com");
        $this->assertRecipient("$root/user4[at]inline.com");
        $this->assertRecipient("$root/user5[at]inline.com");
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanupFolders as $folder) {
            \array_map('unlink', \glob("$folder/*/*.*"));
            \array_map('rmdir', \glob("$folder/*"));
            \rmdir($folder);
        }
    }

    private function assertRecipient(string $folder): void
    {
        self::assertDirectoryExists($folder);
        $files = \glob(\str_replace('[', '[[]', "$folder/*.json"));
        self::assertCount(1, $files);
        $arr = \json_decode(\file_get_contents($files[0]), true, \JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('protocol', $arr);
        self::assertArrayHasKey('headers', $arr);
        self::assertArrayHasKey('messages', $arr);
        self::assertArrayHasKey('attachments', $arr);
    }
}
