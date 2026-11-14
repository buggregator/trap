<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Support\Spx;

use Buggregator\Trap\Support\Spx\Metadata;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Test;

#[Covers(Metadata::class)]
#[Test]
final class MetadataTest
{
    private const STUB = __DIR__ . '/../../../Stub/spx/handwritten.json';

    public function testReadsReportMetadata(): void
    {
        $metadata = Metadata::fromJson((string) \file_get_contents(self::STUB));

        Assert::same($metadata->metrics, ['wt', 'ct', 'zm', 'zgr', 'io']);
        Assert::same($metadata->functionCount, 4);
        Assert::same($metadata->callCount, 6);
        Assert::same([$metadata->column('wt'), $metadata->column('zm'), $metadata->column('it')], [0, 2, null]);
        Assert::same($metadata->string('http_method'), 'POST');
        // SPX writes `n/a` for what it doesn't know
        Assert::same($metadata->string('cli_command_line'), null);
        Assert::same($metadata->int('exec_ts'), 1747569600);
        Assert::same($metadata->isCli(), false);
    }

    #[DataSet(['[]', 'The metadata is not a JSON object.'], 'list')]
    #[DataSet(['{"called_function_count": 1, "recorded_call_count": 1}', 'No `enabled_metrics` list in the metadata.'], 'no metrics')]
    #[DataSet(['{"enabled_metrics": ["WT"], "called_function_count": 1, "recorded_call_count": 1}', 'Invalid metric in `enabled_metrics`.'], 'bad metric')]
    #[DataSet(['{"enabled_metrics": ["wt", "wt"], "called_function_count": 1, "recorded_call_count": 1}', 'Repeated metric in `enabled_metrics`.'], 'repeated metric')]
    #[DataSet(['{"enabled_metrics": [], "recorded_call_count": 1}', 'No `called_function_count` count in the metadata.'], 'no function count')]
    #[DataSet(['{"enabled_metrics": [], "called_function_count": 1, "recorded_call_count": -1}', 'No `recorded_call_count` count in the metadata.'], 'negative call count')]
    #[DataSet(['{"enabled_metrics": [], "called_function_count": 999999999, "recorded_call_count": 1}', 'The `called_function_count` is over 262144.'], 'too many functions')]
    public function testRejectsInvalidMetadata(string $json, string $message): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage($message);

        Metadata::fromJson($json);
    }

    public function testRejectsTooDeepJson(): void
    {
        try {
            Metadata::fromJson('{"a": [[[[[[[[1]]]]]]]]}');
            Assert::fail('No exception thrown.');
        } catch (\InvalidArgumentException $e) {
            // PHP 8.6 appends the error location to the json_decode() message
            Assert::true(\str_starts_with($e->getMessage(), 'Invalid metadata JSON: Maximum stack depth exceeded'));
        }
    }

    public function testReadsRawControlCharactersSpxLeavesUnescaped(): void
    {
        // SPX escapes `\n` and the like only; a bell or an escape sequence comes raw
        $json = \str_replace(
            '"release=1.2.3"',
            "\"release\x07=\x1b[31m1.2.3\"",
            (string) \file_get_contents(self::STUB),
        );

        $metadata = Metadata::fromJson($json);

        Assert::same($metadata->string('custom_metadata_str'), "release\x07=\x1b[31m1.2.3");
    }

    public function testRejectsTooLargeMetadata(): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage('The metadata is larger than 65536 bytes.');

        Metadata::fromJson(\str_repeat(' ', 70_000));
    }
}
