<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Csp\Console;

use Buggregator\Trap\Module\Csp\Console\Renderer;
use Buggregator\Trap\Module\Csp\Frame;
use Buggregator\Trap\Module\Csp\Report;
use Buggregator\Trap\Tests\Unit\Module\Csp\ReportTest;
use Symfony\Component\Console\Output\BufferedOutput;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Covers(Renderer::class)]
#[Test]
final class RendererTest
{
    public function testRendersBlockPerReport(): void
    {
        [$format, $reports] = Report::parse(null, ReportTest::REPORTS);
        $output = new BufferedOutput();

        (new Renderer())->render($output, new Frame($format, $reports, 'shop'));
        $text = (string) \preg_replace('/ +/', ' ', $output->fetch());

        Assert::string($text)
            ->contains('CSP reports+json shop')
            ->contains('# script-src-elem blocked inline')
            ->contains('Page: https://shop.test/')
            ->contains('Directive: script-src-elem')
            ->contains('Disposition: enforce (blocked)')
            ->contains('Source: https://shop.test/:121:39')
            ->contains('Sample: console.log(1)')
            ->contains("# deprecation: 'window.webkitStorageInfo' is deprecated.")
            ->contains('ID: PrefixedStorageInfo')
            ->contains('Source: https://shop.test/app.js:2:5');
    }

    public function testEscapesMarkupOfReport(): void
    {
        [$format, $reports] = Report::parse(null, '{"csp-report":{"document-uri":"http://a/<fg=red>x</>","blocked-uri":"<b>","disposition":"report"}}');
        $output = new BufferedOutput();

        (new Renderer())->render($output, new Frame($format, $reports));

        Assert::string($output->fetch())
            ->contains('http://a/<fg=red>x</>')
            ->contains('CSP blocked <b>')
            ->contains('report (Report-Only: not blocked)');
    }
}
