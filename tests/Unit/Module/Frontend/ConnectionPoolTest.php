<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Frontend;

use Buggregator\Trap\Module\Frontend\ConnectionPool;
use Buggregator\Trap\Module\Frontend\EventPusher;
use Buggregator\Trap\Module\Frontend\Publisher;
use Buggregator\Trap\Module\Ray\Frontend\Payload as RayPayload;
use Buggregator\Trap\Module\Ray\Lock\Locks;
use Buggregator\Trap\Server\Event\Event;
use Buggregator\Trap\Server\Event\EventStore;
use Buggregator\Trap\Server\Traffic\Websocket\Frame;
use Buggregator\Trap\Server\Traffic\Websocket\StreamReader;
use Buggregator\Trap\Test\Mock\StreamClientMock;
use Internal\Container\ObjectContainer;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Covers(ConnectionPool::class)]
#[Covers(Publisher::class)]
#[Covers(EventPusher::class)]
#[Test]
final class ConnectionPoolTest
{
    /** @var list<string> Data the server sent to the client */
    private array $sent = [];

    public function testConnectSubscribesToProjectChannels(): void
    {
        [$pool] = $this->connected();

        $connect = $this->messages()[0];
        Assert::same($connect['id'], 1);
        Assert::same(\array_keys($connect['connect']['subs']), ['events.project.default', 'events.project.shop']);
        Assert::same($pool->channels(), ['events.project.default', 'events.project.shop']);
    }

    /**
     * centrifuge-js drops a publication of a channel it is not subscribed to.
     */
    public function testNewChannelIsSubscribedBeforeItsFirstPublication(): void
    {
        [$pool, $publisher] = $this->connected();
        $this->sent = [];

        $publisher->toProject('new', 'event.received', ['uuid' => 'a']);
        $publisher->toProject('new', 'event.received', ['uuid' => 'b']);
        $publisher->toProject('default', 'events.cleared');
        $pool->process();

        Assert::same($this->messages(), [
            ['push' => ['channel' => 'events.project.new', 'subscribe' => []]],
            ['push' => ['channel' => 'events.project.new', 'pub' => ['data' => ['event' => 'event.received', 'data' => ['uuid' => 'a']]]]],
            ['push' => ['channel' => 'events.project.new', 'pub' => ['data' => ['event' => 'event.received', 'data' => ['uuid' => 'b']]]]],
            ['push' => ['channel' => 'events.project.default', 'pub' => ['data' => ['event' => 'events.cleared', 'data' => null]]]],
        ]);
    }

    public function testStoreChangesArePushedToTheProjectChannel(): void
    {
        [$pool, , $store] = $this->connected();
        $store->listen(new EventPusher(new Publisher($pool), $store));
        $this->sent = [];

        $store->add(new Event('a1', 'smtp', [], 3.0, project: 'shop'));
        $store->delete('a1');
        $store->clear();
        $pool->process();

        $pubs = \array_map(
            static fn(array $m): array => [$m['push']['channel'], $m['push']['pub']['data']['event']],
            $this->messages(),
        );
        Assert::same($pubs, [
            ['events.project.shop', 'event.received'],
            ['events.project.shop', 'event.deleted'],
            ['events.project.default', 'events.cleared'],
            ['events.project.shop', 'events.cleared'],
        ]);
        Assert::same($this->messages()[1]['push']['pub']['data']['data'], ['uuid' => 'a1', 'project' => 'shop']);
        Assert::false($this->messages()[0]['push']['pub']['data']['data']['is_pinned']);
    }

    /**
     * A Continue in one tab: the tab gets the state in the reply, and every tab gets the event with it.
     */
    public function testReleasedRayPauseIsPushedWithItsState(): void
    {
        $this->connected(['{"id":2,"rpc":{"method":"post:api/ray/locks/aaa","data":{"project":"default"}}}'], static function (ObjectContainer $c): void {
            $store = $c->get(EventStore::class);
            $store->listen(new EventPusher($c->get(Publisher::class), $store));
            $store->add(new Event('0145a0e0', 'ray', new RayPayload('0145a0e0', [
                ['type' => 'create_lock', 'content' => ['name' => 'aaa']],
            ], []), 2.0));
            $c->get(Locks::class)->create('aaa');
        });

        $messages = \array_slice($this->messages(), 1);
        $reply = \array_values(\array_filter($messages, static fn(array $m): bool => isset($m['rpc'])));
        $pubs = \array_values(\array_filter($messages, static fn(array $m): bool => isset($m['push']['pub'])));
        Assert::same($reply[0]['rpc']['data'], ['code' => 200, 'data' => ['code' => 200, 'status' => true, 'state' => 'continued']]);
        Assert::count($pubs, 1);
        Assert::same($pubs[0]['push']['channel'], 'events.project.default');
        Assert::same($pubs[0]['push']['pub']['data']['event'], 'event.received');
        Assert::same($pubs[0]['push']['pub']['data']['data']['payload']['payloads'][0]['content'], ['name' => 'aaa', 'state' => 'continued']);
    }

    /**
     * An RPC call of no HTTP verb is answered as bad, and the connection goes on.
     */
    public function testConnectionKeepsWorkingAfterInvalidRpcMethod(): void
    {
        $this->connected([
            '{"id":2,"rpc":{"method":"ping"}}',
            '{"id":3,"rpc":{"method":"foo:api/x"}}',
            '{"id":4,"rpc":{"method":"get:api/version"}}',
        ]);

        $replies = \array_column(\array_slice($this->messages(), 1), 'rpc', 'id');
        Assert::same($replies[2]['data']['code'], 400);
        Assert::same($replies[3]['data']['code'], 400);
        Assert::same($replies[4]['data']['code'], 200);
    }

    /**
     * @param list<string> $messages Sent by the client after the connect.
     * @param \Closure(ObjectContainer): void|null $setup Runs before the client connects.
     *
     * @return array{ConnectionPool, Publisher, EventStore} A pool with a client connected.
     */
    private function connected(array $messages = [], ?\Closure $setup = null): array
    {
        $this->sent = [];
        $container = new ObjectContainer();
        $store = $container->get(EventStore::class);
        $store->add(new Event('s1', 'smtp', [], 1.0, project: 'shop'));
        $setup === null or $setup($container);
        $pool = $container->get(ConnectionPool::class);

        $pool->addStream(StreamClientMock::createFromGenerator($this->client($messages)));
        for ($i = 0; $i < 3 + \count($messages) * 2; ++$i) {
            $pool->process();
        }

        return [$pool, new Publisher($pool), $store];
    }

    /**
     * @param list<string> $messages
     */
    private function client(array $messages): \Generator
    {
        $data = yield (string) Frame::text('{"id":1,"connect":{"token":"ignored","name":"js"}}');
        foreach ($messages as $message) {
            $data === '' or $this->sent[] = $data;
            $data = yield (string) Frame::text($message);
        }
        while (true) {
            $data === '' or $this->sent[] = $data;
            $data = yield '';
        }
    }

    /**
     * @return list<array>
     */
    private function messages(): array
    {
        $messages = [];
        foreach (StreamReader::readFrames([\implode('', $this->sent)]) as $frame) {
            $messages[] = \json_decode($frame->content, true, flags: \JSON_THROW_ON_ERROR);
        }

        return $messages;
    }
}
