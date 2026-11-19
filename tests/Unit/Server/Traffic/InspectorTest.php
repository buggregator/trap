<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Server\Traffic;

use Buggregator\Trap\Module\Mailer\Traffic\Dispatcher as SmtpDispatcher;
use Buggregator\Trap\Server\Handler\Http\Middleware;
use Buggregator\Trap\Server\Logger;
use Buggregator\Trap\Server\Proto\Buffer;
use Buggregator\Trap\Server\Socket\Client;
use Buggregator\Trap\Server\Socket\Exception\ClientDisconnected;
use Buggregator\Trap\Server\Socket\SocketStream;
use Buggregator\Trap\Server\Traffic\Dispatcher\Http;
use Buggregator\Trap\Server\Traffic\Inspector;
use Buggregator\Trap\Support\Activity;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(Inspector::class)]
final class InspectorTest
{
    private \Socket $server;
    private \Socket $remote;
    private Client $client;
    private \Fiber $clientFiber;
    private Inspector $inspector;

    #[BeforeTest]
    public function connect(): void
    {
        Activity::take();
        $this->server = \socket_create(\AF_INET, \SOCK_STREAM, \SOL_TCP);
        \socket_bind($this->server, '127.0.0.1', 0);
        \socket_listen($this->server);
        \socket_getsockname($this->server, $address, $port);

        $this->remote = \socket_create(\AF_INET, \SOCK_STREAM, \SOL_TCP);
        \socket_connect($this->remote, '127.0.0.1', $port);
        $accepted = \socket_accept($this->server);
        \assert($accepted instanceof \Socket);

        $this->client = Client::init($accepted, selectPeriod: 0.0);
        $this->inspector = new Inspector(
            new Buffer(bufferSize: 1024),
            new Logger(),
            new Http([new class implements Middleware {
                public function handle(ServerRequestInterface $request, callable $next): ResponseInterface
                {
                    return new Response(200, body: 'pong');
                }
            }]),
            new SmtpDispatcher(),
        );
        $this->inspector->addStream(SocketStream::create($this->client, 1));
        $this->clientFiber = new \Fiber($this->client->process(...));
        $this->clientFiber->start();
    }

    #[AfterTest]
    public function close(): void
    {
        $this->client->destroy();
        \socket_close($this->remote);
        \socket_close($this->server);
        Activity::take();
    }

    /**
     * A busy server reads a request late: the time it didn't read is no silence of the client, which an SMTP
     * client keeps waiting for the greeting.
     */
    public function requestReadLateByBusyServerIsNotTakenForSmtp(): void
    {
        $this->inspector->process();
        \socket_write($this->remote, "GET /ping HTTP/1.1\r\nHost: localhost\r\nContent-Length: 0\r\n\r\n");

        \usleep(600_000);
        $answer = $this->answer();

        Assert::string($answer)->startsWith('HTTP/1.1 200');
    }

    public function silentClientIsTakenForSmtp(): void
    {
        $this->inspector->process();
        $this->clientFiber->resume();

        \usleep(600_000);
        $answer = $this->answer();

        Assert::string($answer)->startsWith('220 ');
    }

    /**
     * Runs the main loop in its order, the inspector before the server, until the remote gets an answer.
     */
    private function answer(): string
    {
        \socket_set_nonblock($this->remote);
        $answer = '';
        $deadline = \microtime(true) + 5;
        while ($answer === '' && \microtime(true) < $deadline) {
            $this->inspector->process();
            try {
                $this->clientFiber->isTerminated() or $this->clientFiber->resume();
            } catch (ClientDisconnected) {
                // The answer is written before the client closes
            }
            \usleep(1_000);
            $answer = (string) @\socket_read($this->remote, 1024);
        }

        return $answer;
    }
}
