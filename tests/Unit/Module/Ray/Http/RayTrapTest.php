<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Ray\Http;

use Buggregator\Trap\Module\Frontend\UiChannel;
use Buggregator\Trap\Module\Ray\Frame;
use Buggregator\Trap\Module\Ray\Http\RayTrap;
use Buggregator\Trap\Module\Ray\Lock\Locks;
use Buggregator\Trap\Server\Handler\Http\Handler\Fallback;
use Buggregator\Trap\Tests\Unit\Module\Ray\FrameTest;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Covers(RayTrap::class)]
#[Test]
final class RayTrapTest
{
    private const CLIENT = ['User-Agent' => 'Ray 1.0', 'Content-Type' => 'application/json'];
    private const LOCK = '5d41402abc4b2a76b9719d911017c592';

    public function testAvailabilityCheckAnswersWithErrorAndIsNotDumped(): void
    {
        [$response, $frames] = self::handle(new RayTrap(new Locks(), self::ui()), new ServerRequest('GET', '/_availability_check'));

        // spatie/ray treats any HTTP error status as "the server is available"
        Assert::same($response->getStatusCode(), 400);
        Assert::true($response->hasHeader(Fallback::NO_DUMP_HEADER));
        Assert::same($frames, []);
    }

    public function testPayloadsBecomeFrameAndCreateLocks(): void
    {
        $locks = new Locks();
        $time = new \DateTimeImmutable('2026-01-02 03:04:05');
        $request = (new ServerRequest('POST', '/', self::CLIENT, FrameTest::REQUEST))->withAttribute('begin_at', $time);

        [$response, $frames] = self::handle(new RayTrap($locks, self::ui()), $request);

        Assert::same($response->getStatusCode(), 200);
        Assert::count($frames, 1);
        Assert::instanceOf($frames[0], Frame::class);
        Assert::same($frames[0]->time, $time);
        Assert::true($locks->poll(self::LOCK)?->isPending());
    }

    /**
     * Nothing could release a held client: the Continue and Stop buttons are in the UI.
     */
    public function testWithoutUiPauseDoesNotHoldClient(): void
    {
        $locks = new Locks();
        $trap = new RayTrap($locks, new UiChannel());

        [$response, $frames] = self::handle($trap, new ServerRequest('POST', '/', self::CLIENT, FrameTest::REQUEST));
        [$poll] = self::handle($trap, new ServerRequest('GET', '/locks/' . self::LOCK, self::CLIENT));

        Assert::same($response->getStatusCode(), 200);
        Assert::count($frames, 1);
        Assert::same($locks->count(), 0);
        Assert::same(\json_decode((string) $poll->getBody(), true), ['active' => false, 'stop_execution' => false]);
    }

    public function testPassesEmptyPayloadListOn(): void
    {
        $request = new ServerRequest('POST', '/', ['Content-Type' => 'application/json'], '{"uuid":"abc","payloads":[]}');

        [$response, $frames] = self::handle(new RayTrap(new Locks(), self::ui()), $request);

        Assert::same($response->getStatusCode(), 404);
        Assert::same($frames, []);
    }

    public function testRecognizesPayloadsWithoutClientUserAgent(): void
    {
        $request = new ServerRequest('POST', '/', ['Content-Type' => 'application/json'], FrameTest::REQUEST);

        [, $frames] = self::handle(new RayTrap(new Locks(), self::ui()), $request);

        Assert::count($frames, 1);
    }

    public function testPassesOtherJsonOn(): void
    {
        $request = new ServerRequest('POST', '/', ['Content-Type' => 'application/json'], '{"foo":"bar"}');

        [$response, $frames] = self::handle(new RayTrap(new Locks(), self::ui()), $request);

        Assert::same($response->getStatusCode(), 404);
        Assert::same($frames, []);
    }

    public function testRejectsInvalidRequestOfClient(): void
    {
        [$response, $frames] = self::handle(new RayTrap(new Locks(), self::ui()), new ServerRequest('POST', '/', self::CLIENT, '{'));

        Assert::same($response->getStatusCode(), 400);
        Assert::same($frames, []);
    }

    public function testLockPollStates(): void
    {
        $locks = new Locks();
        $trap = new RayTrap($locks, self::ui());
        $poll = static fn(): array => \json_decode(
            (string) self::handle($trap, new ServerRequest('GET', '/locks/' . self::LOCK . '?hostname=box&project_name=', self::CLIENT))[0]->getBody(),
            true,
        );

        // An unknown lock lets the client continue
        Assert::same($poll(), ['active' => false, 'stop_execution' => false]);

        $locks->create(self::LOCK);
        Assert::same($poll(), ['active' => true, 'stop_execution' => false]);

        $locks->resolve(self::LOCK, stop: true);
        Assert::same($poll(), ['active' => false, 'stop_execution' => true]);
    }

    public function testLockPollIsNotDumped(): void
    {
        [$response, $frames] = self::handle(new RayTrap(new Locks(), self::ui()), new ServerRequest('GET', '/locks/abc', self::CLIENT));

        Assert::same($response->getHeaderLine('Content-Type'), 'application/json');
        Assert::true($response->hasHeader(Fallback::NO_DUMP_HEADER));
        Assert::same($frames, []);
    }

    public function testUnknownLockPathOfOtherClientPassesOn(): void
    {
        [$response] = self::handle(new RayTrap(new Locks(), self::ui()), new ServerRequest('GET', '/locks/abc'));

        Assert::same($response->getStatusCode(), 404);
    }

    public function testKnownLockIsAnsweredToAnyClient(): void
    {
        $locks = new Locks();
        $locks->create('abc');

        [$response] = self::handle(new RayTrap($locks, self::ui()), new ServerRequest('GET', '/locks/abc'));

        Assert::same(\json_decode((string) $response->getBody(), true), ['active' => true, 'stop_execution' => false]);
    }

    private static function ui(): UiChannel
    {
        $ui = new UiChannel();
        $ui->enable();

        return $ui;
    }

    /**
     * Runs the middleware the way {@see Fallback} does: in a fiber that suspends with frames.
     *
     * @return array{ResponseInterface, list<Frame>}
     */
    private static function handle(RayTrap $trap, ServerRequestInterface $request): array
    {
        $frames = [];
        $fiber = new \Fiber(static fn(): ResponseInterface => $trap->handle($request, static fn(): Response => new Response(404)));
        $got = $fiber->start();
        while (!$fiber->isTerminated()) {
            $got instanceof Frame and $frames[] = $got;
            $got = $fiber->resume();
        }

        return [$fiber->getReturn(), $frames];
    }
}
