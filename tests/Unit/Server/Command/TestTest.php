<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Server\Command;

use Buggregator\Trap\Server\Command\Test;
use Buggregator\Trap\Server\Logger;
use Symfony\Component\Console\Output\BufferedOutput;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test as TestAttribute;

#[TestAttribute]
#[Covers(Test::class)]
final class TestTest
{
    public function testMissingPayloadIsReportedWithoutThrowing(): void
    {
        $output = new BufferedOutput();
        $command = new Test();
        (new \ReflectionProperty(Test::class, 'logger'))->setValue($command, new Logger($output));

        (new \ReflectionMethod(Test::class, 'sendContent'))->invoke($command, 'no-such-payload.http');

        Assert::string($output->fetch())->contains('Cannot open file.');
    }
}
