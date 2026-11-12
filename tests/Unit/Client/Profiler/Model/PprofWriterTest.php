<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Client\Profiler\Model;

use Buggregator\Trap\Client\Profiler\Internal\Model\CallTree;
use Buggregator\Trap\Client\Profiler\Internal\Model\PprofWriter;
use Buggregator\Trap\Client\Profiler\Internal\Model\SpeedscopeReader;
use Buggregator\Trap\Client\Profiler\Internal\Model\XHProfWriter;
use Buggregator\Trap\Module\Profiler\Pprof\ProfileBuilder;
use Buggregator\Trap\Tests\Unit\Client\Profiler\Stub\PprofDecoder;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

#[Test]
#[Covers(PprofWriter::class)]
#[Covers(CallTree::class)]
final class PprofWriterTest
{
    private const FIXTURES = __DIR__ . '/../Stub/Speedscope/';

    public function writesTheProfileStructure(): void
    {
        $data = PprofWriter::encode(self::tree('01-excimer-cpu-phpt', 0.1), 0.1, false, 1_731_400_000_123_456_000, 2_900_123_456, gzip: false);

        $profile = PprofDecoder::profile($data);

        Assert::false($profile['gzip']);
        Assert::same($profile['strings'][0], '');
        Assert::same(\count($profile['strings']), \count(\array_unique($profile['strings'])));
        Assert::same($profile['sample_type'], [['samples', 'count'], ['wall', 'nanoseconds']]);
        Assert::same($profile['period_type'], ['wall', 'nanoseconds']);
        Assert::same($profile['period'], 100_000_000);
        Assert::same($profile['default_sample_type'], 'wall');
        Assert::same($profile['time_nanos'], 1_731_400_000_123_456_000);
        Assert::same($profile['duration_nanos'], 2_900_123_456);
        Assert::same(\array_values($profile['functions']), [
            ['name' => 'main()', 'system_name' => 'main()', 'filename' => '/data/cpu.php'],
            ['name' => 'foo', 'system_name' => 'foo', 'filename' => '/data/cpu.php'],
            ['name' => 'bar', 'system_name' => 'bar', 'filename' => '/data/cpu.php'],
            ['name' => '{closure:/data/cpu.php(5)}', 'system_name' => '{closure:/data/cpu.php(5)}', 'filename' => '/data/cpu.php'],
            ['name' => '{closure:/data/cpu.php(8)}', 'system_name' => '{closure:/data/cpu.php(8)}', 'filename' => '/data/cpu.php'],
            ['name' => 'C::member', 'system_name' => 'C::member', 'filename' => '/data/cpu.php'],
            ['name' => 'baz', 'system_name' => 'baz', 'filename' => '/data/cpu.php'],
        ]);
        Assert::same(\array_keys($profile['functions']), [1, 2, 3, 4, 5, 6, 7]);
        Assert::same($profile['locations'], [1 => [1], 2 => [2], 3 => [3], 4 => [4], 5 => [5], 6 => [6], 7 => [7]]);
        Assert::same($profile['samples'], [
            ['locations' => [4, 3, 2, 1], 'values' => [8, 800_000_000]],
            ['locations' => [5, 3, 2, 1], 'values' => [7, 700_000_000]],
            ['locations' => [6, 3, 2, 1], 'values' => [7, 700_000_000]],
            ['locations' => [7, 3, 2, 1], 'values' => [8, 800_000_000]],
        ]);
    }

    public function cpuClockTakesCpuTime(): void
    {
        $profile = PprofDecoder::profile(PprofWriter::encode(self::tree('07-excimer-recursion', 0.001), 0.001, true));

        Assert::same($profile['sample_type'], [['samples', 'count'], ['cpu', 'nanoseconds']]);
        Assert::same($profile['period_type'], ['cpu', 'nanoseconds']);
        Assert::same($profile['default_sample_type'], 'cpu');
        Assert::same($profile['period'], 1_000_000);
    }

    public function unknownTimesAreLeftOut(): void
    {
        $profile = PprofDecoder::profile(PprofWriter::encode(self::tree('07-excimer-recursion', 0.001), 0.001, false));

        Assert::same($profile['time_nanos'], 0);
        Assert::same($profile['duration_nanos'], 0);
    }

    public function compressesWithGzip(): void
    {
        $tree = self::tree('07-excimer-recursion', 0.001);
        $raw = PprofWriter::encode($tree, 0.001, false, gzip: false);

        $compressed = PprofWriter::encode($tree, 0.001, false, gzip: true);

        Assert::same(\substr($compressed, 0, 2), "\x1f\x8b");
        Assert::same(\gzdecode($compressed), $raw);
        Assert::same(PprofWriter::encode($tree, 0.001, false), $compressed);
        Assert::true(PprofDecoder::profile($compressed)['gzip']);
    }

    #[DataSet(['01-excimer-cpu-phpt', 0.1, [
        'main();foo;bar;{closure:/data/cpu.php(5)}' => '8/800000000',
        'main();foo;bar;{closure:/data/cpu.php(8)}' => '7/700000000',
        'main();foo;bar;C::member' => '7/700000000',
        'main();foo;bar;baz' => '8/800000000',
    ]], 'a file-scope root becomes main()')]
    #[DataSet(['03-excimer-truncated-mixed', 0.001, [
        'main();/app/public/index.php;App\Kernel::handle;App\Controller\TreeController::show;App\Tree\Node::render' => '1/1000000',
        'main();/app/public/index.php;App\Kernel::handle;App\Controller\TreeController::show;App\Tree\Node::render;App\Tree\Node::render' => '1/1000000',
        'main();excimer_truncated;App\Controller\TreeController::show;App\Tree\Node::render;App\Tree\Node::render;App\Tree\Node::render;App\Tree\Node::render' => '2/2000000',
        'main();excimer_truncated;App\Tree\Node::render;App\Tree\Node::render;App\Tree\Node::render;App\Tree\Node::render;App\Tree\Node::render' => '1/1000000',
    ]], 'truncated stacks as a second root under main()')]
    #[DataSet(['04-excimer-overrun-event-count', 0.001, [
        'main();App\Bootstrap::boot' => '1/1000000',
        'main();App\Service\Weather::fetch' => '1/1000000',
        'main();App\Service\Weather::fetch;App\Http\Client::send' => '250/250000000',
        'main();App\Cache::retry' => '3/3000000',
    ]], 'event counts above 1')]
    #[DataSet(['09-excimer-shutdown-roots', 0.001, [
        'main();/app/public/index.php;App\Kernel::handle' => '3/3000000',
        'main();{closure:/app/public/index.php(7)}' => '1/1000000',
        'main();Monolog\Handler\BufferHandler::__destruct;Monolog\Handler\BufferHandler::close' => '2/2000000',
    ]], 'several roots under main()')]
    #[DataSet(['11-excimer-command-line-root', 0.001, [
        'main()' => '1/1000000',
        'main();work' => '2/2000000',
    ]], 'php -r code as the root')]
    #[DataSet(['13-excimer-empty-stack', 0.001, [
        'main()' => '3/3000000',
        'main();work' => '1/1000000',
    ]], 'an empty stack goes to main()')]
    #[DataSet(['g3-sampled-zero-weight', 0.001, [
        'main();a' => '1/1000000',
    ]], 'zero weights are skipped')]
    public function fixtureBecomesStacks(string $fixture, float $period, array $expected): void
    {
        $profile = PprofDecoder::profile(PprofWriter::encode(self::tree($fixture, $period), $period, false));

        Assert::same(PprofDecoder::stacks($profile), $expected);
    }

    #[DataSet(['01-excimer-cpu-phpt', 0.1])]
    #[DataSet(['02-excimer-maxdepth-phpt', 0.1])]
    #[DataSet(['03-excimer-truncated-mixed', 0.001])]
    #[DataSet(['04-excimer-overrun-event-count', 0.001])]
    #[DataSet(['07-excimer-recursion', 0.001])]
    #[DataSet(['08-excimer-real-world-names', 0.001])]
    #[DataSet(['09-excimer-shutdown-roots', 0.001])]
    #[DataSet(['10-excimer-anonymous-class-root', 0.001])]
    #[DataSet(['11-excimer-command-line-root', 0.001])]
    #[DataSet(['13-excimer-empty-stack', 0.001])]
    #[DataSet(['upstream/two-sampled.speedscope', 1.0])]
    public function totalsAgreeWithXHProf(string $fixture, float $period): void
    {
        $tree = self::tree($fixture, $period);
        $profile = PprofDecoder::profile(PprofWriter::encode($tree, $period, false));
        $main = \json_decode(XHProfWriter::json($tree->toGraph()), true)['main()'];

        $count = 0;
        $time = 0;
        foreach ($profile['samples'] as $sample) {
            Assert::count($sample['values'], 2);
            Assert::int($sample['values'][0])->greaterThan(0);
            Assert::true($sample['locations'] !== []);
            Assert::same(\count(\array_filter($sample['locations'])), \count($sample['locations']));
            // Leaf first: the root is the last location.
            Assert::same($profile['functions'][\end($sample['locations'])]['name'], 'main()');
            $count += $sample['values'][0];
            $time += $sample['values'][1];
        }

        Assert::same([$count, $time], [$main['ct'], $main['wt'] * 1000]);
        Assert::same(\array_keys($profile['functions']), \range(1, \count($profile['functions'])));
        $keys = \array_map(
            static fn(array $function): string => $function['name'] . "\0" . $function['filename'],
            $profile['functions'],
        );
        Assert::same(\count($keys), \count(\array_unique($keys)));
    }

    #[DataSet(['01-excimer-cpu-phpt', 0.1])]
    #[DataSet(['03-excimer-truncated-mixed', 0.001])]
    #[DataSet(['04-excimer-overrun-event-count', 0.001])]
    #[DataSet(['07-excimer-recursion', 0.001])]
    #[DataSet(['08-excimer-real-world-names', 0.001])]
    #[DataSet(['09-excimer-shutdown-roots', 0.001])]
    #[DataSet(['13-excimer-empty-stack', 0.001])]
    public function serverReadsTheTotalsOfXHProf(string $fixture, float $period): void
    {
        $document = \json_decode((string) \file_get_contents(self::FIXTURES . $fixture . '.json'), true);
        $builder = new ProfileBuilder();

        foreach ([false, true] as $cpu) {
            $tree = SpeedscopeReader::read($document, $period, $cpu);
            $main = \json_decode(XHProfWriter::json($tree->toGraph()), true)['main()'];

            $profile = $builder->createProfile($builder->decode(PprofWriter::encode($tree, $period, $cpu)));

            Assert::same($profile->metadata['clock'] ?? null, $cpu ? 'cpu' : 'wall');
            Assert::same([$profile->peaks->ct, $profile->peaks->wt], [$main['ct'], $main['wt']]);
            Assert::same($profile->peaks->cpu, $main['cpu']);
        }
    }

    public function timeIsExactForAPeriodOfFractionalMicroseconds(): void
    {
        // Excimer weighs a sample by its event count times the period in nanoseconds.
        $document = [
            'shared' => ['frames' => [['name' => 'a', 'file' => '/x.php'], ['name' => 'b', 'file' => '/x.php']]],
            'profiles' => [['type' => 'sampled', 'unit' => 'nanoseconds', 'samples' => [[0, 1], [0, 1], [0]], 'weights' => [1500, 3000, 1500]]],
        ];
        $tree = SpeedscopeReader::read($document, 0.0000015, false);

        $profile = PprofDecoder::profile(PprofWriter::encode($tree, 0.0000015, false));

        Assert::same($profile['period'], 1500);
        Assert::same(PprofDecoder::stacks($profile), ['main();a' => '1/1500', 'main();a;b' => '3/4500']);
    }

    public function cpuClockWritesTheCpuTime(): void
    {
        $tree = new CallTree();
        $tree->add($tree->node(CallTree::ROOT, 0, 'work'), 3_000_000, 1_000_000, 1);

        Assert::same(PprofDecoder::stacks(PprofDecoder::profile(PprofWriter::encode($tree, 0.001, true))), ['main();work' => '1/1000000']);
        Assert::same(PprofDecoder::stacks(PprofDecoder::profile(PprofWriter::encode($tree, 0.001, false))), ['main();work' => '1/3000000']);
    }

    #[DataSet(['01-excimer-cpu-phpt', '/data/cpu.php'], 'the script of main()')]
    #[DataSet(['09-excimer-shutdown-roots', ''], 'no script')]
    public function locationsBelongToOneMapping(string $fixture, string $filename): void
    {
        $profile = PprofDecoder::profile(PprofWriter::encode(self::tree($fixture, 0.001), 0.001, false));

        Assert::same($profile['mappings'], [1 => ['filename' => $filename, 'has_functions' => true, 'has_filenames' => true]]);
        Assert::same(\array_unique($profile['location_mappings']), [1 => 1]);
    }

    public function deepStacksAreWritten(): void
    {
        $depth = 2000;
        $samples = [];
        for ($i = 1; $i <= 200; ++$i) {
            $samples[] = \range(0, $depth - $i);
        }
        $frames = \array_map(static fn(int $i): array => ['name' => "f{$i}", 'file' => '/deep.php'], \range(0, $depth));
        $document = ['shared' => ['frames' => $frames], 'profiles' => [['type' => 'sampled', 'unit' => 'nanoseconds', 'samples' => $samples, 'weights' => \array_fill(0, 200, 1_000_000)]]];
        $tree = SpeedscopeReader::read($document, 0.001, false);

        $profile = PprofDecoder::profile(PprofWriter::encode($tree, 0.001, false));

        Assert::count($profile['samples'], 200);
        Assert::same(\max(\array_map(static fn(array $sample): int => \count($sample['locations']), $profile['samples'])), $depth + 1);
        Assert::same(\array_sum(\array_map(static fn(array $sample): int => $sample['values'][1], $profile['samples'])), 200_000_000);
    }

    public function functionsAreSharedBetweenStacks(): void
    {
        $profile = PprofDecoder::profile(PprofWriter::encode(self::tree('03-excimer-truncated-mixed', 0.001), 0.001, false));

        Assert::same(\array_column($profile['functions'], 'name'), [
            'main()',
            '/app/public/index.php',
            'App\Kernel::handle',
            'App\Controller\TreeController::show',
            'App\Tree\Node::render',
            'excimer_truncated',
        ]);
    }

    public function functionsWithoutAFileHaveAnEmptyFilename(): void
    {
        $profile = PprofDecoder::profile(PprofWriter::encode(self::tree('09-excimer-shutdown-roots', 0.001), 0.001, false));

        Assert::same($profile['functions'][1], ['name' => 'main()', 'system_name' => 'main()', 'filename' => '']);
    }

    public function replacesInvalidUtf8(): void
    {
        $tree = new CallTree();
        $tree->add($tree->node(CallTree::ROOT, 0, "bad\xFFname", "/app/\xC3.php"), 1000, 0, 1);

        $profile = PprofDecoder::profile(PprofWriter::encode($tree, 0.001, false));

        Assert::same($profile['functions'][2]['name'], "bad\u{FFFD}name");
        Assert::same($profile['functions'][2]['filename'], "/app/\u{FFFD}.php");
    }

    public function emptyTreeHasNoSamples(): void
    {
        $profile = PprofDecoder::profile(PprofWriter::encode(new CallTree(), 0.001, false));

        Assert::same($profile['samples'], []);
        Assert::same($profile['strings'][0], '');
        Assert::same($profile['sample_type'], [['samples', 'count'], ['wall', 'nanoseconds']]);
    }

    #[DataSet([0, "\x00"])]
    #[DataSet([127, "\x7F"])]
    #[DataSet([300, "\xAC\x02"])]
    #[DataSet([\PHP_INT_MAX, "\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\x7F"])]
    #[DataSet([-1, "\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\x01"], 'a negative number takes 10 bytes')]
    #[DataSet([\PHP_INT_MIN, "\x80\x80\x80\x80\x80\x80\x80\x80\x80\x01"], 'the minimum')]
    public function writesVarints(int $value, string $expected): void
    {
        $varint = (static fn(int $value): string => self::varint($value))->bindTo(null, PprofWriter::class);

        Assert::same($varint($value), $expected);
        Assert::same(PprofDecoder::varints($expected), [$value]);
    }

    private static function tree(string $fixture, float $period): CallTree
    {
        $document = \json_decode((string) \file_get_contents(self::FIXTURES . $fixture . '.json'), true);

        return SpeedscopeReader::read($document, $period, false);
    }
}
