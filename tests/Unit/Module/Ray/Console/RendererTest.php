<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Ray\Console;

use Buggregator\Trap\Module\Ray\Console\Renderer;
use Buggregator\Trap\Module\Ray\Frame;
use Buggregator\Trap\Proto\Frame\Http;
use Nyholm\Psr7\ServerRequest;
use Symfony\Component\Console\Output\BufferedOutput;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Covers(Renderer::class)]
#[Test]
final class RendererTest
{
    public function testSupportsRayFramesOnly(): void
    {
        Assert::true((new Renderer())->isSupport(new Frame('a1', [])));
        Assert::false((new Renderer())->isSupport(new Http(new ServerRequest('GET', '/'))));
    }

    public function testSummarizesPayloads(): void
    {
        $output = new BufferedOutput();
        $frame = new Frame('a1', [
            [
                'type' => 'log',
                'content' => ['values' => ['hello', '<style>pre{}</style><pre class=sf-dump>array:1 [&quot;a&quot;]</pre>']],
                'origin' => ['file' => '/app/index.php', 'line_number' => 7, 'hostname' => 'box'],
            ],
            ['type' => 'label', 'content' => ['label' => 'mine']],
            ['type' => 'exception', 'content' => ['class' => 'RuntimeException', 'message' => 'Boom']],
            ['type' => 'create_lock', 'content' => ['name' => 'abc']],
            ['type' => 'confetti', 'content' => []],
        ], ['project_name' => 'shop']);

        (new Renderer())->render($output, $frame);
        $text = (string) \preg_replace('/ +/', ' ', $output->fetch());

        Assert::string($text)
            ->contains('RAY')
            ->contains('/app/index.php:7')
            ->contains('box')
            ->contains('shop')
            ->contains('log hello, array:1 ["a"]')
            ->contains('label mine')
            ->contains('exception RuntimeException: Boom')
            ->contains('create_lock pause(), lock abc')
            ->contains('confetti');
    }
}
