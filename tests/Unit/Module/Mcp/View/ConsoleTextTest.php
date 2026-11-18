<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Mcp\View;

use Buggregator\Trap\Module\Mcp\View\ConsoleText;
use Buggregator\Trap\Module\VarDumper\Frame as VarDumperFrame;
use Buggregator\Trap\Server\Event\Event;
use Buggregator\Trap\Server\Event\Record;
use Buggregator\Trap\Server\Proto\Frame\Binary;
use Buggregator\Trap\Tests\Unit\Module\Mcp\Stub\BinaryRendererModule;
use Buggregator\Trap\Tests\Unit\Module\Mcp\Stub\Trap;
use Nyholm\Psr7\Stream;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(ConsoleText::class)]
final class ConsoleTextTest
{
    public function testDumpIsRenderedAsPlainText(): void
    {
        $trap = new Trap();
        $record = $trap->add(Trap::dump(['marker' => 'console-text']));

        $text = $trap->get(ConsoleText::class)->text($record);

        Assert::string($text)
            ->startsWith('DUMP')
            ->contains('"marker" => "console-text"')
            ->contains('Command: test')
            ->notContains("\e[")
            ->notContains('<fg=')
            ->endsWith("]\n");
    }

    public function testFramesAreJoined(): void
    {
        $trap = new Trap();
        $first = Trap::dump('first');
        $second = Trap::log('second');
        $event = new Event('5a4f8e1c-0000-4000-8000-000000000001', 'var-dump', [], 1.0);

        $text = $trap->get(ConsoleText::class)->text(new Record($event, 1, [$first, $second]));

        Assert::string($text)->matchesRegex('/first.*MONOLOG.*second/s');
    }

    public function testRendererSuspendingItsFiberIsRenderedToTheEnd(): void
    {
        $trap = new Trap([BinaryRendererModule::class]);
        $event = new Event('5a4f8e1c-0000-4000-8000-000000000001', 'binary', [], 1.0);
        $record = new Record($event, 1, [new Binary(Stream::create('trap'))]);

        $text = $trap->get(ConsoleText::class)->text($record);

        Assert::string($text)->startsWith('BINARY')->contains('74 72 61 70');
    }

    public function testEventWithoutFramesHasNoText(): void
    {
        $event = new Event('5a4f8e1c-0000-4000-8000-000000000001', 'var-dump', [], 1.0);

        Assert::null((new Trap())->get(ConsoleText::class)->text(new Record($event, 1, [])));
    }

    public function testFailedRenderingIsReported(): void
    {
        $event = new Event('5a4f8e1c-0000-4000-8000-000000000001', 'var-dump', [], 1.0);
        $record = new Record($event, 1, [new VarDumperFrame('not a dump')]);

        $text = (new Trap())->get(ConsoleText::class)->text($record);

        Assert::string($text)->contains('Rendering of the var-dumper frame failed: Unable to decode the message.');
    }

    public function testTerminalWidthIsRestored(): void
    {
        $trap = new Trap();
        $record = $trap->add(Trap::dump('width'));
        $columns = \getenv('COLUMNS');
        \putenv('COLUMNS=33');

        try {
            $trap->get(ConsoleText::class)->text($record);

            Assert::same(\getenv('COLUMNS'), '33');
        } finally {
            \putenv($columns === false ? 'COLUMNS' : "COLUMNS=$columns");
        }
    }

    public function testNoSummary(): void
    {
        $event = new Event('5a4f8e1c-0000-4000-8000-000000000001', 'var-dump', [], 1.0);

        Assert::null((new Trap())->get(ConsoleText::class)->summary($event));
    }
}
