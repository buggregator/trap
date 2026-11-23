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

    /**
     * An unclosed tag in the project, as `/csp/%3Cerror%3Ered` gives, swallowed the whole output.
     */
    public function testEscapesMarkupOfProject(): void
    {
        [$format, $reports] = Report::parse(null, ReportTest::CSP_REPORT);
        $output = new BufferedOutput();

        (new Renderer())->render($output, new Frame($format, $reports, '<error>red'));

        Assert::string($output->fetch())
            ->contains('<error>red')
            ->contains('script-src-elem blocked https://cdn.evil.test/x.js');
    }

    public function testShowsBodyOfNetworkErrorAndCrash(): void
    {
        [$format, $reports] = Report::parse(null, '[{"type":"network-error","url":"https://shop.test/","body":{"elapsed_time":48,"method":"GET","phase":"connection","protocol":"http/1.1","referrer":"","sampling_fraction":1,"server_ip":"203.0.113.7","status_code":0,"type":"tcp.refused"}},{"type":"crash","url":"https://shop.test/","body":{"reason":"oom"}}]');
        $output = new BufferedOutput();

        (new Renderer())->render($output, new Frame($format, $reports));
        $text = (string) \preg_replace('/ +/', ' ', $output->fetch());

        Assert::string($text)
            ->contains('# network-error: tcp.refused')
            ->contains('Error: tcp.refused')
            ->contains('Phase: connection')
            ->contains('Elapsed: 48 ms')
            ->contains('Server IP: 203.0.113.7')
            ->notContains('Status:')
            ->contains('# crash: oom')
            ->contains('Reason: oom');
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
