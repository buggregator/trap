<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Acceptance;

use Buggregator\Trap\Tests\Acceptance\Support\TrapProcess;
use Testo\Assert;
use Testo\Data\DataSet;
use Testo\Test;

#[Test]
final class CliTest
{
    public function testVersion(): void
    {
        [$exitCode, $output] = TrapProcess::run('cli-version', ['--version']);

        Assert::same($exitCode, 0);
        Assert::string($output)->ignoringAnsi()->contains('Buggregator Trap')->matchesRegex('/\d+\.\d+\.\d+/');
    }

    public function testHelp(): void
    {
        [$exitCode, $output] = TrapProcess::run('cli-help', ['--help']);

        Assert::same($exitCode, 0);
        Assert::string($output)->ignoringAnsi()
            ->contains('--port')
            ->contains('--sender')
            ->contains('--ui');
    }

    public function testUnknownOptionFails(): void
    {
        [$exitCode, $output] = TrapProcess::run('cli-unknown-option', ['--no-such-option']);

        Assert::int($exitCode)->greaterThan(0);
        Assert::string($output)->contains('no-such-option');
    }

    /**
     * `TRAP_TCP_PORTS` is removed for the port cases: it takes precedence over `--port`.
     */
    #[DataSet(['--port=abc', 'abc', ['TRAP_TCP_PORTS' => null]], 'non-numeric port')]
    #[DataSet(['--port=70000', '70000', ['TRAP_TCP_PORTS' => null]], 'port out of range')]
    #[DataSet(['--sender=nosuch', 'nosuch', []], 'unknown sender')]
    public function testInvalidStartupOptionFails(string $option, string $reported, array $env): void
    {
        [$exitCode, $output] = TrapProcess::run('cli-invalid-option', [$option], $env);

        Assert::int($exitCode)->greaterThan(0);
        Assert::string($output)->contains($reported);
    }
}
