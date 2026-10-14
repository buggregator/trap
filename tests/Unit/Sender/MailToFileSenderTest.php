<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Sender;

use Buggregator\Trap\Info;
use Buggregator\Trap\Proto\Frame\Smtp as SmtpFrame;
use Buggregator\Trap\Sender\MailToFileSender;
use Buggregator\Trap\Traffic\Message\Smtp as SmtpMessage;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Test;

/**
 * @coversDefaultClass \Buggregator\Trap\Sender\MailToFileSender
 */
#[Test]
final class MailToFileSenderTest
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
                    'User3 <user3@inline.com>, User4 <user4@inline.com>, us_er_5@inline.com',
                ],
                'Subject' => ['Very important theme'],
                'Content-Type' => ['text/plain'],
            ],
        );
        $frame = new SmtpFrame($message);
        $sender = new MailToFileSender($root);
        $sender->send([$frame]);

        $this->assertRecipient("$root/user1@company.tld");
        $this->assertRecipient("$root/user2@company.tld");
        $this->assertRecipient("$root/user3@inline.com");
        $this->assertRecipient("$root/user4@inline.com");
        $this->assertRecipient("$root/us_er_5@inline.com");
    }

    #[AfterTest]
    protected function tearDown(): void
    {
        foreach ($this->cleanupFolders as $folder) {
            \array_map('unlink', \glob("$folder/*/*.*"));
            \array_map('rmdir', \glob("$folder/*"));
            \rmdir($folder);
        }
        $this->cleanupFolders = [];
    }

    private function assertRecipient(string $folder): void
    {
        Assert::true(\is_dir($folder));
        $files = \glob(\str_replace('[', '[[]', "$folder/*.json"));
        Assert::count($files, 1);
        $arr = \json_decode(\file_get_contents($files[0]), true, \JSON_THROW_ON_ERROR);
        Assert::array($arr)->hasKeys('protocol')->hasKeys('headers')->hasKeys('messages')->hasKeys('attachments');
    }
}
