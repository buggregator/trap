<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Csp\Mcp;

use Buggregator\Trap\Module\Csp\Frame;
use Buggregator\Trap\Module\Csp\Frontend\Mapper;
use Buggregator\Trap\Module\Csp\Mcp\CspView;
use Buggregator\Trap\Module\Csp\Report;
use Buggregator\Trap\Server\Event\Event;
use Buggregator\Trap\Tests\Unit\Module\Csp\ReportTest;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Covers(CspView::class)]
#[Covers(Mapper::class)]
#[Test]
final class CspViewTest
{
    public function testMapsFrameToUiEvent(): void
    {
        $event = self::event(ReportTest::CSP_REPORT, 'shop');

        Assert::same($event->type, 'csp');
        Assert::same($event->projectKey(), 'shop');
        Assert::same(
            \json_decode(\json_encode($event, \JSON_THROW_ON_ERROR), true)['payload']['reports'][0]['blocked'] ?? null,
            'https://cdn.evil.test/x.js',
        );
    }

    public function testSummaryNamesFirstReportAndPage(): void
    {
        $view = new CspView();

        Assert::same(
            $view->summary(self::event(ReportTest::CSP_REPORT)),
            'script-src-elem blocked https://cdn.evil.test/x.js on https://shop.test/cart',
        );
        Assert::same(
            $view->summary(self::event(ReportTest::REPORTS)),
            'script-src-elem blocked inline on https://shop.test/ (+1 more)',
        );
        Assert::null($view->summary(new Event('a1', 'monolog', ['message' => 'x'], 1.0)));
    }

    public function testFieldsOfAllReports(): void
    {
        $fields = (new CspView())->fields(self::event(ReportTest::REPORTS));

        Assert::same($fields, [
            'report_type' => ['csp-violation', 'deprecation'],
            'directive' => ['script-src-elem'],
            'blocked' => ['inline'],
            'disposition' => ['enforce'],
            'page' => ['https://shop.test/'],
            'source_file' => ['https://shop.test/', 'https://shop.test/app.js'],
        ]);
        Assert::same(\array_keys((new CspView())->fieldNames()), ['csp']);
    }

    private static function event(string $body, ?string $project = null): Event
    {
        [$format, $reports] = Report::parse(null, $body);

        return (new Mapper())->map(new Frame($format, $reports, $project));
    }
}
