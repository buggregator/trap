<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Ray\Frontend;

use Buggregator\Trap\Module\Frontend\Event;
use Buggregator\Trap\Module\Frontend\EventStorage;
use Buggregator\Trap\Module\Frontend\FrameMapper;
use Buggregator\Trap\Module\Ray\Frame;
use Buggregator\Trap\Module\Ray\Frontend\Mapper;
use Buggregator\Trap\Tests\Unit\Module\Ray\FrameTest;
use Internal\Container\ObjectContainer;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Covers(Mapper::class)]
#[Test]
final class MapperTest
{
    private const UUID = '0f0e1c2b-3a49-4c5d-8e6f-708192a3b4c5';

    public function testMapsToTheRayUiEvent(): void
    {
        $frame = Frame::fromString(FrameTest::REQUEST, new \DateTimeImmutable('@1767323045.5'));

        $event = (new Mapper(new EventStorage()))->map($frame);

        $json = \json_decode(\json_encode($event, \JSON_THROW_ON_ERROR), true);
        Assert::same($json['uuid'], self::UUID);
        Assert::same($json['type'], 'ray');
        Assert::same($json['timestamp'], 1767323045.5);
        Assert::same($json['project'], 'default');
        Assert::same($json['payload'], \json_decode(FrameTest::REQUEST, true));
    }

    public function testChainedCallsAddPayloadsToTheEvent(): void
    {
        $storage = new EventStorage();
        $mapper = new Mapper($storage);
        $first = $mapper->map(self::frame([['type' => 'log', 'content' => ['values' => ['x']]]], 10.0));
        \assert($first !== null);
        $storage->add($first);

        $second = $mapper->map(self::frame([['type' => 'color', 'content' => ['color' => 'red']]], 11.0));

        Assert::same($second?->uuid, self::UUID);
        Assert::same(\array_column($second->payload->payloads, 'type'), ['log', 'color']);
        Assert::same($second->timestamp, 10.0);
    }

    public function testClearAllDeletesStoredRayEvents(): void
    {
        $storage = new EventStorage();
        $storage->add(new Event('aa-1', 'ray', [], 1.0));
        $storage->add(new Event('aa-2', 'monolog', [], 1.0));

        $event = (new Mapper($storage))->map(self::frame([['type' => 'clear_all', 'content' => []]], 2.0));

        Assert::null($event);
        Assert::null($storage->get('aa-1'));
        Assert::notNull($storage->get('aa-2'));
    }

    public function testKeepsOnlyThePreOfHtmlDumps(): void
    {
        $dump = '<script> Sfdump = window.Sfdump || (function (doc) { })(document);</script><style>pre.sf-dump{}</style>'
            . '<pre class=sf-dump id=sf-dump-42 data-indent-pad="  "><span class=sf-dump-note>array:1</span></pre>'
            . '<script>Sfdump("sf-dump-42")</script>';

        $event = (new Mapper(new EventStorage()))->map(self::frame([
            ['type' => 'log', 'content' => ['values' => [$dump, 'plain'], 'meta' => []]],
        ], 1.0));

        Assert::same($event?->payload->payloads[0]['content']['values'], [
            '<pre class=sf-dump id=sf-dump-42 data-indent-pad="  "><span class=sf-dump-note>array:1</span></pre>',
            'plain',
        ]);
    }

    public function testReplacesUuidTheUiCannotRoute(): void
    {
        $event = (new Mapper(new EventStorage()))->map(new Frame('not/a uuid', [['type' => 'log']]));

        Assert::same(\preg_match('/^[a-f0-9-]+$/', (string) $event?->uuid), 1);
    }

    public function testFrameMapperUsesTheRayMapper(): void
    {
        $event = ((new ObjectContainer())->get(FrameMapper::class))->map(Frame::fromString(FrameTest::REQUEST, new \DateTimeImmutable()));

        Assert::same($event?->type, 'ray');
    }

    private static function frame(array $payloads, float $time): Frame
    {
        return new Frame(self::UUID, $payloads, [], new \DateTimeImmutable('@' . $time));
    }
}
