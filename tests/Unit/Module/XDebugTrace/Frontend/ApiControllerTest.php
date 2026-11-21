<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\XDebugTrace\Frontend;

use Buggregator\Trap\Module\XDebugTrace\Config\Traces;
use Buggregator\Trap\Module\XDebugTrace\Frame;
use Buggregator\Trap\Module\XDebugTrace\Frontend\ApiController;
use Buggregator\Trap\Module\XDebugTrace\Frontend\Mapper;
use Buggregator\Trap\Module\XDebugTrace\TraceStorage;
use Buggregator\Trap\Server\Event\EventStore;
use Buggregator\Trap\Server\Handler\Router\Method;
use Buggregator\Trap\Server\Handler\Router\Router;
use Buggregator\Trap\Tests\Unit\Module\XDebugTrace\Trace\TraceReaderTest;
use Psr\Http\Message\ResponseInterface;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Covers(ApiController::class)]
#[Test]
final class ApiControllerTest
{
    private EventStore $events;
    private TraceStorage $storage;
    private Router $router;
    private string $uuid;

    #[BeforeTest]
    public function storeTrace(): void
    {
        $this->events = new EventStore();
        $this->storage = new TraceStorage(new Traces(), $this->events);
        $trace = $this->storage->store(\fopen(TraceReaderTest::MAIN, 'rb'));
        $this->events->add((new Mapper())->map(Frame::fromStored($trace, url: 'GET /users')));
        $this->uuid = $trace->uuid;
        $this->router = Router::compose(new ApiController($this->events, $this->storage, new Traces()));
    }

    public function testAnswersSummary(): void
    {
        $answer = $this->get("summary");

        Assert::same(\array_keys($answer), ['name', 'url', 'size', 'compressed', 'summary']);
        Assert::same($answer['url'], 'GET /users');
        Assert::same($answer['summary']['user_calls'], 32);
    }

    public function testAnswersTreeLevels(): void
    {
        $top = $this->get('tree');
        $kids = $this->get('tree?parent=' . $top['nodes'][0]['offset'] . '&limit=5');
        $more = $this->get('tree?parent=' . $top['nodes'][0]['offset'] . '&from=' . $kids['next'] . '&limit=5');

        Assert::same($top['nodes'][0]['func'], '{main}');
        Assert::same($kids['parent']['func'], '{main}');
        Assert::count($kids['nodes'], 5);
        Assert::same($more['nodes'][0]['func'], 'strtoupper');
    }

    public function testAnswersSearch(): void
    {
        $page = $this->get('search?q=fib&limit=200');

        Assert::count($page['nodes'], 25);
        Assert::null($page['next']);
        Assert::false($page['truncated']);
    }

    public function testRejectsBadArguments(): void
    {
        Assert::same($this->error('tree?parent=abc'), [400, 'parent, from and limit must be whole numbers']);
        Assert::same($this->error('tree?limit=0'), [400, 'parent, from and limit must be whole numbers']);
        Assert::same($this->error('tree?parent=72'), [400, 'The offset is not the start of a call.']);
        Assert::same($this->error('search?q=%20'), [400, 'q is required (at most 256 bytes)']);
    }

    public function testAnswersNotFound(): void
    {
        Assert::same($this->error('summary', '0190402f-7eb2-7287-82a8-897a0091f58e'), [404, 'trace not found']);
        Assert::same($this->error('tree', '0190402f-7eb2-7287-82a8-897a0091f58e'), [404, 'trace not found']);
    }

    public function testAnswersGoneFile(): void
    {
        \unlink((string) $this->storage->path($this->uuid));

        Assert::same($this->error('tree'), [404, 'trace file is gone']);
    }

    private function get(string $part): array
    {
        $result = $this->call($part, $this->uuid);
        Assert::false($result instanceof ResponseInterface);

        return \json_decode(\json_encode($result), true);
    }

    /**
     * @return array{int, string}
     */
    private function error(string $part, ?string $uuid = null): array
    {
        $result = $this->call($part, $uuid ?? $this->uuid);
        \assert($result instanceof ResponseInterface);

        return [$result->getStatusCode(), \json_decode((string) $result->getBody(), true)['error']];
    }

    private function call(string $part, string $uuid): mixed
    {
        $handler = $this->router->match(Method::Get, "api/xdebug-traces/$uuid/$part");
        \assert($handler !== null);

        return $handler();
    }
}
