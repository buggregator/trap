<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Ray\Frontend;

use Buggregator\Trap\Module\Frontend\RPC;
use Buggregator\Trap\Module\Ray\Frame;
use Buggregator\Trap\Module\Ray\Frontend\Mapper;
use Buggregator\Trap\Module\Ray\Frontend\Payload;
use Buggregator\Trap\Module\Ray\Lock\Locks;
use Buggregator\Trap\Server\Event\Event;
use Buggregator\Trap\Server\Event\EventStore;
use Buggregator\Trap\Server\Event\FrameMapper;
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

        $event = (new Mapper(new EventStore(), new Locks()))->map($frame);

        $json = \json_decode(\json_encode($event, \JSON_THROW_ON_ERROR), true);
        Assert::same($json['uuid'], self::UUID);
        Assert::same($json['type'], 'ray');
        Assert::same($json['timestamp'], 1767323045.5);
        Assert::same($json['project'], 'default');
        Assert::same($json['payload'], \json_decode(FrameTest::REQUEST, true));
    }

    public function testChainedCallsAddPayloadsToTheEvent(): void
    {
        $storage = new EventStore();
        $mapper = new Mapper($storage, new Locks());
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
        $storage = new EventStore();
        $storage->add(new Event('aa-1', 'ray', [], 1.0));
        $storage->add(new Event('aa-2', 'monolog', [], 1.0));

        $event = (new Mapper($storage, new Locks()))->map(self::frame([['type' => 'clear_all', 'content' => []]], 2.0));

        Assert::null($event);
        Assert::null($storage->get('aa-1'));
        Assert::notNull($storage->get('aa-2'));
    }

    public function testKeepsOnlyThePreOfHtmlDumps(): void
    {
        $dump = '<script> Sfdump = window.Sfdump || (function (doc) { })(document);</script><style>pre.sf-dump{}</style>'
            . '<pre class=sf-dump id=sf-dump-42 data-indent-pad="  "><span class=sf-dump-note>array:1</span></pre>'
            . '<script>Sfdump("sf-dump-42")</script>';

        $event = (new Mapper(new EventStore(), new Locks()))->map(self::frame([
            ['type' => 'log', 'content' => ['values' => [$dump, 'plain'], 'meta' => []]],
        ], 1.0));

        Assert::same($event?->payload->payloads[0]['content']['values'], [
            '<pre class=sf-dump id=sf-dump-42 data-indent-pad="  "><span class=sf-dump-note>array:1</span></pre>',
            'plain',
        ]);
    }

    public function testReplacesUuidTheUiCannotRoute(): void
    {
        $event = (new Mapper(new EventStore(), new Locks()))->map(new Frame('not/a uuid', [['type' => 'log']]));

        Assert::same(\preg_match('/^[a-f0-9-]+$/', (string) $event?->uuid), 1);
    }

    public function testFrameMapperUsesTheRayMapper(): void
    {
        $event = ((new ObjectContainer())->get(FrameMapper::class))->map(Frame::fromString(FrameTest::REQUEST, new \DateTimeImmutable()));

        Assert::same($event?->type, 'ray');
    }

    public function testHostsOfAllPayloadOriginsAreFacets(): void
    {
        $storage = new EventStore();
        $mapper = new Mapper($storage, new Locks());
        $first = $mapper->map(self::frame([['type' => 'log', 'origin' => ['hostname' => 'web-1']]], 10.0));
        \assert($first !== null);
        $storage->add($first);

        $second = $mapper->map(self::frame([['type' => 'color', 'origin' => ['hostname' => 'web-2']]], 11.0));

        Assert::same($first->facets, ['host' => ['web-1']]);
        Assert::same($second?->facets, ['host' => ['web-1', 'web-2']]);
    }

    /**
     * A Continue that lands before the event is stored: the event comes with the state.
     */
    public function testPauseReleasedBeforeItsEventIsStoredHasTheState(): void
    {
        $container = new ObjectContainer();
        $container->get(Locks::class)->create('aaa');
        $container->get(RPC::class)->handleMessage(['method' => 'post:api/ray/locks/aaa', 'data' => []]);

        $event = $container->get(FrameMapper::class)->map(self::frame([
            ['type' => 'create_lock', 'content' => ['name' => 'aaa']],
            ['type' => 'create_lock', 'content' => ['name' => 'bbb']],
        ], 1.0));

        \assert($event?->payload instanceof Payload);
        Assert::same(\array_column($event->payload->payloads, 'content'), [
            ['name' => 'aaa', 'state' => 'continued'],
            ['name' => 'bbb'],
        ]);
    }

    /**
     * A client pausing in the same second as a released pause joins its lock and goes on at once.
     */
    public function testPauseJoiningReleasedLockHasTheState(): void
    {
        $locks = new Locks();
        $locks->create('aaa');
        $locks->resolve('aaa', stop: true);
        $locks->create('aaa');

        $event = (new Mapper(new EventStore(), $locks))->map(self::frame([['type' => 'create_lock', 'content' => ['name' => 'aaa']]], 1.0));

        \assert($event?->payload instanceof Payload);
        Assert::same($event->payload->payloads[0]['content'], ['name' => 'aaa', 'state' => 'stopped']);
    }

    public function testPendingPauseHasNoState(): void
    {
        $locks = new Locks();
        $locks->create('aaa');

        $event = (new Mapper(new EventStore(), $locks))->map(self::frame([['type' => 'create_lock', 'content' => ['name' => 'aaa']]], 1.0));

        \assert($event?->payload instanceof Payload);
        Assert::same($event->payload->payloads[0]['content'], ['name' => 'aaa']);
    }

    private static function frame(array $payloads, float $time): Frame
    {
        return new Frame(self::UUID, $payloads, [], new \DateTimeImmutable('@' . $time));
    }
}
