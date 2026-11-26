<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Html\Binary;

use Buggregator\Trap\Module\Frontend\Http\EventAssets;
use Buggregator\Trap\Module\Frontend\Http\Pipeline;
use Buggregator\Trap\Module\Html\Binary\AssetsController;
use Buggregator\Trap\Module\Html\Binary\Mapper;
use Buggregator\Trap\Server\Event\Event;
use Buggregator\Trap\Server\Event\EventStore;
use Buggregator\Trap\Server\Proto\Frame\Binary;
use Buggregator\Trap\Support\StreamHelper;
use Internal\Container\ObjectContainer;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Covers(AssetsController::class)]
#[Covers(EventAssets::class)]
#[Test]
final class AssetsControllerTest
{
    public function testDownloadsAllBytes(): void
    {
        $bytes = "\x1f\x8b" . \str_repeat("\x00\xff", 1000);
        [$pipeline, $store] = self::pipeline();
        $event = (new Mapper())->map(self::frame($bytes));
        $store->add($event);

        $response = self::get($pipeline, AssetsController::PATH . $event->uuid);

        Assert::same($response->getStatusCode(), 200);
        Assert::same((string) $response->getBody(), $bytes);
        Assert::same($response->getHeaderLine('Content-Type'), 'application/octet-stream');
        Assert::string($response->getHeaderLine('Content-Disposition'))->startsWith('attachment;')->contains('.gz"');
    }

    public function testNoDownloadOfOtherEvent(): void
    {
        [$pipeline, $store] = self::pipeline();
        $store->add(new Event('0145a0e0-0b1a-4e4a-9b1a', 'html', ['title' => '', 'html' => '<p>x</p>'], 1.0));

        Assert::same(self::get($pipeline, AssetsController::PATH . '0145a0e0-0b1a-4e4a-9b1a')->getStatusCode(), 404);
        Assert::same(self::get($pipeline, AssetsController::PATH . '0145a0e0-0b1a-4e4a-9b1b')->getStatusCode(), 404);
    }

    /**
     * The page of the event runs its script without an origin: it gets no bytes but those it carries.
     */
    public function testPageWithoutOriginGetsNoBytes(): void
    {
        [$pipeline, $store] = self::pipeline();
        $event = (new Mapper())->map(self::frame("\x00\x01"));
        $store->add($event);

        $response = self::get($pipeline, AssetsController::PATH . $event->uuid, ['Origin' => 'null']);

        Assert::same($response->getStatusCode(), 403);
        Assert::string((string) $response->getBody())->contains('"code":"origin_null"');
    }

    /**
     * @return array{Pipeline, EventStore}
     */
    private static function pipeline(): array
    {
        $container = new ObjectContainer();

        return [$container->get(Pipeline::class), $container->get(EventStore::class)];
    }

    /**
     * @param array<string, string> $headers
     */
    private static function get(Pipeline $pipeline, string $path, array $headers = []): ResponseInterface
    {
        return $pipeline->handle(new ServerRequest('GET', $path, $headers), static fn(): Response => new Response(418));
    }

    private static function frame(string $bytes): Binary
    {
        $stream = StreamHelper::createFileStream();
        $stream->write($bytes);

        return new Binary($stream);
    }
}
