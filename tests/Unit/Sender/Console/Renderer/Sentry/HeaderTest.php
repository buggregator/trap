<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Sender\Console\Renderer\Sentry;

use Buggregator\Trap\Sender\Console\Renderer\Sentry\Header;
use Symfony\Component\Console\Output\BufferedOutput;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Covers(Header::class)]
#[Test]
final class HeaderTest
{
    public function testRendersMessageWithoutSdkWithoutWarnings(): void
    {
        $warnings = [];
        \set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
            $warnings[] = $errstr;
            return true;
        });
        try {
            $output = new BufferedOutput();
            Header::renderMessageHeader($output, [
                'event_id' => 'abc',
                'server_name' => 'web-1',
                'level' => 'error',
                'platform' => 'php',
            ]);
        } finally {
            \restore_error_handler();
        }

        Assert::same($warnings, []);
        Assert::string($output->fetch())->contains('abc')->contains('web-1')->contains('error')->contains('php')->notContains('SDK');
    }

    public function testRendersSdk(): void
    {
        $output = new BufferedOutput();

        Header::renderMessageHeader($output, ['sdk' => ['name' => 'sentry.php', 'version' => '4.0.0']]);

        Assert::string($output->fetch())->contains('SDK')->contains('sentry.php 4.0.0');
    }
}
