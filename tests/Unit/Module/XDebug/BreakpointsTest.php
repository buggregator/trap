<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\XDebug;

use Buggregator\Trap\Module\XDebug\Breakpoints;
use Buggregator\Trap\Module\XDebug\DebuggerError;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Covers(Breakpoints::class)]
#[Test]
final class BreakpointsTest
{
    public function testTurnsPathsIntoFileUris(): void
    {
        Assert::same(Breakpoints::fileUri('/var/www/a.php'), 'file:///var/www/a.php');
        Assert::same(Breakpoints::fileUri('C:\app\a.php'), 'file:///C:/app/a.php');
        Assert::same(Breakpoints::fileUri('file:///a.php'), 'file:///a.php');
    }

    public function testAddsUpdatesAndDeletes(): void
    {
        $store = new Breakpoints();

        $line = $store->add(['type' => 'line', 'file' => '/app/a.php', 'line' => 3, 'condition' => ' $a > 1 '], 'ui');
        $exception = $store->add(['exception' => '\RuntimeException'], 'mcp');
        $disabled = $store->update($line['id'], ['enabled' => false]);
        $store->delete($exception['id']);

        Assert::same($line['file'], 'file:///app/a.php');
        Assert::same($line['condition'], '$a > 1');
        Assert::same($exception['type'], 'exception');
        Assert::same($exception['exception'], 'RuntimeException');
        Assert::same($exception['source'], 'mcp');
        Assert::false($disabled['enabled']);
        Assert::same($disabled['line'], 3);
        Assert::count($store->all(), 1);
    }

    public function testRejectsRelativeFile(): never
    {
        Expect::exception(DebuggerError::class)->withMessageContaining('absolute path');

        (new Breakpoints())->add(['file' => 'src/a.php', 'line' => 1], 'ui');
    }

    public function testRejectsUnknownBreakpoint(): never
    {
        Expect::exception(DebuggerError::class)->withMessage('No such breakpoint.');

        (new Breakpoints())->update(5, ['enabled' => true]);
    }
}
