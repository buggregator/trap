<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\XDebugTrace\Mcp;

use Buggregator\Trap\Module\XDebugTrace\Config\Traces as Config;
use Buggregator\Trap\Module\XDebugTrace\Frame;
use Buggregator\Trap\Module\XDebugTrace\Frontend\Mapper;
use Buggregator\Trap\Module\XDebugTrace\Mcp\TraceCall;
use Buggregator\Trap\Module\XDebugTrace\Mcp\TraceCalls;
use Buggregator\Trap\Module\XDebugTrace\Mcp\Traces;
use Buggregator\Trap\Module\XDebugTrace\Mcp\TraceSummary;
use Buggregator\Trap\Module\XDebugTrace\Mcp\TraceView;
use Buggregator\Trap\Module\XDebugTrace\TraceStorage;
use Buggregator\Trap\Server\Event\EventStore;
use Buggregator\Trap\Tests\Unit\Module\XDebugTrace\Trace\TraceReaderTest;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Covers(Traces::class)]
#[Covers(TraceSummary::class)]
#[Covers(TraceCalls::class)]
#[Covers(TraceCall::class)]
#[Covers(TraceView::class)]
#[Test]
final class TraceToolsTest
{
    private EventStore $events;
    private Traces $traces;
    private string $uuid;

    #[BeforeTest]
    public function storeTrace(): void
    {
        $this->events = new EventStore();
        $storage = new TraceStorage(new Config(), $this->events);
        $trace = $storage->store(\fopen(TraceReaderTest::MAIN, 'rb'));
        $this->events->add((new Mapper())->map(Frame::fromStored($trace, name: 'import')));
        $this->uuid = $trace->uuid;
        $this->traces = new Traces($this->events, $storage, new Config());
    }

    public function testSummarizesTrace(): void
    {
        $result = (new TraceSummary($this->traces))->call(['uuid' => $this->uuid]);

        Assert::same($result['name'], 'import');
        Assert::same($result['summary']['calls'], 39);
    }

    public function testWalksTreeAndSearches(): void
    {
        $tool = new TraceCalls($this->traces);

        $top = $tool->call(['uuid' => $this->uuid]);
        $kids = $tool->call(['uuid' => $this->uuid, 'parent' => $top['nodes'][0]['offset'], 'limit' => 3]);
        $found = $tool->call(['uuid' => $this->uuid, 'query' => 'strtoupper']);

        Assert::same($top['nodes'][0]['func'], '{main}');
        Assert::count($kids['nodes'], 3);
        Assert::int($kids['next'])->greaterThan(0);
        Assert::count($found['nodes'], 2);
        Assert::count($tool->call(['uuid' => $this->uuid, 'parent' => 71, 'limit' => 0])['nodes'], 13);
        Assert::same($found['nodes'][0]['path'][0]['func'], '{main}');
    }

    public function testShowsOneCallWithCallees(): void
    {
        $result = (new TraceCall($this->traces))->call(['uuid' => $this->uuid, 'offset' => 71]);

        Assert::same($result['call']['func'], '{main}');
        Assert::count($result['callees'], 13);
        Assert::null($result['from']);
    }

    public function testRejectsUnknownTrace(): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('No Xdebug trace with UUID `nope`');

        (new TraceSummary($this->traces))->call(['uuid' => 'nope']);
    }

    public function testRejectsNegativeOffset(): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage('`offset` must be a whole number.');

        (new TraceCall($this->traces))->call(['uuid' => $this->uuid, 'offset' => -1]);
    }

    public function testSummarizesEventForListings(): void
    {
        $line = (new TraceView())->summary($this->events->get($this->uuid));

        Assert::same($line, 'import, 1.43 ms, 39 calls');
    }
}
