<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Mcp\Tool;

use Buggregator\Trap\Module\Mcp\Tool\GetEvent;
use Buggregator\Trap\Tests\Unit\Module\Mcp\Stub\Trap;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(GetEvent::class)]
final class GetEventTest
{
    public function testHeaderThenText(): void
    {
        $trap = new Trap();
        $record = $trap->add(Trap::dump(['order' => 'get-event'], ['project' => 'shop']));

        $text = $trap->get(GetEvent::class)->call(['id' => $record->event->uuid]);

        Assert::string($text)
            ->startsWith("var-dump event {$record->event->uuid} at 2023-11-14T22:13:20.500+00:00, project shop\n"
                . "array:1 [ \"order\" => \"get-event\" ]\n\nDUMP\n")
            ->contains("  \"order\" => \"get-event\"\n")
            ->notContains("\e[");
    }

    public function testLongTextIsTruncated(): void
    {
        $trap = new Trap();
        $record = $trap->add(Trap::dump(\str_repeat('long ', 100)));

        $text = $trap->get(GetEvent::class)->call(['id' => $record->event->uuid, 'max_length' => 120]);

        [$kept, $notice] = \explode("\n\n[Truncated: ", $text);
        Assert::same(\mb_strlen($kept), 120);
        Assert::string($notice)->matchesRegex('/^120 of \d+ characters shown\. Call again with a larger `max_length` for more\.]$/');
    }

    public function testShortTextIsKept(): void
    {
        $trap = new Trap();
        $record = $trap->add(Trap::log('short'));

        $text = $trap->get(GetEvent::class)->call(['id' => $record->event->uuid, 'max_length' => 100_000]);

        Assert::string($text)->contains('MONOLOG')->notContains('[Truncated');
    }

    public function testUnknownEventFails(): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('No event with ID `missing`');

        (new Trap())->get(GetEvent::class)->call(['id' => 'missing']);
    }

    public function testDeletedEventFails(): never
    {
        $trap = new Trap();
        $record = $trap->add(Trap::log('deleted'));
        $trap->store->delete($record->event->uuid);

        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('No event with ID');

        $trap->get(GetEvent::class)->call(['id' => $record->event->uuid]);
    }

    #[DataSet([[], '`id` must be a non-empty string.'], 'no ID')]
    #[DataSet([['id' => ''], '`id` must be a non-empty string.'], 'empty ID')]
    #[DataSet([['id' => 1], '`id` must be a non-empty string.'], 'ID number')]
    #[DataSet([['id' => 'x', 'max_length' => 99], '`max_length` must be an integer from 100 to'], 'small limit')]
    public function testInvalidArguments(array $arguments, string $message): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining($message);

        (new Trap())->get(GetEvent::class)->call($arguments);
    }

    public function testDescription(): void
    {
        $tool = (new Trap())->get(GetEvent::class);

        Assert::same($tool->name(), 'get_event');
        Assert::same($tool->inputSchema()['required'], ['id']);
        Assert::same($tool->annotations(), ['readOnlyHint' => true, 'openWorldHint' => false]);
    }
}
