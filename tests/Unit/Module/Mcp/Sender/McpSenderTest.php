<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Mcp\Sender;

use Buggregator\Trap\Module\Mcp\Config\Mcp;
use Buggregator\Trap\Module\Mcp\McpStatus;
use Buggregator\Trap\Module\Mcp\Sender\McpSender;
use Buggregator\Trap\Module\Mcp\View\TextCache;
use Buggregator\Trap\Server\Application\Module\Modules;
use Buggregator\Trap\Server\Bootstrap;
use Buggregator\Trap\Server\Event\Event;
use Buggregator\Trap\Server\Event\EventStore;
use Buggregator\Trap\Server\Logger;
use Buggregator\Trap\Server\Proto\Frame\Binary;
use Internal\Container\Container;
use Nyholm\Psr7\Stream;
use Symfony\Component\Console\Output\BufferedOutput;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

#[Test]
#[Covers(McpSender::class)]
final class McpSenderTest
{
    public function testStoreKeepsFramesForTheTools(): void
    {
        $container = self::container();
        $container->get(McpSender::class);
        $frame = new Binary(Stream::create('data'));

        $record = $container->get(EventStore::class)->add(new Event('a', 'binary', [], 1.0), $frame);

        Assert::same($record->frames, [$frame]);
    }

    public function testTurnsOnMcpStatus(): void
    {
        $container = self::container();
        $status = $container->get(McpStatus::class);
        $before = $status->isOn();

        $container->get(McpSender::class);

        Assert::false($before);
        Assert::true($status->isOn());
    }

    public function testRendersTheNewEventsAheadOfTheSearches(): void
    {
        $container = self::container();
        $sender = $container->get(McpSender::class);
        $store = $container->get(EventStore::class);
        $cache = $container->get(TextCache::class);
        $container->get(McpStatus::class)->toolCalled();
        $store->add(new Event('a', 'binary', [], 1.0));

        $sender->process();
        $first = $cache->count();
        $store->add(new Event('b', 'binary', [], 2.0));
        $sender->process();

        Assert::same($first, 1);
        Assert::same($cache->count(), 1, 'The next slice waits for a pause.');
    }

    public function testRendersNothingBeforeAToolCall(): void
    {
        $container = self::container();
        $sender = $container->get(McpSender::class);
        $container->get(EventStore::class)->add(new Event('a', 'binary', [], 1.0));

        $sender->process();

        Assert::same($container->get(TextCache::class)->count(), 0);
    }

    public function testHostIsUnsetByDefault(): void
    {
        $endpoint = self::container()->get(McpSender::class)->httpEndpoint();

        Assert::null($endpoint->host);
        Assert::same($endpoint->port, 8000);
    }

    #[DataSet(['0.0.0.0', 'http://0.0.0.0:8000/mcp'], 'any IPv4')]
    #[DataSet(['::', 'http://[::]:8000/mcp'], 'any IPv6')]
    #[DataSet(['192.168.1.10', 'http://192.168.1.10:8000/mcp'], 'LAN address')]
    #[DataSet(['trap.internal', 'http://trap.internal:8000/mcp'], 'host name')]
    public function testWarnsOfHostBeyondLoopbackWithoutToken(string $host, string $url): void
    {
        $output = new BufferedOutput();
        $sender = self::container([], $output)->get(McpSender::class);

        $sender->listensOn($host);

        Assert::string($output->fetch())->contains($url)->contains('TRAP_MCP_TOKEN');
    }

    #[DataSet(['127.0.0.1', []], 'IPv4 loopback')]
    #[DataSet(['127.0.1.1', []], 'other IPv4 loopback')]
    #[DataSet(['::1', []], 'IPv6 loopback')]
    #[DataSet(['[::1]', []], 'IPv6 loopback in brackets')]
    #[DataSet(['LocalHost', []], 'localhost')]
    #[DataSet(['localhost.', []], 'localhost with the root dot')]
    #[DataSet(['::ffff:127.0.0.1', []], 'IPv4-mapped loopback')]
    #[DataSet(['0.0.0.0', ['TRAP_MCP_TOKEN' => 's3cr3t']], 'any host with token')]
    public function testDoesNotWarnOfLoopbackOrToken(string $host, array $environment): void
    {
        $output = new BufferedOutput();
        $sender = self::container($environment, $output)->get(McpSender::class);

        $sender->listensOn($host);

        Assert::same($output->fetch(), '');
    }

    #[DataSet([['TRAP_MCP_TOKEN' => 'env'], '<trap><mcp token="xml"/></trap>', 'env'], 'variable wins')]
    #[DataSet([[], '<trap><mcp token="xml"/></trap>', 'xml'], 'trap.xml without variable')]
    #[DataSet([['TRAP_MCP_TOKEN' => ''], '<trap><mcp token="xml"/></trap>', null], 'empty variable turns it off')]
    #[DataSet([[], '<trap><mcp/></trap>', null], 'neither')]
    public function testTokenIsTakenFromVariableThenTrapXml(array $environment, string $xml, ?string $token): void
    {
        $container = Bootstrap::init()->withConfig(xml: "<?xml version=\"1.0\"?>$xml", environment: $environment)->finish();

        $config = $container->get(Mcp::class);

        Assert::same($config->token, $token);
    }

    /**
     * @param array<non-empty-string, string> $environment
     */
    private static function container(array $environment = [], ?BufferedOutput $output = null): Container
    {
        $container = Bootstrap::init()->withConfig(environment: $environment)->finish();
        $container->set(new Modules($container));
        $container->set(new Logger($output));

        return $container;
    }
}
