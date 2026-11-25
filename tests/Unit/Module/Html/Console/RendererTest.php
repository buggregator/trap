<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Html\Console;

use Buggregator\Trap\Module\Html\Console\Renderer;
use Buggregator\Trap\Module\Html\Frame;
use Buggregator\Trap\Tests\Unit\Module\Html\PageTest;
use Symfony\Component\Console\Output\BufferedOutput;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Covers(Renderer::class)]
#[Test]
final class RendererTest
{
    public function testRendersTitleSizeAndText(): void
    {
        $output = new BufferedOutput();

        (new Renderer())->render($output, new Frame('Nightly <error>report', PageTest::PAGE, 'shop'));
        $text = (string) \preg_replace('/ +/', ' ', $output->fetch());

        Assert::string($text)
            ->contains('HTML shop')
            ->contains('Title: Nightly <error>report')
            ->contains('Size: ' . \strlen(PageTest::PAGE) . '.00 B')
            ->contains('Text: OK')
            ->notContains('<h1>');
    }

    public function testCutsLongText(): void
    {
        $output = new BufferedOutput();

        (new Renderer())->render($output, new Frame('', '<p>' . \str_repeat('a', 300) . '</p>'));
        $text = $output->fetch();

        Assert::string($text)
            ->contains(\str_repeat('a', Renderer::EXCERPT_LENGTH) . '…')
            ->notContains(\str_repeat('a', Renderer::EXCERPT_LENGTH + 1))
            ->notContains('Title:');
    }
}
