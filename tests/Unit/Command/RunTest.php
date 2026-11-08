<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Command;

use Buggregator\Trap\Command\Run;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(Run::class)]
final class RunTest
{
    public function testStartupErrorIsReportedAndFails(): void
    {
        $tester = new CommandTester(new Run());

        $exitCode = $tester->execute(['--sender' => ['nosuch']]);

        Assert::same($exitCode, Command::FAILURE);
        Assert::string($tester->getDisplay())->contains('Unknown sender type `nosuch`.');
    }
}
