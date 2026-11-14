<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Profiler\Console;

use Buggregator\Trap\Module\Monolog\Frame as Monolog;
use Buggregator\Trap\Module\Profiler\Console\Renderer;
use Buggregator\Trap\Module\Profiler\Frame;
use Buggregator\Trap\Module\Profiler\Frame\Payload;
use Buggregator\Trap\Module\Profiler\Frame\Type;
use Buggregator\Trap\Module\Profiler\Speedscope\ProfileBuilder as SpeedscopeProfileBuilder;
use Buggregator\Trap\Module\Profiler\SPX\ProfileBuilder as SpxProfileBuilder;
use Buggregator\Trap\Module\Profiler\Struct\Profile;
use Buggregator\Trap\Module\Profiler\XDebug\ProfileBuilder as XDebugProfileBuilder;
use Buggregator\Trap\Module\Profiler\XHProf\ProfileBuilder;
use Buggregator\Trap\Support\Spx\Metadata;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

#[Covers(Renderer::class)]
final class RendererTest
{
    /**
     * `main()` calls `foo()` that calls `bar()` recursively and `strlen()`.
     */
    private const XHPROF_STUB = [
        'foo==>bar' => ['ct' => 2, 'wt' => 37_000, 'cpu' => 30_000, 'mu' => 2208, 'pmu' => 0],
        'foo==>strlen' => ['ct' => 2, 'wt' => 3_000, 'cpu' => 2_000, 'mu' => 624, 'pmu' => 0],
        'bar==>bar@1' => ['ct' => 1, 'wt' => 2_000, 'cpu' => 1_000, 'mu' => 856, 'pmu' => 0],
        'main()==>foo' => ['ct' => 1, 'wt' => 104_000, 'cpu' => 90_000, 'mu' => 4168, 'pmu' => 0],
        'main()==>xhprof_disable' => ['ct' => 1, 'wt' => 10, 'cpu' => 10, 'mu' => 344, 'pmu' => 0],
        'main()' => ['ct' => 1, 'wt' => 1_390_000, 'cpu' => 120_000, 'mu' => 5936, 'pmu' => 8000],
    ];

    private const XDEBUG_STUB = <<<'CACHEGRIND'
        version: 1
        creator: xdebug 3.4.0 (PHP 8.4.0)
        cmd: /app/index.php
        part: 1
        positions: line

        events: Time_(10ns) Memory_(bytes)

        fn=(1) work
        1 60000 100

        fn=(2) {main}
        1 40000 0
        cfn=(1)
        calls=1 0 0
        1 60000 100

        CACHEGRIND;

    #[Test]
    public function supportsOnlyProfilerFrames(): void
    {
        $renderer = new Renderer();

        Assert::true($renderer->isSupport(self::xhprofFrame(self::XHPROF_STUB)));
        Assert::false($renderer->isSupport(new Monolog([])));
    }

    #[Test]
    public function rendersTopFunctionsBySelfWallTime(): void
    {
        $output = self::render(self::xhprofFrame(self::XHPROF_STUB));

        Assert::string($output)
            ->contains('Top 5 of 5 functions by self wall time')
            ->contains('| Function       | Calls | Self wall |     % | Total wall |      % | Self CPU | Self memory |')
            ->contains('| Wall time         | 1.39 s    |')
            ->contains('| CPU time          | 120.00 ms |')
            ->contains('| Peak memory usage | 7.81 KiB  |');

        Assert::same(self::rows($output), [
            '| main()         |     1 |    1.29 s | 92.5% |     1.39 s | 100.0% | 29.99 ms |    1.39 KiB |',
            '| foo            |     1 |  64.00 ms |  4.6% |  104.00 ms |   7.5% | 58.00 ms |    1.30 KiB |',
            '| bar            |     3 |  37.00 ms |  2.7% |   37.00 ms |   2.7% | 30.00 ms |    2.16 KiB |',
            '| strlen         |     2 |   3.00 ms |  0.2% |    3.00 ms |   0.2% |  2.00 ms |    624.00 B |',
            '| xhprof_disable |     1 |     10 µs | <0.1% |      10 µs |  <0.1% |    10 µs |    344.00 B |',
        ]);
    }

    #[Test]
    public function rendersHotPath(): void
    {
        $output = self::render(self::xhprofFrame(self::XHPROF_STUB));

        Assert::string($output)->contains('## Hot path')->contains(<<<'TEXT'
            100.0%       1.39 s  main()
              7.5%    104.00 ms  └ foo
              2.7%     37.00 ms  └ bar
              0.1%      2.00 ms  └ bar

            TEXT);
    }

    #[Test]
    public function skipsMetricsTheProfilerDoesNotMeasure(): void
    {
        $profile = (new XDebugProfileBuilder())->createProfile(self::XDEBUG_STUB);
        $output = self::render(new Frame(Payload::new(Type::XDebug, static fn(): Profile => $profile)));

        Assert::string($output)
            ->contains('| Function | Calls | Self wall |     % | Total wall |      % | Self memory |')
            ->contains('| work     |     1 |    600 µs | 60.0% |     600 µs |  60.0% |    100.00 B |')
            ->notContains('CPU');
    }

    #[Test]
    public function namesSamplesOfSampledProfile(): void
    {
        $profile = (new SpeedscopeProfileBuilder())->createProfile([
            'shared' => ['frames' => [['name' => 'main'], ['name' => 'foo']]],
            'profiles' => [['type' => 'sampled', 'unit' => 'milliseconds', 'samples' => [[0, 1], [0]], 'weights' => [1, 1]]],
        ]);
        $output = self::render(new Frame(Payload::new(Type::Speedscope, static fn(): Profile => $profile)));

        Assert::string($output)
            ->contains('| Function | Samples | Self wall |')
            ->contains('| Samples   | 2 ');
    }

    #[Test]
    public function limitsTopFunctions(): void
    {
        $calls = ['main()' => ['ct' => 1, 'wt' => 1000, 'cpu' => 0, 'mu' => 0, 'pmu' => 0]];
        for ($i = 1; $i <= 15; ++$i) {
            $calls["main()==>f$i"] = ['ct' => 1, 'wt' => $i, 'cpu' => 0, 'mu' => 0, 'pmu' => 0];
        }

        $output = self::render(self::xhprofFrame($calls));
        $verbose = self::render(self::xhprofFrame($calls), OutputInterface::VERBOSITY_VERBOSE);

        Assert::string($output)->contains('Top 10 of 16 functions')->contains('| f7 ')->notContains('| f6 ');
        Assert::string($verbose)->contains('Top 16 of 16 functions')->contains('| f1 ');
    }

    #[Test]
    public function shortensDeepHotPath(): void
    {
        $calls = ['main()' => ['ct' => 1, 'wt' => 100, 'cpu' => 0, 'mu' => 0, 'pmu' => 0]];
        $caller = 'main()';
        for ($i = 1; $i <= 20; ++$i) {
            $calls["$caller==>f$i"] = ['ct' => 1, 'wt' => 100 - $i, 'cpu' => 0, 'mu' => 0, 'pmu' => 0];
            $caller = "f$i";
        }

        $output = self::render(self::xhprofFrame($calls));

        Assert::string($output)
            ->contains("└ f2\n")
            ->notContains("└ f3\n")
            ->contains('… 10 more calls')
            ->notContains("└ f12\n")
            ->contains("└ f13\n")
            ->contains("└ f20\n");
    }

    #[Test]
    public function rendersEmptyProfile(): void
    {
        $output = self::render(new Frame(Payload::new(Type::XHProf, static fn(): Profile => new Profile())));

        Assert::string($output)
            ->contains('PROFILER')
            ->contains('No calls recorded.')
            ->notContains('Top ')
            ->notContains('Hot path');
    }

    #[Test]
    public function escapesFunctionNames(): void
    {
        $output = self::render(self::xhprofFrame([
            'main()==><fg=red>x</>' => ['ct' => 1, 'wt' => 5, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
            "main()==>y\e[31m" => ['ct' => 1, 'wt' => 4, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
            'main()' => ['ct' => 1, 'wt' => 10, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
        ]));

        Assert::string($output)->contains('<fg=red>x</>')->contains('y?[31m')->notContains("\e");
    }

    #[Test]
    public function shortensLongFunctionNames(): void
    {
        $name = \str_repeat('Vendor\\Package\\', 5) . 'Service::handle';
        $output = self::render(self::xhprofFrame([
            "main()==>$name" => ['ct' => 1, 'wt' => 5, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
            'main()' => ['ct' => 1, 'wt' => 10, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
        ]));

        Assert::string($output)->contains('…' . \substr($name, -59) . ' |')->notContains($name);
    }

    #[Test]
    public function rendersDetailsOfSpxReport(): void
    {
        $stub = __DIR__ . '/../../../../Stub/spx/handwritten';
        $profile = (new SpxProfileBuilder())->createProfile(
            Metadata::fromJson((string) \file_get_contents("$stub.json")),
            [(string) \gzencode((string) \file_get_contents("$stub.txt"))],
        );

        $output = self::render(new Frame(Payload::new(Type::SPX, static fn(): Profile => $profile)));

        Assert::string($output)
            ->contains('PROFILER')
            ->contains('SPX')
            ->contains('Request: POST shop.test/checkout?step=2')
            ->contains('Custom metadata: release=1.2.3')
            ->contains('GC runs: 2')
            ->contains('I/O: 8.00 KiB')
            ->contains('Top 4 of 4 functions by self wall time');
    }

    #[Test]
    public function rendersFileNameWithItsSize(): void
    {
        $profile = (new ProfileBuilder())->createProfile(
            calls: ['main()' => ['ct' => 1, 'wt' => 10, 'cpu' => 0, 'mu' => 0, 'pmu' => 0]],
            metadata: ['filename' => 'spx-full-1.txt.gz', 'filesize' => 2048],
        );

        $output = self::render(new Frame(Payload::new(Type::XHProf, static fn(): Profile => $profile)));

        Assert::string($output)->contains('File name: spx-full-1.txt.gz (2.00 KiB)');
    }

    #[Test]
    public function rendersMetadataAndTags(): void
    {
        $profile = (new ProfileBuilder())->createProfile(
            metadata: [
                'date' => 1700000000,
                'app_name' => 'shop',
                'hostname' => 'app',
                'filename' => 'a.xhprof',
                'filesize' => 0,
                'details' => ['Request' => 'GET /', 5 => 'no label', 'List' => ['no scalar']],
            ],
            tags: ['env' => 'test'],
            calls: ['main()==>foo' => self::call(wt: 1000), 'main()' => self::call(wt: 2000)],
        );

        $output = self::render(new Frame(Payload::new(Type::XHProf, static fn(): Profile => $profile)));

        Assert::same(\implode("\n", \array_map(\rtrim(...), \explode("\n", $output))), <<<'TEXT'

             PROFILER  XHProf

                 Time: 2023-11-14 22:13:20
             App name: shop
             Hostname: app
            File name: a.xhprof (0.00 B)
              Request: GET /
            Num edges: 2

             env: test

            +---- Peak values ----+
            | Wall time | 2.00 ms |
            | Calls     | 1       |
            +-----------+---------+

            +---------- Top 2 of 2 functions by self wall time -+--------+
            | Function | Calls | Self wall |     % | Total wall |      % |
            +----------+-------+-----------+-------+------------+--------+
            | main()   |     1 |   1.00 ms | 50.0% |    2.00 ms | 100.0% |
            | foo      |     1 |   1.00 ms | 50.0% |    1.00 ms |  50.0% |
            +----------+-------+-----------+-------+------------+--------+

            ## Hot path
            100.0%      2.00 ms  main()
             50.0%      1.00 ms  └ foo

            TEXT);
    }

    #[Test]
    public function skipsMetadataOfOtherTypes(): void
    {
        $profile = (new ProfileBuilder())->createProfile(
            metadata: ['date' => 'yesterday', 'app_name' => 5, 'filename' => 'a.xhprof', 'filesize' => '1 KiB'],
            calls: ['main()' => self::call(wt: 10)],
        );

        $output = self::render(new Frame(Payload::new(Type::XHProf, static fn(): Profile => $profile)));

        Assert::string($output)
            ->contains("File name: a.xhprof\n")
            ->notContains('Time:')
            ->notContains('App name:')
            ->notContains('Hostname:');
    }

    #[Test]
    public function rendersNoCallsWhenNothingIsMeasured(): void
    {
        $output = self::render(self::xhprofFrame(['main()==>foo' => self::call(ct: 0), 'main()' => self::call(ct: 0)]));

        Assert::string($output)->endsWith("+-------+---+\n\nNo calls recorded.\n")->notContains('Wall time');
    }

    /**
     * @param array<non-empty-string, int<0, max>> $callee Costs of the callee, by the names of {@see self::call()}.
     * @param array<non-empty-string, int<0, max>> $main
     */
    #[Test]
    #[DataSet([['cpu' => 5], ['cpu' => 10], 'CPU time', '| CPU time | 10 µs |'], 'CPU time')]
    #[DataSet([['mu' => 5], ['mu' => 10], 'memory', '| Memory usage | 10.00 B |'], 'memory')]
    #[DataSet([['ct' => 3], ['ct' => 1], 'calls', '| Calls | 3 |'], 'calls')]
    public function ranksByTheFirstMeasuredMetric(array $callee, array $main, string $metric, string $peak): void
    {
        $output = self::render(self::xhprofFrame([
            'main()==>App\Service\VeryLongNamespace\Handler::process' => self::call(...$callee),
            'main()' => self::call(...$main),
        ]));

        Assert::string($output)
            ->contains("Top 2 of 2 functions by self $metric")
            ->notContains('Wall time')
            ->ignoringWhitespace()->contains($peak);
    }

    #[Test]
    public function namesCountsOfPprofProfile(): void
    {
        $profile = (new ProfileBuilder())->createProfile(
            metadata: ['count_type' => 'allocations'],
            calls: ['main()==>App\Service\VeryLongNamespace\Handler::process' => self::call(ct: 3), 'main()' => self::call()],
        );

        $output = self::render(new Frame(Payload::new(Type::Pprof, static fn(): Profile => $profile)));

        Assert::string($output)
            ->contains('| Allocations | 3 |')
            ->contains('Top 2 of 2 functions by self allocations')
            ->contains(" 33.3%            1  main()\n100.0%            3  └ App\\Service\\VeryLongNamespace\\Handler::process\n");
    }

    #[Test]
    public function formatsTimesAndSharesAtTheirBoundaries(): void
    {
        $output = self::render(self::xhprofFrame([
            'main()==>a' => self::call(wt: 1000, cpu: 20),
            'main()==>b' => self::call(wt: 999),
            'main()==>c' => self::call(wt: 1),
            'main()==>d' => self::call(),
            'main()' => self::call(wt: 2000, cpu: 10),
        ]));
        $seconds = self::render(self::xhprofFrame(['main()==>a' => self::call(wt: 1_000_000), 'main()' => self::call(wt: 1_000_000)]));

        Assert::same(self::rows($output), [
            '| a        |     1 |   1.00 ms | 50.0% |    1.00 ms |  50.0% |    20 µs |',
            '| b        |     1 |    999 µs | 50.0% |     999 µs |  50.0% |     0 µs |',
            '| c        |     1 |      1 µs |  0.1% |       1 µs |   0.1% |     0 µs |',
            '| main()   |     1 |      0 µs |  0.0% |    2.00 ms | 100.0% |     0 µs |',
            '| d        |     1 |      0 µs |  0.0% |       0 µs |   0.0% |     0 µs |',
        ]);
        Assert::string($seconds)->contains('| a        |     1 |    1.00 s | 100.0% |     1.00 s | 100.0% |');
    }

    #[Test]
    public function followsTheFirstOfEquallyHeavyCallsAndSkipsFreeOnes(): void
    {
        $output = self::render(self::xhprofFrame([
            'main()==>a' => self::call(wt: 5),
            'main()==>b' => self::call(wt: 5),
            'a==>d' => self::call(),
            'main()' => self::call(wt: 20),
        ]));

        Assert::string($output)->endsWith("## Hot path \n100.0%        20 µs  main()\n 25.0%         5 µs  └ a\n");
    }

    #[Test]
    public function keepsNameOfTheWidthWhole(): void
    {
        $name = \str_repeat('a', 60);

        $output = self::render(self::xhprofFrame(["main()==>$name" => self::call(wt: 5), 'main()' => self::call(wt: 10)]));

        Assert::string($output)->contains("| $name |")->notContains('…');
    }

    #[Test]
    public function keepsHotPathOfTheLimitWhole(): void
    {
        $calls = ['main()' => self::call(wt: 100)];
        $caller = 'main()';
        for ($i = 1; $i <= 11; ++$i) {
            $calls["$caller==>f$i"] = self::call(wt: 100 - $i);
            $caller = "f$i";
        }

        $output = self::render(self::xhprofFrame($calls));

        Assert::string($output)->contains("└ f3\n")->contains("└ f11\n")->notContains('more calls');
    }

    #[Test]
    public function keepsCountWiderThanItsColumn(): void
    {
        $output = self::render(self::xhprofFrame([
            'main()==>a' => self::call(ct: 123_456_789_012),
            'main()' => self::call(),
        ]));

        Assert::string($output)->contains("100.0%  123456789012  └ a\n");
    }

    /**
     * @return array{ct: int<0, max>, wt: int<0, max>, cpu: int<0, max>, mu: int<0, max>, pmu: int<0, max>}
     */
    private static function call(int $ct = 1, int $wt = 0, int $cpu = 0, int $mu = 0): array
    {
        return ['ct' => $ct, 'wt' => $wt, 'cpu' => $cpu, 'mu' => $mu, 'pmu' => 0];
    }

    /**
     * @param array<non-empty-string, array{ct: int<0, max>, wt: int<0, max>, cpu: int<0, max>, mu: int<0, max>, pmu: int<0, max>}> $calls
     */
    private static function xhprofFrame(array $calls): Frame
    {
        $profile = (new ProfileBuilder())->createProfile(calls: $calls);

        return new Frame(Payload::new(Type::XHProf, static fn(): Profile => $profile));
    }

    private static function render(Frame $frame, int $verbosity = OutputInterface::VERBOSITY_NORMAL): string
    {
        $output = new BufferedOutput($verbosity);
        (new Renderer())->render($output, $frame);

        return \str_replace(\PHP_EOL, "\n", $output->fetch());
    }

    /**
     * @return list<string> Rows of the top functions table.
     */
    private static function rows(string $output): array
    {
        $table = \explode('Top ', $output, 2)[1];
        $rows = \array_filter(
            \explode("\n", $table),
            static fn(string $line): bool => \str_starts_with($line, '| ') && !\str_starts_with($line, '| Function'),
        );

        return \array_values($rows);
    }
}
