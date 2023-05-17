<?php

declare(strict_types=1);

namespace Buggregator\Client\Sender\Console\Renderer;

use Buggregator\Client\Proto\Frame;
use Buggregator\Client\Sender\Console\RendererInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Termwind\HtmlRenderer;

final class Plain implements RendererInterface
{
    public function __construct(
        private readonly HtmlRenderer $renderer,
    ) {
    }

    public function isSupport(Frame $frame): bool
    {
        return true;
    }

    public function render(OutputInterface $output, Frame $frame): void
    {
        $date = $frame->time->format('Y-m-d H:i:s.u');
        $channel = $frame->type->value;

        $this->renderer->render(
            <<<HTML
            <div class="mt-2">
                <table>
                    <tr>
                        <th>date</th>
                        <td>$date</td>
                    </tr>
                </table>

                <h1 class="font-bold text-white my-1">
                    <span class="bg-blue px-1">$channel</span>
                </h1>

                <div class="mb-1">
                    $frame
                </div>
            </div>
            HTML
            ,
            0
        );
    }
}
