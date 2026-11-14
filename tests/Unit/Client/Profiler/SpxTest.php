<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Client\Profiler;

use Buggregator\Trap\Client\Profiler\Format;
use Buggregator\Trap\Client\Profiler\Internal\Model\SpeedscopeWriter;
use Buggregator\Trap\Client\Profiler\Internal\Model\SpxReport;
use Buggregator\Trap\Client\Profiler\Internal\Recorded;
use Buggregator\Trap\Client\Profiler\Internal\ServerSender;
use Buggregator\Trap\Client\Profiler\Internal\SpxConfig;
use Buggregator\Trap\Client\Profiler\Internal\SpxExtension;
use Buggregator\Trap\Client\Profiler\Internal\TrapSender;
use Buggregator\Trap\Client\Profiler\Internal\Warnings;
use Buggregator\Trap\Client\Profiler\Metadata;
use Buggregator\Trap\Client\Profiler\Profile;
use Buggregator\Trap\Client\Profiler\Spx;
use Buggregator\Trap\Client\TrapHandle;
use Buggregator\Trap\Module\Profiler\BuildLock;
use Buggregator\Trap\Module\Profiler\Frame;
use Buggregator\Trap\Module\Profiler\Http\SPXTrap;
use Buggregator\Trap\Module\Profiler\SPX\ProfileBuilder;
use Buggregator\Trap\Server\Logger;
use Buggregator\Trap\Support\Spx\Archive;
use Buggregator\Trap\Support\Spx\Metadata as SpxMetadata;
use Buggregator\Trap\Tests\Unit\Client\Profiler\Stub\BrokenStreamDriver;
use Buggregator\Trap\Tests\Unit\Client\Profiler\Stub\FakeDriver;
use Buggregator\Trap\Tests\Unit\Client\Profiler\Stub\FakeSpxExtension;
use Buggregator\Trap\Tests\Unit\Client\Profiler\Stub\HttpCatcher;
use Buggregator\Trap\Tests\Unit\Client\Profiler\Stub\LogCapture;
use Buggregator\Trap\Tests\Unit\Client\Profiler\Stub\PprofDecoder;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Core\Exception\SkipTest;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(Spx::class)]
#[Covers(SpxConfig::class)]
#[Covers(SpxReport::class)]
#[Covers(SpeedscopeWriter::class)]
final class SpxTest
{
    private const STUB = __DIR__ . '/../../../Stub/spx/handwritten';
    private const KEY = 'spx-full-20250518_120000-app-42-1804289383';

    private string $dir = '';

    #[BeforeTest]
    public function createDir(): void
    {
        $this->dir = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . \uniqid('trap-spx-', true);
        \mkdir($this->dir);
        \file_put_contents($this->dir . '/' . self::KEY . '.json', (string) \file_get_contents(self::STUB . '.json'));
        \file_put_contents($this->dir . '/' . self::KEY . '.txt.gz', \gzencode((string) \file_get_contents(self::STUB . '.txt'), 1));
    }

    #[AfterTest]
    public function removeDir(): void
    {
        $this->emptyDir();
        \rmdir($this->dir);
    }

    public function isUnavailableWithoutTheExtension(): void
    {
        \function_exists('spx_profiler_start') and throw new SkipTest('ext-spx is loaded');

        Assert::false((new Spx())->isAvailable());
    }

    /**
     * @param array<string, string> $settings
     */
    #[DataSet([true, ['SPX_ENABLED' => '1', 'SPX_AUTO_START' => '0', 'SPX_REPORT' => 'full'], null], 'CLI ready')]
    #[DataSet([true, [], 'SPX is not enabled: set `SPX_ENABLED=1` in the environment of the process.'], 'CLI not enabled')]
    #[DataSet([true, ['SPX_ENABLED' => '1'], 'SPX profiles the whole run by itself: set `SPX_AUTO_START=0` in the environment of the process.'], 'CLI auto start')]
    #[DataSet([true, ['SPX_ENABLED' => '1', 'SPX_AUTO_START' => '0'], 'SPX writes no full report: set `SPX_REPORT=full` in the environment of the process.'], 'CLI flat profile')]
    #[DataSet([false, [], null], 'web: SPX checks it')]
    public function tellsWhatTheProcessMisses(bool $cli, array $settings, ?string $problem): void
    {
        $config = new SpxConfig($cli, static fn(string $name): ?string => $settings[$name] ?? null);

        Assert::same($config->problem(), $problem);
    }

    public function startsAndStopsSpxAroundTheCode(): void
    {
        $this->emptyDir();
        SpxExtension::$override = $extension = new FakeSpxExtension($this->dir, self::STUB);
        $received = null;

        try {
            TrapHandle::profile(new Spx())
                ->to(static function (Profile $profile) use (&$received): void {
                    $received = $profile;
                })
                ->run(static fn() => null);
        } finally {
            SpxExtension::$override = null;
        }

        Assert::same($extension->calls, ['start', 'stop']);
        Assert::instanceOf($received, Profile::class);
        Assert::same($received->formats()[0], Format::Spx);
        // Out of the way of a Trap and the SPX web UI, which lists `*.json`, and deleted once the delivered profile
        // is not used
        Assert::same($this->files(), ['.trap-' . self::KEY . '.metadata', '.trap-' . self::KEY . '.txt.gz']);
        unset($received);
        Assert::same($this->files(), []);
    }

    public function writeFailingHalfWayLeavesTheFileAsItWas(): void
    {
        $path = $this->dir . '/profile.spx.tar';
        \file_put_contents($path, 'the previous profile');
        $log = new LogCapture();
        Warnings::$logger = $log;
        Warnings::reset();

        try {
            TrapHandle::profile(new BrokenStreamDriver())->toFile($path)->run(static fn() => null);
        } finally {
            Warnings::$logger = null;
        }

        Assert::same(\file_get_contents($path), 'the previous profile');
        // No temporary file is left
        Assert::same($this->files(), ['profile.spx.tar', self::KEY . '.json', self::KEY . '.txt.gz']);
        Assert::string($log->messages())->contains('The trace is gone.');
    }

    public function keepsReportNoDestinationTook(): void
    {
        $this->emptyDir();
        SpxExtension::$override = new FakeSpxExtension($this->dir, self::STUB);
        $log = new LogCapture();
        Warnings::$logger = $log;
        Warnings::reset();

        try {
            TrapHandle::profile(new Spx())
                ->to(static fn() => throw new \RuntimeException('Trap is down.'))
                ->run(static fn() => null);
        } finally {
            SpxExtension::$override = null;
            Warnings::$logger = null;
        }

        // Put back where SPX wrote it, for an observing Trap and the SPX web UI
        Assert::same($this->files(), [self::KEY . '.json', self::KEY . '.txt.gz']);
        Assert::same(\file_get_contents($this->dir . '/' . self::KEY . '.json'), \file_get_contents(self::STUB . '.json'));
        Assert::string($log->messages())->contains('The SPX report is not delivered, and is kept as `');
    }

    public function putsBackReportsLeftAsideAtShutdown(): void
    {
        // A fatal error skips the destructors and the delivery; the shutdown functions still run
        $report = SpxReport::read($this->dir, self::KEY, keep: false);
        Assert::same($this->files(), ['.trap-' . self::KEY . '.metadata', '.trap-' . self::KEY . '.txt.gz']);
        $log = new LogCapture();
        Warnings::$logger = $log;
        Warnings::reset();

        try {
            SpxReport::shutdown();
        } finally {
            Warnings::$logger = null;
        }

        Assert::same($this->files(), [self::KEY . '.json', self::KEY . '.txt.gz']);
        Assert::string($log->messages())->contains('The SPX report is not delivered, and is kept as `');
        unset($report);
        Assert::same($this->files(), [self::KEY . '.json', self::KEY . '.txt.gz']);
    }

    public function deletesReportsDeliveredAtShutdown(): void
    {
        $report = SpxReport::read($this->dir, self::KEY, keep: false);
        $report->settle(true);

        SpxReport::shutdown();

        Assert::same($this->files(), []);
    }

    public function leavesReportItCanNotMoveAsideForTheObserver(): void
    {
        // Sent and left in place, it would arrive twice with a Trap observing the directory
        \mkdir($this->dir . '/.trap-' . self::KEY . '.txt.gz');

        try {
            SpxReport::read($this->dir, self::KEY, keep: false);
            Assert::fail('The report is read.');
        } catch (\RuntimeException $e) {
            Assert::string($e->getMessage())->contains("can't be moved aside, and is left for a Trap observing the directory");
        } finally {
            \rmdir($this->dir . '/.trap-' . self::KEY . '.txt.gz');
        }

        Assert::same($this->files(), [self::KEY . '.json', self::KEY . '.txt.gz']);
    }

    public function warnsWhereTheReportIsWhenItCanNotBePutBack(): void
    {
        $report = SpxReport::read($this->dir, self::KEY, keep: false);
        \mkdir($this->dir . '/' . self::KEY . '.txt.gz');

        try {
            $warning = $report->settle(false);
        } finally {
            \rmdir($this->dir . '/' . self::KEY . '.txt.gz');
        }

        Assert::string((string) $warning)->contains("can't be put back: its trace is `")->contains('.trap-' . self::KEY . '.metadata');
        unset($report);
    }

    public function leavesNoMetadataWithoutTraceWhenItCanNotBePutBack(): void
    {
        $report = SpxReport::read($this->dir, self::KEY, keep: false);
        \mkdir($this->dir . '/' . self::KEY . '.json');

        try {
            $warning = $report->settle(false);
        } finally {
            \rmdir($this->dir . '/' . self::KEY . '.json');
        }

        Assert::same($this->files(), ['.trap-' . self::KEY . '.metadata', self::KEY . '.txt.gz']);
        Assert::string((string) $warning)->contains("can't be put back: its trace is `")->contains('.trap-' . self::KEY . '.metadata');
        unset($report);
    }

    public function putsBackReportChangedForAnObservingTrap(): void
    {
        // A Trap that listed the report before it was taken aside takes it again only once it changed
        $before = \time() - 100;
        \touch($this->dir . '/' . self::KEY . '.json', $before);
        \touch($this->dir . '/' . self::KEY . '.txt.gz', $before);
        $report = SpxReport::read($this->dir, self::KEY, keep: false);

        $report->settle(false);

        \clearstatcache();
        Assert::true((int) \filemtime($this->dir . '/' . self::KEY . '.txt.gz') > $before);
        Assert::true((int) \filemtime($this->dir . '/' . self::KEY . '.json') > $before);
        unset($report);
    }

    public function putsBackReportChangedWithinTheSecondSpxWroteIt(): void
    {
        // Touched now, a report written this second would keep its time
        $now = \time();
        \touch($this->dir . '/' . self::KEY . '.json', $now);
        \touch($this->dir . '/' . self::KEY . '.txt.gz', $now);
        $report = SpxReport::read($this->dir, self::KEY, keep: false);

        $report->settle(false);

        \clearstatcache();
        Assert::true((int) \filemtime($this->dir . '/' . self::KEY . '.txt.gz') > $now);
        Assert::true((int) \filemtime($this->dir . '/' . self::KEY . '.json') > $now);
        unset($report);
    }

    public function dropsTheReportOfAnEmptyProfile(): void
    {
        $this->emptyDir();
        $stub = $this->dir . '/empty';
        \file_put_contents("$stub.json", \str_replace('"recorded_call_count": 6', '"recorded_call_count": 0', (string) \file_get_contents(self::STUB . '.json')));
        \copy(self::STUB . '.txt', "$stub.txt");
        SpxExtension::$override = new FakeSpxExtension($this->dir, $stub);
        $log = new LogCapture();
        Warnings::$logger = $log;
        Warnings::reset();

        try {
            $profile = TrapHandle::profile(new Spx())->to(static fn() => null)->run(static fn() => null);
        } finally {
            SpxExtension::$override = null;
            Warnings::$logger = null;
        }

        Assert::null($profile);
        Assert::string($log->messages())->contains('The `spx` profile is empty and is not delivered');
        // Not put back for an observing Trap to show
        Assert::same($this->files(), ['empty.json', 'empty.txt']);
    }

    public function warnsWhenSpxMakesNoReport(): void
    {
        $this->emptyDir();
        SpxExtension::$override = $extension = new FakeSpxExtension($this->dir, self::STUB, reports: false);
        $log = new LogCapture();
        Warnings::$logger = $log;
        Warnings::reset();

        try {
            $result = TrapHandle::profile(new Spx())->to(static fn() => null)->run(static fn(): int => 7);
        } finally {
            SpxExtension::$override = null;
            Warnings::$logger = null;
        }

        Assert::same($result, 7);
        Assert::same($extension->calls, ['start', 'stop']);
        Assert::string($log->messages())->contains(Spx::NO_REPORT);
    }

    public function isUnavailableWhenSpxCanNotWriteItsReport(): void
    {
        $dir = $this->dir . '/missing/spx';
        SpxExtension::$override = new FakeSpxExtension($dir, self::STUB);
        $log = new LogCapture();
        Warnings::$logger = $log;
        Warnings::reset();

        try {
            $available = (new Spx())->isAvailable();
        } finally {
            SpxExtension::$override = null;
            Warnings::$logger = null;
        }

        Assert::false($available);
        Assert::string($log->messages())->contains("SPX can't write its report to `$dir`.");
    }

    public function readsSettingsOfCliFromEnvironment(): void
    {
        \putenv('SPX_ENABLED=1');
        \putenv('SPX_AUTO_START=0');
        \putenv('SPX_SAMPLING_PERIOD=250');

        try {
            $config = SpxConfig::current();
            $problem = $config->problem();
            $period = $config->samplingPeriod();
        } finally {
            \putenv('SPX_ENABLED');
            \putenv('SPX_AUTO_START');
            \putenv('SPX_SAMPLING_PERIOD');
        }

        Assert::same($problem, 'SPX writes no full report: set `SPX_REPORT=full` in the environment of the process.');
        Assert::same($period, 250);
    }

    public function writesNameAndTagsIntoTheReportForTheSpxWebUi(): void
    {
        $profile = self::profile(self::recorded(), name: "checkout \"v2\" / ✓\x07", tags: ['env' => 'stage']);

        $json = self::unpack($profile->encode(Format::Spx))[self::KEY . '.json'];

        $metadata = \json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        Assert::same($metadata['custom_metadata_str'], "name=checkout \"v2\" / ✓\x07, env=stage");
        Assert::same($metadata['key'], 'spx-full-20250518_120000-app-42-1804289383');
        // Trap reads it back, and shows it
        Assert::same(SpxMetadata::fromJson($json)->string('custom_metadata_str'), "name=checkout \"v2\" / ✓\x07, env=stage");
    }

    public function keepsTheCustomMetadataWithinTheLimitOfSpx(): void
    {
        $profile = self::profile(self::recorded(), name: 'job', tags: ['long' => \str_repeat('ü', 3000)]);

        $json = self::unpack($profile->encode(Format::Spx))[self::KEY . '.json'];

        $custom = \json_decode($json, true, flags: \JSON_THROW_ON_ERROR)['custom_metadata_str'];
        // Cut at a character: `ü` takes 2 bytes, and the 4096th is the first of one
        Assert::same(\strlen($custom), 4095);
        Assert::true(\mb_check_encoding($custom, 'UTF-8'));
    }

    public function keepsTheReportMetadataWithoutNameOrTags(): void
    {
        $json = self::unpack(self::profile(self::recorded())->encode(Format::Spx))[self::KEY . '.json'];

        Assert::same($json, \file_get_contents(self::STUB . '.json'));
    }

    public function keepsReportWithInvalidMetadata(): void
    {
        \file_put_contents($this->dir . '/' . self::KEY . '.json', '{"key": ');

        try {
            SpxReport::read($this->dir, self::KEY, keep: false);
            Assert::fail('The report is read.');
        } catch (\RuntimeException $e) {
            Assert::string($e->getMessage())->contains('is kept: Invalid metadata JSON');
        }

        Assert::count((array) \glob($this->dir . '/spx-full-*'), 2);
    }

    public function refusesTraceOverTheLimitOfTrap(): void
    {
        $handle = \fopen($this->dir . '/' . self::KEY . '.txt.gz', 'r+b');
        \ftruncate($handle, SpxReport::MAX_SIZE + 1);
        \fclose($handle);

        try {
            SpxReport::read($this->dir, self::KEY, keep: false);
            Assert::fail('The report is read.');
        } catch (\RuntimeException $e) {
            Assert::string($e->getMessage())->contains('is kept: it is larger than the 64 MB Trap takes.');
        }

        Assert::count((array) \glob($this->dir . '/spx-full-*'), 2);
    }

    public function refusesToJoinTheReportOverTheMemoryLeft(): void
    {
        $profile = self::profile(self::recorded());
        Recorded::$freeMemory = 1024 * 1024;

        try {
            $profile->encode(Format::Spx);
            Assert::fail('The report is joined.');
        } catch (\RuntimeException $e) {
            Assert::string($e->getMessage())->contains('would not fit the memory left; send or write it instead.');
        } finally {
            Recorded::$freeMemory = null;
        }

        // In chunks, it takes no more
        Assert::count(self::unpack(\implode('', \iterator_to_array($profile->stream(Format::Spx)[1], false))), 2);
    }

    public function sendsTheTraceWithoutHoldingIt(): void
    {
        // Incompressible, as it is sent as is
        $handle = \fopen($this->dir . '/' . self::KEY . '.txt.gz', 'wb');
        for ($i = 0; $i < 4; ++$i) {
            \fwrite($handle, \random_bytes(1024 * 1024));
        }
        \fclose($handle);
        $profile = self::profile(Spx::recorded(SpxReport::read($this->dir, self::KEY, keep: true)));
        $trap = new HttpCatcher();

        try {
            \gc_collect_cycles();
            $before = \memory_get_usage();
            \memory_reset_peak_usage();
            TrapSender::create('127.0.0.1', $trap->port)->send($profile);
            $peak = \memory_get_peak_usage() - $before;
        } finally {
            $trap->close();
        }

        // The 4 MB trace is sent in chunks, and the client checks it sends as many bytes as it tells
        Assert::true($peak < 1024 * 1024, \sprintf('Peak memory grew by %.1f MB.', $peak / 1048576));
    }

    public function refusesToSendSpxReportToServerOfAnotherFormat(): void
    {
        $profile = new Profile(new Recorded([Format::Spx->value => static fn(): string => 'tar']), self::metadata());

        Expect::exception(\RuntimeException::class)->withMessage(
            'The SPX profile can not be converted to XHProf without ext-zlib, and the server takes no SPX report.',
        );

        ServerSender::create('http://127.0.0.1:1/api/profiler/store', Format::XHProf, [])->send($profile);
    }

    public function reportsItsFormatsAndOptions(): void
    {
        $driver = new Spx(keepFiles: true);

        Assert::same($driver->formats(), [Format::Spx, Format::XHProf, Format::Speedscope, Format::Collapsed, Format::Pprof]);
        Assert::same($driver->options()['keep_files'], true);
        Assert::same($driver->name(), 'spx');
    }

    public function keepsReportFilesWhenAsked(): void
    {
        $report = SpxReport::read($this->dir, self::KEY, keep: true);
        unset($report);

        Assert::count((array) \glob($this->dir . '/spx-full-*'), 2);
    }

    public function failsOnMissingReport(): never
    {
        Expect::exception(\RuntimeException::class)->withMessage("Unable to read the SPX report `spx-full-x` in `{$this->dir}`.");

        SpxReport::read($this->dir, 'spx-full-x', keep: false);
    }

    public function rejectsKeyOutsideTheDataDir(): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage('Unexpected SPX report key `../etc/passwd`.');

        SpxReport::read($this->dir, '../etc/passwd', keep: false);
    }

    public function convertsToXHProfWithCalls(): void
    {
        $xhprof = \json_decode(self::recorded()->encode(Format::XHProf), true, flags: \JSON_THROW_ON_ERROR);

        Assert::same($xhprof, [
            'main()' => ['ct' => 1, 'wt' => 12, 'cpu' => 10, 'mu' => 0, 'pmu' => 0],
            'main()==>App\Kernel::handle' => ['ct' => 2, 'wt' => 9, 'cpu' => 8, 'mu' => 0, 'pmu' => 0],
            'App\Kernel::handle==>App\fib' => ['ct' => 1, 'wt' => 4, 'cpu' => 3, 'mu' => 0, 'pmu' => 0],
            'App\fib==>App\fib@1' => ['ct' => 1, 'wt' => 1, 'cpu' => 1, 'mu' => 0, 'pmu' => 0],
            'App\Kernel::handle==>strlen' => ['ct' => 1, 'wt' => 1, 'cpu' => 1, 'mu' => 0, 'pmu' => 0],
        ]);
    }

    public function convertsToSpeedscopeAndCollapsedBySelfWallTime(): void
    {
        $recorded = self::recorded();
        $speedscope = \json_decode($recorded->encode(Format::Speedscope), true, flags: \JSON_THROW_ON_ERROR);

        Assert::same($speedscope['shared']['frames'][0], ['name' => 'main()', 'file' => '/app/index.php']);
        Assert::same(\array_sum($speedscope['profiles'][0]['weights']), 12_000);
        Assert::same($speedscope['profiles'][0]['unit'], 'nanoseconds');
        Assert::same($recorded->encode(Format::Collapsed), "main() 3\nmain();App\\Kernel::handle 4\n"
            . "main();App\\Kernel::handle;App\\fib 3\nmain();App\\Kernel::handle;App\\fib;App\\fib 1\n"
            . "main();App\\Kernel::handle;strlen 1\n");
    }

    public function convertsToPprofWithCallsOrSampledCalls(): void
    {
        $calls = PprofDecoder::profile(self::recorded()->encode(Format::Pprof));
        $sampled = PprofDecoder::profile(self::recorded(250)->encode(Format::Pprof));

        Assert::same($calls['sample_type'], [['calls', 'count'], ['wall', 'nanoseconds']]);
        Assert::same($calls['time_nanos'], 1747569600 * 1_000_000_000);
        // SPX names it `wall_time_ms`, but it holds microseconds
        Assert::same($calls['duration_nanos'], 12_000);
        Assert::same($sampled['sample_type'][0], ['sampled_calls', 'count']);
        Assert::same($sampled['period'], 250_000);
    }

    public function packsTheNativeReport(): void
    {
        $files = self::unpack(self::recorded()->encode(Format::Spx));

        Assert::same(\array_keys($files), [self::KEY . '.json', self::KEY . '.txt.gz']);
        Assert::same($files[self::KEY . '.json'], \file_get_contents(self::STUB . '.json'));
    }

    public function guessesTheNativeFormatByFileName(): void
    {
        $path = $this->dir . '/report.spx.tar';

        TrapHandle::profile(new FakeDriver([Format::XHProf, Format::Spx]))->toFile($path)->run(static fn() => null);

        Assert::same(\file_get_contents($path), FakeDriver::SPX);
    }

    public function sendsTheNativeReportToTrap(): void
    {
        $trap = new HttpCatcher();
        try {
            TrapSender::create('127.0.0.1', $trap->port)->send(self::profile(self::recorded(250)));
            $requests = $trap->requests();
        } finally {
            $trap->close();
        }

        Assert::count($requests, 1);
        [$head, $body] = \explode("\r\n\r\n", $requests[0], 2);
        $head = \explode("\r\n", $head);
        Assert::string($head[0])->startsWith('POST /api/profiler/spx?')->contains('sampling_period=250');
        Assert::contains($head, 'Content-Type: application/x-tar');

        // What Trap makes of it
        $request = new ServerRequest('POST', '/api/profiler/spx?sampling_period=250', ['Content-Type' => 'application/x-tar'], $body);
        $middleware = new SPXTrap(new ProfileBuilder(), new Logger(), new BuildLock());
        $fiber = new \Fiber(static fn(): ResponseInterface => $middleware->handle($request, static fn() => new Response(404)));
        $frame = $fiber->start();
        while ($frame === null && !$fiber->isTerminated()) {
            $frame = $fiber->resume();
        }
        Assert::instanceOf($frame, Frame::class);
        Assert::same($frame->payload->getProfile()->peaks->wt, 12);
        Assert::same($frame->payload->getProfile()->metadata['sampling_period'], 250);
    }

    public function warnsOncePerProcessAboutMissingSettings(): void
    {
        \function_exists('spx_profiler_start') and throw new SkipTest('ext-spx is loaded');
        $log = new LogCapture();
        Warnings::$logger = $log;
        Warnings::reset();

        try {
            $result = TrapHandle::profile(new Spx())->to(static fn() => null)->run(static fn(): int => 7);
        } finally {
            Warnings::$logger = null;
        }

        Assert::same($result, 7);
        Assert::string($log->messages())->contains('The `spx` profiler is not available.');
    }

    /**
     * @param array<non-empty-string, string> $tags
     */
    private static function profile(Recorded $recorded, ?string $name = null, array $tags = []): Profile
    {
        return new Profile($recorded, self::metadata($name, $tags));
    }

    /**
     * @param array<non-empty-string, string> $tags
     */
    private static function metadata(?string $name = null, array $tags = []): Metadata
    {
        return new Metadata(
            name: $name,
            tags: $tags,
            appName: null,
            hostname: 'test',
            date: new \DateTimeImmutable(),
            file: null,
            line: null,
            driver: 'spx',
            options: ['sampling_period' => 250],
            duration: 0.01,
            peakMemory: 0,
        );
    }

    /**
     * @return array<string, string> Contents by names.
     */
    private static function unpack(string $archive): array
    {
        $offset = 0;
        $read = static function (int $length) use ($archive, &$offset): string {
            $data = \substr($archive, $offset, $length);
            $offset += $length;
            return $data;
        };

        $files = [];
        while (($entry = Archive::next($read)) !== null) {
            $files[$entry[0]] = $read($entry[1]);
            $read(Archive::paddingOf($entry[1]));
        }

        return $files;
    }

    private function emptyDir(): void
    {
        foreach ($this->files() as $name) {
            \unlink("{$this->dir}/$name");
        }
    }

    /**
     * @return list<string> Names of the files in the directory, the hidden ones too, sorted.
     */
    private function files(): array
    {
        return \array_values(\array_diff((array) \scandir($this->dir), ['.', '..']));
    }

    /**
     * @param positive-int|null $samplingPeriod
     */
    private function recorded(?int $samplingPeriod = null): Recorded
    {
        return Spx::recorded(SpxReport::read($this->dir, self::KEY, keep: true), $samplingPeriod);
    }
}
