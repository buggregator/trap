<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Client\Profiler;

use Buggregator\Trap\Client\Profiler\Format;
use Buggregator\Trap\Client\Profiler\Internal\Warnings;
use Buggregator\Trap\Client\Profiler\Profile;
use Buggregator\Trap\Client\Profiler\Profiling;
use Buggregator\Trap\Client\Profiler\Session;
use Buggregator\Trap\Client\TrapHandle;
use Buggregator\Trap\Tests\Unit\Client\Base;
use Buggregator\Trap\Tests\Unit\Client\Profiler\Stub\FakeDriver;
use Buggregator\Trap\Tests\Unit\Client\Profiler\Stub\HttpCatcher;
use Buggregator\Trap\Tests\Unit\Client\Profiler\Stub\LogCapture;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Lifecycle\AfterClass;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeClass;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(Profiling::class)]
#[Covers(Session::class)]
#[Covers(Profile::class)]
final class ProfilingTest extends Base
{
    private static HttpCatcher $trap;
    private string $dir = '';
    private LogCapture $log;

    #[BeforeClass]
    public static function startTrap(): void
    {
        self::$trap = new HttpCatcher();
    }

    #[AfterClass]
    public static function stopTrap(): void
    {
        self::$trap->close();
    }

    #[BeforeTest]
    public function prepare(): void
    {
        $this->dir = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'trap-profile-' . \bin2hex(\random_bytes(4));
        \putenv('TRAP_PROFILER_HOST=127.0.0.1');
        \putenv('TRAP_PROFILER_PORT=' . self::$trap->port);
        self::$trap->requests();
        $this->log = new LogCapture();
        Warnings::$logger = $this->log;
    }

    #[AfterTest]
    public function cleanUp(): void
    {
        \putenv('TRAP_PROFILER_HOST');
        \putenv('TRAP_PROFILER_PORT');
        \putenv('TRAP_PROFILER_APP_NAME');
        \putenv('TRAP_PROFILER_SERVER');
        Warnings::$logger = null;
        self::removeDir($this->dir);
    }

    public function profileRunsNothing(): void
    {
        $driver = new FakeDriver();

        TrapHandle::profile($driver)->name('idle');

        Assert::same($driver->started, 0);
        Assert::same(self::$trap->requests(), []);
    }

    public function runReturnsTheCallableResult(): void
    {
        $driver = new FakeDriver();

        $result = TrapHandle::profile($driver)->to(static fn() => null)->run(static fn(): int => 42);

        Assert::same($result, 42);
        Assert::same($driver->started, 1);
        Assert::same($driver->stopped, 1);
    }

    public function sendsSpeedscopeToTrapByDefault(): void
    {
        \putenv('TRAP_PROFILER_APP_NAME=shop');

        TrapHandle::profile(new FakeDriver())->name('checkout')->tags(['env' => 'dev'])->run(static fn() => null);

        [$head, $body] = self::parse(self::$trap->requests());
        $query = \http_build_query([
            'app_name' => 'shop',
            'hostname' => \gethostname(),
            'name' => 'checkout',
            'clock' => 'cpu',
            'period' => '0.001',
            'tags' => ['env' => 'dev'],
        ], '', '&', \PHP_QUERY_RFC3986);
        Assert::same($head[0], "POST /api/profiler/speedscope?{$query} HTTP/1.1");
        Assert::contains($head, 'Content-Type: application/json');
        Assert::contains($head, 'Content-Length: ' . \strlen(FakeDriver::SPEEDSCOPE));
        Assert::same($body, FakeDriver::SPEEDSCOPE);
    }

    public function sendsXHProfToTheStoreEndpoint(): void
    {
        TrapHandle::profile(FakeDriver::xhprof())->name('job')->tags(['queue' => 'mail'])->run(static fn() => null);

        [$head, $body] = self::parse(self::$trap->requests());
        Assert::same($head[0], 'POST /api/profiler/store HTTP/1.1');
        $payload = \json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        Assert::same($payload['profile'], \json_decode(FakeDriver::XHPROF, true));
        Assert::same($payload['tags'], ['queue' => 'mail', 'name' => 'job']);
        Assert::same($payload['hostname'], \gethostname());
        Assert::int($payload['date'])->greaterThan(0);
        Assert::false(\array_key_exists('app_name', $payload));
    }

    public function sendsEmptyTagsAsObject(): void
    {
        TrapHandle::profile(FakeDriver::xhprof())->run(static fn() => null);

        [, $body] = self::parse(self::$trap->requests());
        Assert::string($body)->contains('"tags":{}');
    }

    public function explicitDestinationsReplaceTrap(): void
    {
        $received = null;

        TrapHandle::profile(new FakeDriver())
            ->to(static function (Profile $profile) use (&$received): void {
                $received = $profile->encode(Format::Collapsed);
            })
            ->run(static fn() => null);

        Assert::same($received, FakeDriver::COLLAPSED);
        Assert::same(self::$trap->requests(), []);
    }

    public function toTrapTakesHostAndPortArguments(): void
    {
        \putenv('TRAP_PROFILER_PORT=1');
        $received = false;

        TrapHandle::profile(new FakeDriver())
            ->to(static function () use (&$received): void {
                $received = true;
            })
            ->toTrap('127.0.0.1', self::$trap->port)
            ->run(static fn() => null);

        Assert::true($received);
        Assert::count(self::$trap->requests(), 1);
    }

    #[DataSet([0.001, '0.001'])]
    #[DataSet([0.00001, '0.00001'], 'exponent notation in PHP')]
    #[DataSet([1.0e-10, '0.0000000001'], 'rounded to 0 by 9 decimals')]
    #[DataSet([1.25e-12, '0.00000000000125'])]
    #[DataSet([0.25, '0.25'])]
    #[DataSet([2, '2'], 'an integer')]
    #[DataSet([10.5, '10.5'])]
    #[DataSet([1.5e20, '150000000000000000000'])]
    public function sendsThePeriodInPlainDecimals(int|float $period, string $expected): void
    {
        TrapHandle::profile(new FakeDriver(options: ['period' => $period]))->run(static fn() => null);

        [$head] = self::parse(self::$trap->requests());
        Assert::string($head[0])->contains("period={$expected} ");
    }

    public function failingStartRunsTheCallableAndWarns(): void
    {
        $driver = new FakeDriver(name: 'broken-' . \bin2hex(\random_bytes(4)), startError: new \ErrorException('no CPU clock'));
        $called = false;
        $profiling = TrapHandle::profile($driver)->to(static function () use (&$called): void {
            $called = true;
        });

        $result = $profiling->run(static fn(): int => 11);
        $profile = $profiling->start()->stop();

        Assert::same($result, 11);
        Assert::null($profile);
        Assert::false($called);
        Assert::same($driver->started, 2);
        Assert::count($this->log->records, 1);
        Assert::string($this->log->messages())->contains($driver->name())->contains('no CPU clock');
        Assert::same($this->log->records[0]['context']['reason'], 'start');
        Assert::notNull(TrapHandle::profile(new FakeDriver())->to(static fn() => null)->start()->stop());
    }

    public function toServerSendsBuggregatorJsonByDefault(): void
    {
        \putenv('TRAP_PROFILER_SERVER=http://127.0.0.1:' . self::$trap->port . '/api/profiler/store');

        TrapHandle::profile(FakeDriver::excimer())->name('job')->toServer()->run(static fn() => null);

        [$head, $body] = self::parse(self::$trap->requests());
        Assert::same($head[0], 'POST /api/profiler/store HTTP/1.1');
        Assert::contains($head, 'Content-Type: application/json');
        Assert::contains($head, 'X-Buggregator-Event: profiler');
        $payload = \json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        Assert::same($payload['profile'], [
            'main()' => ['ct' => 2, 'wt' => 2000, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
            'main()==>foo' => ['ct' => 2, 'wt' => 2000, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
        ]);
        Assert::same($payload['tags'], ['name' => 'job']);
        Assert::same($payload['hostname'], \gethostname());
    }

    public function toServerSendsUserinfoAsBasicAuthorization(): void
    {
        $url = 'http://profiler:s%40cret@127.0.0.1:' . self::$trap->port . '/api/profiler/store';

        TrapHandle::profile(FakeDriver::xhprof())->toServer($url)->run(static fn() => null);

        [$head] = self::parse(self::$trap->requests());
        Assert::contains($head, 'Authorization: Basic ' . \base64_encode('profiler:s@cret'));
    }

    public function toServerMergesTheQueryForSpeedscope(): void
    {
        $url = 'http://127.0.0.1:' . self::$trap->port . '/hook/profiles?project=shop';

        TrapHandle::profile(new FakeDriver())->name('job')->toServer($url, Format::Speedscope)->run(static fn() => null);

        [$head, $body] = self::parse(self::$trap->requests());
        $query = \http_build_query(['hostname' => \gethostname(), 'name' => 'job', 'clock' => 'cpu', 'period' => '0.001'], '', '&', \PHP_QUERY_RFC3986);
        Assert::same($head[0], "POST /hook/profiles?project=shop&{$query} HTTP/1.1");
        Assert::contains($head, 'Content-Type: application/json');
        Assert::same($body, FakeDriver::SPEEDSCOPE);
    }

    public function toServerSendsCollapsedAsTextWithCustomHeaders(): void
    {
        $url = 'http://127.0.0.1:' . self::$trap->port . '/folded';

        TrapHandle::profile(new FakeDriver())
            ->toServer($url, Format::Collapsed, ['X-Token' => 'secret', 'content-type' => 'text/x-folded'])
            ->run(static fn() => null);

        [$head, $body] = self::parse(self::$trap->requests());
        Assert::string($head[0])->startsWith('POST /folded?');
        Assert::contains($head, 'X-Token: secret');
        Assert::contains($head, 'content-type: text/x-folded');
        Assert::contains($head, 'X-Buggregator-Event: profiler');
        Assert::false(\in_array('Content-Type: text/plain; charset=utf-8', $head, true));
        Assert::same($body, FakeDriver::COLLAPSED);
    }

    public function toServerRemovesAHeaderGivenNull(): void
    {
        $url = 'http://127.0.0.1:' . self::$trap->port . '/api/profiler/store';

        TrapHandle::profile(FakeDriver::xhprof())->toServer($url, headers: ['x-buggregator-event' => null])->run(static fn() => null);

        [$head] = self::parse(self::$trap->requests());
        Assert::same(\preg_grep('/^x-buggregator-event:/i', $head), []);
    }

    #[DataSet(['Host'])]
    #[DataSet(['content-length'])]
    #[DataSet(['Connection'])]
    #[DataSet(['Transfer-Encoding'])]
    #[DataSet(['Expect'])]
    public function toServerRejectsReservedHeaders(string $name): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining("`{$name}` header");

        TrapHandle::profile(FakeDriver::xhprof())->toServer(headers: [$name => 'x']);
    }

    #[DataSet(["X-Bad\tName", 'x'], 'tab in the name')]
    #[DataSet(['X-Bad Name', 'x'], 'space in the name')]
    #[DataSet(['X-Bad:Name', 'x'], 'colon in the name')]
    #[DataSet(['X-Ünicode', 'x'], 'non-ASCII name')]
    #[DataSet(['X-Token', "a\0b"], 'NUL in the value')]
    #[DataSet(['X-Token', "a\nb"], 'line feed in the value')]
    #[DataSet(['X-Token', "a\x7Fb"], 'DEL in the value')]
    public function toServerRejectsInvalidHeaders(string $name, string $value): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Invalid HTTP header');

        TrapHandle::profile(FakeDriver::xhprof())->toServer(headers: [$name => $value]);
    }

    public function toServerAcceptsATabInAHeaderValue(): void
    {
        $url = 'http://127.0.0.1:' . self::$trap->port . '/api/profiler/store';

        TrapHandle::profile(FakeDriver::xhprof())->toServer($url, headers: ['X-Token' => "a\tb"])->run(static fn() => null);

        [$head] = self::parse(self::$trap->requests());
        Assert::contains($head, "X-Token: a\tb");
    }

    public function toServerRejectsAUrlWithSpaces(): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('must be encoded');

        TrapHandle::profile(FakeDriver::xhprof())->toServer('http://127.0.0.1:8000/a b');
    }

    public function toServerPercentEncodesNonAsciiBytes(): void
    {
        $url = 'http://127.0.0.1:' . self::$trap->port . '/профиль/store?app=café&raw=%41';

        TrapHandle::profile(FakeDriver::xhprof())->toServer($url)->run(static fn() => null);

        [$head] = self::parse(self::$trap->requests());
        Assert::same($head[0], 'POST /%D0%BF%D1%80%D0%BE%D1%84%D0%B8%D0%BB%D1%8C/store?app=caf%C3%A9&raw=%41 HTTP/1.1');
    }

    public function toServerKeepsTheColonOfAnEmptyPassword(): void
    {
        $url = 'http://profiler@127.0.0.1:' . self::$trap->port . '/api/profiler/store';

        TrapHandle::profile(FakeDriver::xhprof())->toServer($url)->run(static fn() => null);

        [$head] = self::parse(self::$trap->requests());
        Assert::contains($head, 'Authorization: Basic ' . \base64_encode('profiler:'));
    }

    public function toTrapTakesThePortFromTheHost(): void
    {
        \putenv('TRAP_PROFILER_PORT=1');

        TrapHandle::profile(new FakeDriver())->toTrap('127.0.0.1:' . self::$trap->port)->run(static fn() => null);

        Assert::count(self::$trap->requests(), 1);
    }

    public function envHostTakesAPort(): void
    {
        \putenv('TRAP_PROFILER_HOST=127.0.0.1:' . self::$trap->port);
        \putenv('TRAP_PROFILER_PORT=1');

        TrapHandle::profile(new FakeDriver())->run(static fn() => null);

        Assert::count(self::$trap->requests(), 1);
    }

    public function toServerSendsCollapsedAsPlainText(): void
    {
        $url = 'http://127.0.0.1:' . self::$trap->port . '/folded';

        TrapHandle::profile(new FakeDriver())->toServer($url, Format::Collapsed)->run(static fn() => null);

        [$head] = self::parse(self::$trap->requests());
        Assert::contains($head, 'Content-Type: text/plain; charset=utf-8');
    }

    public function toServerSendsTheNativeFormatInPlaceOfAnUnsupportedOne(): void
    {
        $url = 'http://127.0.0.1:' . self::$trap->port . '/profiles';

        TrapHandle::profile(new FakeDriver())->name('job')->toServer($url)->run(static fn() => null);

        [$head, $body] = self::parse(self::$trap->requests());
        Assert::string($head[0])->startsWith('POST /profiles?');
        Assert::contains($head, 'Content-Type: application/json');
        Assert::same($body, FakeDriver::SPEEDSCOPE);
    }

    public function toServerOmitsAContentTypeRemovedByNull(): void
    {
        $url = 'http://127.0.0.1:' . self::$trap->port . '/folded';

        TrapHandle::profile(new FakeDriver())->toServer($url, Format::Collapsed, ['Content-Type' => null])->run(static fn() => null);

        [$head] = self::parse(self::$trap->requests());
        Assert::same(\preg_grep('/^content-type:/i', $head), []);
    }

    public function toServerRejectsANonHttpUrl(): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('ftp://example.com');

        TrapHandle::profile(FakeDriver::xhprof())->toServer('ftp://example.com/profiles');
    }

    public function toFileConvertsToXHProf(): void
    {
        $file = $this->dir . '/profile.xhprof';

        TrapHandle::profile(FakeDriver::excimer())->toFile($file)->run(static fn() => null);

        Assert::same(
            \json_decode((string) \file_get_contents($file), true),
            ['main()' => ['ct' => 2, 'wt' => 2000, 'cpu' => 0, 'mu' => 0, 'pmu' => 0], 'main()==>foo' => ['ct' => 2, 'wt' => 2000, 'cpu' => 0, 'mu' => 0, 'pmu' => 0]],
        );
    }

    public function throwingCallableIsDeliveredAndRethrown(): never
    {
        $driver = new FakeDriver();

        Expect::exception(\RuntimeException::class)->withMessage('boom');

        try {
            TrapHandle::profile($driver)->run(static fn() => throw new \RuntimeException('boom'));
        } finally {
            Assert::same($driver->stopped, 1);
            Assert::count(self::$trap->requests(), 1);
            Assert::instanceOf(TrapHandle::profile($driver)->to(static fn() => null)->start()->stop(), Profile::class);
        }
    }

    public function destinationErrorDoesNotReplaceTheCallableException(): never
    {
        Expect::exception(\RuntimeException::class)->withMessage('callable');

        TrapHandle::profile(new FakeDriver())
            ->to(static fn() => throw new \LogicException('destination'))
            ->run(static fn() => throw new \RuntimeException('callable'));
    }

    public function destinationErrorsAreLoggedAndTheOtherDestinationsRun(): void
    {
        $called = false;

        $result = TrapHandle::profile(new FakeDriver())
            ->to(static fn() => throw new \LogicException('first destination'))
            ->to(static fn() => throw new \LogicException('second destination'))
            ->to(static function () use (&$called): void {
                $called = true;
            })
            ->run(static fn(): int => 5);

        Assert::same($result, 5);
        Assert::true($called);
        Assert::string($this->log->messages())
            ->contains('The `fake` profile was not delivered: first destination')
            ->contains('The `fake` profile was not delivered: second destination');
        Assert::same($this->log->records[0]['level'], 'error');
    }

    public function unwritableFileIsLogged(): void
    {
        $file = $this->dir . '/profile.txt';
        \mkdir($file, 0777, true);

        $result = TrapHandle::profile(new FakeDriver())->toFile($file)->run(static fn(): int => 6);

        Assert::same($result, 6);
        Assert::string($this->log->messages())->contains("Unable to write the profile to `{$file}`");
    }

    public function failingProfilerIsLoggedOnStop(): void
    {
        $driver = new FakeDriver(stopError: new \RuntimeException('stop failed on stop()'));

        $profile = TrapHandle::profile($driver)->to(static fn() => null)->start()->stop();

        Assert::null($profile);
        Assert::string($this->log->messages())->contains('The `fake` profiler failed to stop: stop failed on stop()');
    }

    public function samplingKeepsTheSeededRandomSequence(): void
    {
        \mt_srand(42);
        $expected = [\mt_rand(), \mt_rand()];

        \mt_srand(42);
        TrapHandle::profile(new FakeDriver())->sample(0.5)->to(static fn() => null)->run(static fn() => null);

        Assert::same([\mt_rand(), \mt_rand()], $expected);
    }

    #[DataSet([false, 1.0], 'condition is false')]
    #[DataSet([true, 0.0], 'sample misses')]
    public function skippedProfilingRunsTheCallableOnly(bool $if, float $sample): void
    {
        $driver = new FakeDriver();
        $called = false;
        $profiling = TrapHandle::profile($driver)
            ->if($if)
            ->sample($sample)
            ->toFile($this->dir . '/never.txt')
            ->to(static function () use (&$called): void {
                $called = true;
            });

        $result = $profiling->run(static fn(): string => 'done');
        $profile = $profiling->start()->stop();

        Assert::same($result, 'done');
        Assert::null($profile);
        Assert::same($driver->started, 0);
        Assert::false($called);
        Assert::false(\is_dir($this->dir));
        Assert::same($this->log->records, []);
    }

    public function conditionCallableIsEvaluatedOnEveryRun(): void
    {
        $driver = new FakeDriver();
        $enabled = false;
        $profiling = TrapHandle::profile($driver)->to(static fn() => null)->if(static function () use (&$enabled): bool {
            return $enabled;
        });

        $profiling->run(static fn() => null);
        $enabled = true;
        $profiling->run(static fn() => null);

        Assert::same($driver->started, 1);
    }

    public function sampleOutOfRangeThrows(): never
    {
        Expect::exception(\InvalidArgumentException::class);

        TrapHandle::profile(new FakeDriver())->sample(1.5);
    }

    public function unavailableProfilerRunsTheCallableAndWarns(): void
    {
        $driver = new FakeDriver(available: false, name: 'missing-' . \bin2hex(\random_bytes(4)));
        $called = false;
        $profiling = TrapHandle::profile($driver)
            ->toFile($this->dir . '/never.xhprof')
            ->to(static function () use (&$called): void {
                $called = true;
            });

        $result = $profiling->run(static fn(): int => 7);
        $profile = $profiling->start()->stop();

        Assert::same($result, 7);
        Assert::null($profile);
        Assert::same($driver->started, 0);
        Assert::false($called);
        Assert::count($this->log->records, 1);
        Assert::same($this->log->records[0]['level'], 'warning');
        Assert::string($this->log->messages())->contains($driver->name());
        Assert::same($this->log->records[0]['context'], ['driver' => $driver->name(), 'reason' => 'unavailable']);
    }

    public function runInsideRunIsSkipped(): void
    {
        $driver = new FakeDriver();
        $deliveries = 0;
        $profiling = TrapHandle::profile($driver)->to(static function () use (&$deliveries): void {
            ++$deliveries;
        });

        $result = $profiling->run(static fn(): int => $profiling->run(static fn(): int => 5) + 1);

        Assert::same($result, 6);
        Assert::same($driver->started, 1);
        Assert::same($deliveries, 1);
        Assert::same($profiling->run(static fn(): int => 1), 1);
        Assert::same($driver->started, 2);
    }

    public function startWhileStartedIsSkipped(): void
    {
        $driver = new FakeDriver();
        $profiling = TrapHandle::profile($driver)->to(static fn() => null);
        $session = $profiling->start();

        $inner = $profiling->start()->stop();
        $outer = $session->stop();

        Assert::null($inner);
        Assert::instanceOf($outer, Profile::class);
        Assert::same($driver->started, 1);
    }

    public function nestingIsCheckedBeforeTheConditions(): void
    {
        $session = TrapHandle::profile(new FakeDriver())->to(static fn() => null)->start();
        $evaluated = false;
        $missing = new FakeDriver(available: false, name: 'missing-' . \bin2hex(\random_bytes(4)));

        $result = TrapHandle::profile($missing)
            ->if(static function () use (&$evaluated): bool {
                $evaluated = true;
                return true;
            })
            ->run(static fn(): int => 3);
        $session->stop();

        Assert::same($result, 3);
        Assert::false($evaluated);
        Assert::string($this->log->messages())->notContains($missing->name());
    }

    public function failingProfilerDoesNotReplaceTheCallableException(): never
    {
        $driver = new FakeDriver(stopError: new \RuntimeException('stop failed'));

        Expect::exception(\RuntimeException::class)->withMessage('callable');

        try {
            TrapHandle::profile($driver)->to(static fn() => null)->run(static fn() => throw new \RuntimeException('callable'));
        } finally {
            Assert::same($driver->stopped, 1);
            Assert::notNull(TrapHandle::profile(new FakeDriver())->to(static fn() => null)->start()->stop());
        }
    }

    public function failingProfilerIsSilentOnDestruction(): void
    {
        $driver = new FakeDriver(stopError: new \RuntimeException('stop failed'));
        $session = TrapHandle::profile($driver)->to(static fn() => null)->start();

        unset($session);

        Assert::same($driver->stopped, 1);
        Assert::notNull(TrapHandle::profile(new FakeDriver())->to(static fn() => null)->start()->stop());
    }

    public function emptyProfileIsNotDeliveredAndWarns(): void
    {
        $driver = new FakeDriver(name: 'short-' . \bin2hex(\random_bytes(4)), empty: true);
        $called = false;
        $profiling = TrapHandle::profile($driver)->to(static function () use (&$called): void {
            $called = true;
        });

        $result = $profiling->run(static fn(): int => 9);
        $profile = $profiling->start()->stop();

        Assert::same($result, 9);
        Assert::null($profile);
        Assert::false($called);
        Assert::same(self::$trap->requests(), []);
        Assert::string($this->log->messages())->contains($driver->name())->contains('period');
    }

    public function sessionStopDeliversOnceAndReturnsTheSameProfile(): void
    {
        $deliveries = 0;
        $session = TrapHandle::profile(new FakeDriver())
            ->name('run')
            ->to(static function () use (&$deliveries): void {
                ++$deliveries;
            })
            ->start();

        $first = $session->stop();
        $second = $session->stop();

        Assert::instanceOf($first, Profile::class);
        Assert::same($second, $first);
        Assert::same($deliveries, 1);
        Assert::same($first->metadata()->name, 'run');
    }

    public function sessionIsStoppedAndDeliveredOnDestruction(): void
    {
        $driver = new FakeDriver();
        $file = $this->dir . '/run.txt';
        $session = TrapHandle::profile($driver)->toFile($file)->start();

        unset($session);

        Assert::same($driver->stopped, 1);
        Assert::same(\file_get_contents($file), FakeDriver::COLLAPSED);
        Assert::notNull(TrapHandle::profile($driver)->to(static fn() => null)->start()->stop());
    }

    #[DataSet(['profile.speedscope.json', FakeDriver::SPEEDSCOPE], 'speedscope extension')]
    #[DataSet(['profile.txt', FakeDriver::COLLAPSED], 'txt')]
    #[DataSet(['profile.folded', FakeDriver::COLLAPSED], 'folded')]
    #[DataSet(['profile.COLLAPSED', FakeDriver::COLLAPSED], 'collapsed in upper case')]
    #[DataSet(['profile.json', FakeDriver::SPEEDSCOPE], 'plain json is native')]
    #[DataSet(['profile', FakeDriver::SPEEDSCOPE], 'no extension is native')]
    public function toFileGuessesTheFormat(string $name, string $expected): void
    {
        $file = $this->dir . '/nested/' . $name;

        TrapHandle::profile(new FakeDriver())->toFile($file)->run(static fn() => null);

        Assert::same(\file_get_contents($file), $expected);
    }

    public function toFileTakesAnExplicitFormat(): void
    {
        $file = $this->dir . '/profile.json';

        TrapHandle::profile(new FakeDriver())->toFile($file, Format::Collapsed)->run(static fn() => null);

        Assert::same(\file_get_contents($file), FakeDriver::COLLAPSED);
    }

    public function toFileWritesTheNativeFormatInPlaceOfAnUnsupportedOne(): void
    {
        $file = $this->dir . '/profile.speedscope.json';

        TrapHandle::profile(FakeDriver::xhprof())->toFile($file)->run(static fn() => null);

        Assert::same(\file_get_contents($file), FakeDriver::XHPROF);
    }

    public function unavailableProfilerTakesAnyFormat(): void
    {
        $profiling = TrapHandle::profile(new FakeDriver([Format::XHProf], available: false))
            ->toFile($this->dir . '/profile.speedscope.json')
            ->toServer(format: Format::Collapsed);

        Assert::same($profiling->run(static fn() => 'ran'), 'ran');
    }

    public function encodeRejectsAnUnsupportedFormat(): never
    {
        $profile = TrapHandle::profile(FakeDriver::xhprof())->to(static fn() => null)->start()->stop();

        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('available formats: XHProf');

        $profile?->encode(Format::Speedscope);
    }

    public function metadataDescribesTheProfile(): void
    {
        \putenv('TRAP_PROFILER_APP_NAME=shop');
        $profiling = trap()::profile(new FakeDriver())
            ->to(static fn() => null)
            ->name('checkout')
            ->tags(['env' => 'dev'])
            ->tags(['region' => 'eu']);

        $line = __LINE__ + 1;
        $session = $profiling->start();
        \usleep(1000);
        $profile = $session->stop();

        Assert::notNull($profile);
        $meta = $profile->metadata();
        Assert::same($meta->name, 'checkout');
        Assert::same($meta->tags, ['env' => 'dev', 'region' => 'eu']);
        Assert::same($meta->appName, 'shop');
        Assert::same($meta->file, __FILE__);
        Assert::same($meta->line, $line);
        Assert::same($meta->driver, 'fake');
        Assert::same($meta->options, ['clock' => 'cpu', 'period' => 0.001]);
        Assert::numeric($meta->duration)->greaterThan(0);
        Assert::int($meta->peakMemory)->greaterThan(0);
        Assert::same($profile->formats(), [Format::Speedscope, Format::Collapsed]);
    }

    /**
     * @param list<string> $requests
     */
    private static function parse(array $requests): array
    {
        Assert::count($requests, 1);
        [$head, $body] = \explode("\r\n\r\n", $requests[0], 2);

        return [\explode("\r\n", $head), $body];
    }

    private static function removeDir(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? \rmdir($item->getPathname()) : \unlink($item->getPathname());
        }

        \rmdir($dir);
    }
}
