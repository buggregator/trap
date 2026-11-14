<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Profiler\Http;

use Buggregator\Trap\Module\Profiler\BuildLock;
use Buggregator\Trap\Module\Profiler\Frame;
use Buggregator\Trap\Module\Profiler\Http\SPXTrap;
use Buggregator\Trap\Module\Profiler\SPX\ProfileBuilder;
use Buggregator\Trap\Server\Logger;
use Buggregator\Trap\Server\Traffic\Parser\Http;
use Buggregator\Trap\Test\Mock\StreamClientMock;
use Buggregator\Trap\Tests\Unit\Module\Profiler\Http\Stub\FaultyStream;
use Buggregator\Trap\Tests\Unit\Module\Profiler\Http\Stub\UnsizedStream;
use Buggregator\Trap\Tests\Unit\Module\Profiler\Http\Stub\UnsizedUpload;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

#[Covers(SPXTrap::class)]
#[Test]
final class SPXTrapTest
{
    private const STUB = __DIR__ . '/../../../../Stub/spx/handwritten';
    private const REAL = __DIR__ . '/../../../../Stub/spx/real';

    public function testStoresReport(): void
    {
        $begin = new \DateTimeImmutable('2025-05-18 12:30:00');
        $request = self::request()
            ->withQueryParams(['app_name' => 'shop', 'tags' => ['env' => 'test', 'bad' => ['x']]])
            ->withAttribute('begin_at', $begin);

        [$response, $frames] = self::handle($request);

        Assert::same($response->getStatusCode(), 200);
        Assert::count($frames, 1);
        Assert::instanceOf($frames[0], Frame::class);
        Assert::same($frames[0]->payload->type, Frame\Type::SPX);
        Assert::same($frames[0]->time, $begin);
        $profile = $frames[0]->payload->getProfile();
        // The run the report tells about, not the upload
        Assert::same($profile->date->getTimestamp(), 1747569600);
        Assert::same($profile->metadata['app_name'], 'shop');
        Assert::same($profile->tags, ['env' => 'test', 'request' => 'POST /checkout?step=2']);
        Assert::same($profile->peaks->wt, 12);
    }

    public function testTakesMetadataAsFieldAndSamplingPeriod(): void
    {
        $request = self::request(metadataAsFile: false)
            ->withParsedBody(['metadata' => self::json(), 'sampling_period' => '250']);

        [$response, $frames] = self::handle($request);

        Assert::same($response->getStatusCode(), 200);
        Assert::same($frames[0]->payload->getProfile()->metadata['sampling_period'], 250);
    }

    public function testStoresFormParsedByServer(): void
    {
        $boundary = 'spx-boundary';
        $body = "--$boundary\r\n"
            . "Content-Disposition: form-data; name=\"metadata\"; filename=\"report.json\"\r\n"
            . "Content-Type: application/json\r\n\r\n"
            . self::json() . "\r\n"
            . "--$boundary\r\n"
            . "Content-Disposition: form-data; name=\"trace\"; filename=\"report.txt.gz\"\r\n"
            . "Content-Type: application/gzip\r\n\r\n"
            . self::trace() . "\r\n"
            . "--$boundary--\r\n";
        $raw = "POST /api/profiler/spx HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Content-Type: multipart/form-data; boundary=$boundary\r\n"
            . 'Content-Length: ' . \strlen($body) . "\r\n\r\n"
            . $body;

        [$response, $frames] = self::handle(self::parse($raw));

        Assert::same($response->getStatusCode(), 200);
        Assert::same($frames[0]->payload->getProfile()->peaks->wt, 12);
    }

    public function testTakesParametersFromFormFieldsOverQuery(): void
    {
        $boundary = 'spx-boundary';
        $field = static fn(string $name, string $value): string => "--$boundary\r\n"
            . "Content-Disposition: form-data; name=\"$name\"\r\n\r\n$value\r\n";
        $body = $field('app_name', 'form-app') . $field('tags[env]', 'form') . $field('tags[team]', 'core')
            . $field('sampling_period', '100')
            . "--$boundary\r\n"
            . "Content-Disposition: form-data; name=\"metadata\"; filename=\"report.json\"\r\n\r\n"
            . self::json() . "\r\n"
            . "--$boundary\r\n"
            . "Content-Disposition: form-data; name=\"trace\"; filename=\"report.txt.gz\"\r\n\r\n"
            . self::trace() . "\r\n"
            . "--$boundary--\r\n";
        $raw = "POST /api/profiler/spx?app_name=query-app&tags[env]=query&tags[region]=eu HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Content-Type: multipart/form-data; boundary=$boundary\r\n"
            . 'Content-Length: ' . \strlen($body) . "\r\n\r\n"
            . $body;

        [$response, $frames] = self::handle(self::parse($raw));

        Assert::same($response->getStatusCode(), 200);
        $profile = $frames[0]->payload->getProfile();
        Assert::same($profile->metadata['app_name'], 'form-app');
        Assert::same($profile->metadata['sampling_period'], 100);
        Assert::same(
            $profile->tags,
            ['env' => 'form', 'region' => 'eu', 'team' => 'core', 'request' => 'POST /checkout?step=2'],
        );
    }

    public function testPassesOtherRequestsThrough(): void
    {
        foreach ([self::request('/api/other'), new ServerRequest('GET', '/api/profiler/spx')] as $request) {
            [$response, $frames, $log] = self::handle($request);

            Assert::same($response->getStatusCode(), 404);
            Assert::same($frames, []);
            Assert::same($log, '');
        }
    }

    public function testRejectsBodyThatIsNotForm(): void
    {
        $request = new ServerRequest('POST', '/api/profiler/spx', ['Content-Type' => 'application/json'], self::json());

        [$response, $frames, $log] = self::handle($request);

        Assert::same($response->getStatusCode(), 400);
        Assert::same((string) $response->getBody(), 'Send the report as multipart/form-data with the `metadata` and `trace` parts.');
        Assert::same($frames, []);
        Assert::string($log)->contains('SPX: profile rejected with 400: send the report as multipart/form-data');
    }

    #[DataSet(['trace', 'No `trace` file part: the `.txt.gz` file of the report.'], 'no trace')]
    #[DataSet(['metadata', 'No `metadata` part: the `.json` file of the report.'], 'no metadata')]
    public function testRejectsMissingPart(string $part, string $message): void
    {
        $request = self::request();
        $files = $request->getUploadedFiles();
        unset($files[$part]);

        [$response, $frames] = self::handle($request->withUploadedFiles($files));

        Assert::same($response->getStatusCode(), 400);
        Assert::same((string) $response->getBody(), $message);
        Assert::same($frames, []);
    }

    public function testRejectsInvalidMetadata(): void
    {
        $request = self::request(metadataAsFile: false)->withParsedBody(['metadata' => '{"key": 1}']);

        [$response] = self::handle($request);

        Assert::same($response->getStatusCode(), 400);
        Assert::same((string) $response->getBody(), 'No `enabled_metrics` list in the metadata.');
    }

    public function testRejectsTruncatedTrace(): void
    {
        $request = self::request(trace: \substr(self::trace(), 0, -20));

        [$response] = self::handle($request);

        Assert::same($response->getStatusCode(), 400);
        Assert::same((string) $response->getBody(), 'The trace is truncated: the gzip stream does not end.');
    }

    #[DataSet(['0'], 'zero')]
    #[DataSet(['1.5'], 'fraction')]
    #[DataSet(['-5'], 'negative')]
    public function testRejectsInvalidSamplingPeriod(string $period): void
    {
        [$response] = self::handle(self::request()->withQueryParams(['sampling_period' => $period]));

        Assert::same($response->getStatusCode(), 400);
        Assert::same((string) $response->getBody(), 'The `sampling_period` must be a positive number of microseconds.');
    }

    public function testRejectsOversizedTrace(): void
    {
        [$response, $frames, $log] = self::handle(self::request(), new ProfileBuilder(maxSize: 16));

        Assert::same($response->getStatusCode(), 413);
        Assert::same($frames, []);
        Assert::string($log)->contains('SPX: profile rejected with 413: the trace is larger than 16 bytes.');
    }

    public function testRejectsOversizedBody(): void
    {
        $request = self::request()->withBody(Stream::create(\str_repeat('x', 200_000)));

        [$response] = self::handle($request, new ProfileBuilder(maxSize: 16));

        Assert::same($response->getStatusCode(), 413);
        Assert::same((string) $response->getBody(), 'The body is too large.');
    }

    public function testStoresReportOfUnknownSize(): void
    {
        $request = self::request()->withBody(UnsizedStream::of('form'))->withUploadedFiles([
            'metadata' => [UnsizedUpload::of(self::json())],
            'trace' => [UnsizedUpload::of(self::trace())],
        ]);

        [$response, $frames] = self::handle($request);

        Assert::same($response->getStatusCode(), 200);
        Assert::same($frames[0]->payload->getProfile()->peaks->wt, 12);
    }

    public function testRejectsTraceOfUnknownSizeOverLimitAsItIsRead(): void
    {
        $request = self::request()->withBody(UnsizedStream::of('form'))->withUploadedFiles([
            'metadata' => [self::file(self::json())],
            'trace' => [UnsizedUpload::of(self::trace())],
        ]);

        [$response, $frames] = self::handle($request, new ProfileBuilder(maxSize: 16));

        Assert::same($response->getStatusCode(), 413);
        Assert::same((string) $response->getBody(), 'The trace is larger than 16 bytes.');
        Assert::same($frames, []);
    }

    public function testRejectsMetadataOfUnknownSizeOverLimit(): void
    {
        $request = self::request()->withUploadedFiles([
            'metadata' => [UnsizedUpload::of(\str_repeat(' ', 70_000))],
            'trace' => [self::file(self::trace())],
        ]);

        [$response] = self::handle($request);

        Assert::same($response->getStatusCode(), 400);
        Assert::same((string) $response->getBody(), 'The metadata is larger than 65536 bytes.');
    }

    public function testTellsTheClientTrapHasNoZlib(): void
    {
        $middleware = new SPXTrap(new ProfileBuilder(), new Logger(), new BuildLock(), zlib: false);

        $response = $middleware->handle(self::request(), static fn() => new Response(404));

        Assert::same($response->getStatusCode(), 501);
        Assert::same((string) $response->getBody(), 'Trap can not read SPX traces: its PHP has no ext-zlib.');
    }

    public function testRejectsTraceThatIsNotGzip(): void
    {
        [$response, $frames] = self::handle(self::request(trace: (string) \file_get_contents(self::STUB . '.txt')));

        Assert::same($response->getStatusCode(), 400);
        Assert::same((string) $response->getBody(), 'The trace is not gzip data, as SPX writes it.');
        Assert::same($frames, []);
    }

    public function testInflatesTracesUpTo256MegabytesByDefault(): void
    {
        Assert::same((new ProfileBuilder())->maxTraceSize, 256 * 1024 * 1024);
    }

    public function testStopsAtStreamThatGivesNothing(): void
    {
        $request = self::request()->withUploadedFiles([
            'metadata' => [self::file(self::json())],
            'trace' => [new UploadedFile(FaultyStream::stalled(), 1, \UPLOAD_ERR_OK)],
        ]);

        [$response] = self::handle($request);

        Assert::same($response->getStatusCode(), 400);
        Assert::same((string) $response->getBody(), 'The trace is truncated: the gzip stream does not end.');
    }

    public function testTellsTheClientNoDetailsOfUnexpectedErrors(): void
    {
        $request = self::request()->withUploadedFiles([
            'metadata' => [self::file(self::json())],
            'trace' => [new UploadedFile(FaultyStream::throwing(), 1, \UPLOAD_ERR_OK)],
        ]);

        [$response, , $log] = self::handle($request);

        Assert::same($response->getStatusCode(), 500);
        Assert::same((string) $response->getBody(), 'Unable to store the report, see the Trap log.');
        Assert::string($log)->contains('/var/tmp/secret/upload-42');
    }

    public function testRejectsOversizedMetadata(): void
    {
        $request = self::request();
        $files = $request->getUploadedFiles();
        $files['metadata'] = [self::file(\str_repeat(' ', 70_000))];

        [$response] = self::handle($request->withUploadedFiles($files));

        Assert::same($response->getStatusCode(), 400);
        Assert::same((string) $response->getBody(), 'The metadata is larger than 65536 bytes.');
    }

    public function testRejectsReportWhenTooManyBuildsWait(): void
    {
        $lock = new BuildLock(maxWaiting: 0);
        $building = new \Fiber(static fn() => $lock->run(static fn() => \Fiber::suspend()));
        $building->start();

        [$response, $frames, $log] = self::handle(self::request(), lock: $lock);

        Assert::same($response->getStatusCode(), 503);
        Assert::same($frames, []);
        Assert::string($log)->contains('SPX: profile rejected with 503: 0 profile builds are waiting already.');
    }

    public function testWarnsThatProfileIsCompacted(): void
    {
        [$response, , $log] = self::handle(self::request(), new ProfileBuilder(maxNodes: 1));

        Assert::same($response->getStatusCode(), 200);
        Assert::string($log)->matchesRegex('/SPX: the profile is compacted to 1 call tree nodes: /');
    }

    public function testStoresRealReportSentAsForm(): void
    {
        // As `curl -F metadata=@….json -F trace=@….txt.gz` sends a report recorded with ext-spx
        $boundary = '------------------------spx';
        $body = "--$boundary\r\n"
            . "Content-Disposition: form-data; name=\"metadata\"; filename=\"http.json\"\r\n"
            . "Content-Type: application/octet-stream\r\n\r\n"
            . (string) \file_get_contents(self::REAL . '/http.json') . "\r\n"
            . "--$boundary\r\n"
            . "Content-Disposition: form-data; name=\"trace\"; filename=\"http.txt.gz\"\r\n"
            . "Content-Type: application/gzip\r\n\r\n"
            . (string) \file_get_contents(self::REAL . '/http.txt.gz') . "\r\n"
            . "--$boundary--\r\n";
        $raw = "POST /api/profiler/spx?tags%5Benv%5D=test HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Content-Type: multipart/form-data; boundary=$boundary\r\n"
            . 'Content-Length: ' . \strlen($body) . "\r\n\r\n"
            . $body;

        [$response, $frames] = self::handle(self::parse($raw));

        Assert::same($response->getStatusCode(), 200);
        $profile = $frames[0]->payload->getProfile();
        Assert::same($profile->tags, ['env' => 'test', 'request' => 'POST /checkout?step=2']);
        Assert::same($profile->metadata['details']['Custom metadata'], "release=1.2.3\tname=Café\x01");
        Assert::same($profile->calls->callers('handle')[0]->caller, 'main()');
    }

    private static function json(): string
    {
        return (string) \file_get_contents(self::STUB . '.json');
    }

    private static function trace(): string
    {
        return (string) \gzencode((string) \file_get_contents(self::STUB . '.txt'), 1);
    }

    private static function file(string $content): UploadedFile
    {
        return new UploadedFile(Stream::create($content), \strlen($content), \UPLOAD_ERR_OK);
    }

    private static function request(
        string $path = '/api/profiler/spx',
        ?string $trace = null,
        bool $metadataAsFile = true,
    ): ServerRequestInterface {
        $files = ['trace' => [self::file($trace ?? self::trace())]];
        $metadataAsFile and $files['metadata'] = [self::file(self::json())];

        return (new ServerRequest('POST', $path, ['Content-Type' => 'multipart/form-data; boundary=x'], 'form'))
            ->withUploadedFiles($files);
    }

    private static function parse(string $raw): ServerRequestInterface
    {
        $stream = StreamClientMock::createFromGenerator((static function () use ($raw): \Generator {
            yield $raw;
        })());
        $fiber = new \Fiber(static fn(): ServerRequestInterface => (new Http())->parseStream($stream));
        $fiber->start();
        while (!$fiber->isTerminated()) {
            $fiber->resume();
        }

        return $fiber->getReturn();
    }

    /**
     * @return array{ResponseInterface, list<mixed>, string} The response, the frames the middleware suspended with
     *         and the log.
     */
    private static function handle(
        ServerRequestInterface $request,
        ProfileBuilder $builder = new ProfileBuilder(),
        ?\Closure $next = null,
        BuildLock $lock = new BuildLock(),
    ): array {
        $output = new BufferedOutput();
        $middleware = new SPXTrap($builder, new Logger($output), $lock);
        $next ??= static fn(): ResponseInterface => new Response(404);
        $fiber = new \Fiber(static fn(): ResponseInterface => $middleware->handle($request, $next));

        // A null lets the other fibers run during a long build
        $frames = [];
        $frame = $fiber->start();
        while (!$fiber->isTerminated()) {
            $frame === null or $frames[] = $frame;
            $frame = $fiber->resume();
        }

        return [$fiber->getReturn(), $frames, $output->fetch()];
    }
}
