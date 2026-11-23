<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Csp;

use Buggregator\Trap\Module\Csp\Report;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Test;

#[Covers(Report::class)]
#[Test]
final class ReportTest
{
    /** Bodies as Chrome sends them. */
    public const CSP_REPORT = '{"csp-report":{"document-uri":"https://shop.test/cart","referrer":"","violated-directive":"script-src-elem","effective-directive":"script-src-elem","original-policy":"default-src \'self\'; report-uri /csp","disposition":"enforce","blocked-uri":"https://cdn.evil.test/x.js","line-number":12,"column-number":7,"source-file":"https://shop.test/cart","status-code":200,"script-sample":""}}';

    public const REPORTS = '[{"age":10,"body":{"blockedURL":"inline","columnNumber":39,"disposition":"enforce","documentURL":"https://shop.test/","effectiveDirective":"script-src-elem","lineNumber":121,"originalPolicy":"script-src \'self\'; report-to csp","referrer":"","sample":"console.log(1)","sourceFile":"https://shop.test/","statusCode":200},"type":"csp-violation","url":"https://shop.test/","user_agent":"Mozilla/5.0 Chrome/131"},{"age":3,"body":{"columnNumber":5,"id":"PrefixedStorageInfo","lineNumber":2,"message":"\'window.webkitStorageInfo\' is deprecated.","sourceFile":"https://shop.test/app.js"},"type":"deprecation","url":"https://shop.test/","user_agent":"Mozilla/5.0 Chrome/131"}]';

    #[DataSet(['application/csp-report', Report::FORMAT_CSP])]
    #[DataSet(['Application/CSP-Report; charset=utf-8', Report::FORMAT_CSP])]
    #[DataSet(['application/reports+json', Report::FORMAT_REPORTS])]
    #[DataSet(['application/json', null])]
    #[DataSet(['', null])]
    public function testFormatOfContentType(string $contentType, ?string $format): void
    {
        Assert::same(Report::formatOf($contentType), $format);
    }

    public function testReadsCspReport(): void
    {
        [$format, $reports] = Report::parse(Report::FORMAT_CSP, self::CSP_REPORT);

        Assert::same($format, Report::FORMAT_CSP);
        Assert::count($reports, 1);
        $report = $reports[0];
        unset($report['body']);
        Assert::same($report, [
            'type' => 'csp-violation',
            'url' => 'https://shop.test/cart',
            'directive' => 'script-src-elem',
            'blocked' => 'https://cdn.evil.test/x.js',
            'disposition' => 'enforce',
            'source_file' => 'https://shop.test/cart',
            'line' => 12,
            'column' => 7,
            'policy' => "default-src 'self'; report-uri /csp",
        ]);
        Assert::same($reports[0]['body']['status-code'] ?? null, 200);
    }

    public function testViolatedDirectiveStandsInForEffectiveOne(): void
    {
        [, $reports] = Report::parse(null, '{"csp-report":{"document-uri":"http://a/","violated-directive":"img-src","blocked-uri":"data"}}');

        Assert::same($reports[0]['directive'] ?? null, 'img-src');
    }

    public function testReadsReportingApiBatch(): void
    {
        [$format, $reports] = Report::parse(Report::FORMAT_REPORTS, self::REPORTS);

        Assert::same($format, Report::FORMAT_REPORTS);
        Assert::count($reports, 2);
        [$violation, $deprecation] = $reports;
        Assert::same($violation['blocked'] ?? null, 'inline');
        Assert::same($violation['sample'] ?? null, 'console.log(1)');
        Assert::same($violation['line'] ?? null, 121);
        Assert::same($violation['user_agent'] ?? null, 'Mozilla/5.0 Chrome/131');
        Assert::same($deprecation['type'], 'deprecation');
        Assert::same($deprecation['id'] ?? null, 'PrefixedStorageInfo');
        Assert::same($deprecation['message'] ?? null, "'window.webkitStorageInfo' is deprecated.");
        Assert::same($deprecation['source_file'] ?? null, 'https://shop.test/app.js');
        Assert::false(isset($deprecation['directive']));
    }

    #[DataSet([self::CSP_REPORT, Report::FORMAT_CSP], 'object')]
    #[DataSet([" \n" . self::REPORTS, Report::FORMAT_REPORTS], 'list')]
    public function testGuessesFormatByBody(string $body, string $format): void
    {
        Assert::same(Report::parse(null, $body)[0], $format);
    }

    #[DataSet([Report::FORMAT_CSP, '{"x":1}'], 'no csp-report')]
    #[DataSet([Report::FORMAT_CSP, '{"csp-report":[]}'], 'empty csp-report')]
    #[DataSet([Report::FORMAT_REPORTS, '[]'], 'empty list')]
    #[DataSet([Report::FORMAT_REPORTS, '{"type":"deprecation"}'], 'object')]
    #[DataSet([Report::FORMAT_REPORTS, '[1]'], 'not an object')]
    #[DataSet([null, 'not json'], 'not json')]
    public function testRejectsOtherDocuments(?string $format, string $body): void
    {
        Expect::exception(\InvalidArgumentException::class);

        Report::parse($format, $body);
    }

    public function testEmptyBodyStaysObject(): void
    {
        [, $reports] = Report::parse(null, '[{"type":"crash","url":"http://a/","body":{}}]');

        Assert::same(\json_encode($reports[0]), '{"type":"crash","url":"http:\/\/a\/","body":{}}');
    }

    public function testFieldsOfWrongTypesAreLeftOut(): void
    {
        [, $reports] = Report::parse(null, '[{"type":"csp-violation","url":7,"body":{"lineNumber":"12","columnNumber":-1,"blockedURL":["x"],"statusCode":1e999}}]');

        $report = $reports[0];
        unset($report['body']);
        Assert::same($report, ['type' => 'csp-violation', 'url' => '', 'line' => 12]);
        Assert::string(\json_encode($reports, \JSON_THROW_ON_ERROR))->contains('"statusCode":"Infinity"');
    }

    public function testRestoresReport(): void
    {
        [, $reports] = Report::parse(null, self::REPORTS);

        $restored = \array_map(Report::restore(...), \json_decode(\json_encode($reports, \JSON_THROW_ON_ERROR), true));

        Assert::same($restored, $reports);
    }

    public function testSourceHasLineAndColumn(): void
    {
        Assert::same(Report::source(['type' => '', 'url' => '', 'source_file' => 'a.js', 'line' => 3, 'column' => 4, 'body' => []]), 'a.js:3:4');
        Assert::same(Report::source(['type' => '', 'url' => '', 'source_file' => 'a.js', 'body' => []]), 'a.js');
        Assert::same(Report::source(['type' => '', 'url' => '', 'line' => 3, 'body' => []]), '');
    }
}
