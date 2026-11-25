<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Sentry\Console;

use Buggregator\Trap\Module\Sentry\Console\SentryStore;
use Buggregator\Trap\Module\Sentry\Console\TraceLine;
use Buggregator\Trap\Module\Sentry\Frame\SentryStore as SentryStoreFrame;
use Symfony\Component\Console\Output\BufferedOutput;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Covers(SentryStore::class)]
#[Covers(TraceLine::class)]
#[Test]
final class SentryStoreTest
{
    public function testRendersTransactionAsTraceLine(): void
    {
        $output = self::render([
            'type' => 'transaction',
            'transaction' => 'GET /',
            'contexts' => ['trace' => ['trace_id' => 'aaaabbbbccccdddd']],
            'spans' => [],
        ]);

        Assert::string($output)->contains("Trace aaaabbbbccccdddd: GET /, 1 span\n");
    }

    public function testRendersTransactionWithoutTraceIdAsItsLine(): void
    {
        $output = self::render(['type' => 'transaction', 'transaction' => 'GET /', 'spans' => []]);

        Assert::string($output)->contains("Transaction without a trace ID: GET /, 1 span\n")->notContains('Trace ');
    }

    public function testRendersErrorExceptions(): void
    {
        $output = self::render(['exception' => ['values' => [['type' => 'RuntimeException', 'value' => 'Boom']]]]);

        Assert::string($output)->contains('RuntimeException')->contains('Boom')->notContains('trace ID');
    }

    /**
     * sentry-go and the short form of the protocol send the exceptions as a bare list.
     */
    public function testRendersBareListOfExceptions(): void
    {
        $output = self::render(['exception' => [['type' => 'RuntimeException', 'value' => 'Boom']]]);

        Assert::string($output)->contains('RuntimeException')->contains('Boom');
    }

    private static function render(array $message): string
    {
        $output = new BufferedOutput();
        (new SentryStore())->render($output, new SentryStoreFrame($message));

        return \str_replace("\r\n", "\n", $output->fetch());
    }
}
