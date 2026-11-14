<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Support\Spx;

use Buggregator\Trap\Support\Spx\FunctionName;
use Buggregator\Trap\Support\Spx\Metadata;
use Buggregator\Trap\Support\Spx\TraceReader;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Test;

/**
 * The reports of `tests/Stub/spx/real`, recorded with ext-spx.
 */
#[Covers(Metadata::class)]
#[Covers(TraceReader::class)]
#[Covers(FunctionName::class)]
#[Test]
final class RealReportTest
{
    public const DIR = __DIR__ . '/../../../Stub/spx/real';

    public static function metadata(string $report): Metadata
    {
        return Metadata::fromJson((string) \file_get_contents(self::DIR . "/$report.json"));
    }

    /**
     * @param list<non-empty-string> $metrics
     */
    #[DataSet(['default', ['wt', 'zm'], 7, 21, true])]
    #[DataSet(['metrics', ['wt', 'ct', 'zm', 'zgr', 'zif', 'io'], 10, 25, true])]
    #[DataSet(['depth', ['wt', 'zm'], 3, 3, true])]
    #[DataSet(['span', ['wt', 'zm'], 6, 9, true])]
    #[DataSet(['shutdown', ['wt', 'zm'], 7, 8, true])]
    #[DataSet(['sampling', ['wt', 'zm'], 5, 7, true])]
    #[DataSet(['http', ['wt', 'zm'], 2, 2, false])]
    public function readsMetadata(string $report, array $metrics, int $functions, int $calls, bool $cli): void
    {
        $metadata = self::metadata($report);

        Assert::same($metadata->metrics, $metrics);
        Assert::same($metadata->functionCount, $functions);
        Assert::same($metadata->callCount, $calls);
        Assert::same($metadata->int('call_count'), $calls);
        Assert::same($metadata->isCli(), $cli);
        Assert::same($metadata->string('http_request_uri') === null, $cli);
        Assert::same($metadata->column('zm'), \array_search('zm', $metrics, true));
    }

    public function readsMetadataOfWebRequest(): void
    {
        $metadata = self::metadata('http');

        Assert::same($metadata->string('http_method'), 'POST');
        Assert::same($metadata->string('http_host'), 'shop.test');
        Assert::same($metadata->string('http_request_uri'), '/checkout?step=2');
        // SPX escapes the tab, but writes the control character raw
        Assert::same($metadata->string('custom_metadata_str'), "release=1.2.3\tname=Café\x01");
    }

    public function takesNullCustomMetadataForNone(): void
    {
        Assert::null(self::metadata('default')->string('custom_metadata_str'));
        Assert::null(self::metadata('default')->string('http_request_uri'));
    }

    #[DataSet(['default'])]
    #[DataSet(['metrics'])]
    #[DataSet(['depth'])]
    #[DataSet(['span'])]
    #[DataSet(['shutdown'])]
    #[DataSet(['sampling'])]
    #[DataSet(['http'])]
    public function readsEveryEventOfTrace(string $report): void
    {
        $metadata = self::metadata($report);
        $wt = (int) $metadata->column('wt');
        $starts = $ends = 0;
        $time = 0.0;
        $monotonic = true;

        $names = self::read($report, static function (int $function, bool $start, array $values) use (
            $metadata,
            $wt,
            &$starts,
            &$ends,
            &$time,
            &$monotonic,
        ): void {
            Assert::count($values, \count($metadata->metrics));
            $start ? ++$starts : ++$ends;
            $monotonic = $monotonic && $values[$wt] >= $time;
            $time = $values[$wt];
        });

        Assert::count($names, $metadata->functionCount);
        Assert::same($starts, $metadata->callCount);
        Assert::same($ends, $metadata->callCount);
        Assert::true($monotonic);
        // `wall_time_ms` is the last wall time, in nanoseconds, divided by 1000: microseconds, despite the name
        Assert::same((int) ($time / 1000), $metadata->int('wall_time_ms'));
    }

    public function namesFunctionsAsSpxDoes(): void
    {
        Assert::same(self::read('metrics'), [
            '::zend_compile_file',
            '/work/calls.php',
            '/work/lib.php',
            'run',
            'fib',
            '{closure}',
            'lib',
            'Greeter::greet',
            'strtoupper',
            '::php_request_shutdown',
        ]);
    }

    public function tellsFileScopeCodeByItsName(): void
    {
        $fileScope = \array_values(\array_filter(self::read('metrics'), FunctionName::isFileScope(...)));

        // The pseudo functions of SPX_BUILTINS are not file-scope code
        Assert::same($fileScope, ['/work/calls.php', '/work/lib.php']);
    }

    public function readsNegativeFractionalValuesOfSampledTrace(): void
    {
        // SPX writes a negative integer less 0.0001, as `-935.9999`
        $memory = [];
        self::read('sampling', static function (int $function, bool $start, array $values) use (&$memory): void {
            $memory[] = $values[1];
        });

        Assert::true(\min($memory) < 0);
        Assert::true(\fmod(\min($memory), 1.0) !== 0.0);
    }

    public function rejectsTraceCutMidStream(): void
    {
        Expect::exception(new \InvalidArgumentException('The trace is truncated: the gzip stream does not end.'));

        self::read('truncated');
    }

    /**
     * @param (\Closure(int, bool, list<float>): void)|null $onEvent
     *
     * @return list<non-empty-string>
     */
    private static function read(string $report, ?\Closure $onEvent = null): array
    {
        $metadata = self::metadata($report);
        $handle = \fopen(self::DIR . "/$report.txt.gz", 'rb');
        try {
            return TraceReader::read(
                TraceReader::chunks($handle, 64),
                \count($metadata->metrics),
                $metadata->functionCount,
                $onEvent ?? static function (): void {},
            );
        } finally {
            \fclose($handle);
        }
    }
}
