<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Mailer\Console;

use Buggregator\Trap\Module\Mailer\Console\Renderer;
use Buggregator\Trap\Module\Mailer\Frame;
use Buggregator\Trap\Module\Mailer\Message;
use Symfony\Component\Console\Output\BufferedOutput;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Covers(Renderer::class)]
#[Test]
final class RendererTest
{
    public function testDecodesEncodedHeaderValues(): void
    {
        $output = new BufferedOutput();
        $message = Message::create(['FROM' => ['sender@example.com']], [
            'Subject' => ['=?utf-8?Q?=D0=9F=D1=80=D0=B8=D0=B2=D0=B5=D1=82=2C_=D0=BC=D0=B8=D1=80?='],
            'From' => ['=?utf-8?Q?Doe=2C_John?= <sender@example.com>'],
        ]);

        (new Renderer())->render($output, new Frame($message));
        $text = $output->fetch();

        Assert::string($text)
            ->contains('Привет, мир')
            ->contains('Doe, John <sender@example.com>')
            ->notContains('=?utf-8?');
    }
}
