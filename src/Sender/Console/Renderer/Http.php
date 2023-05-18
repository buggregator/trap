<?php

declare(strict_types=1);

namespace Buggregator\Client\Sender\Console\Renderer;

use Buggregator\Client\Proto\Frame;
use Buggregator\Client\ProtoType;
use Buggregator\Client\Sender\Console\RendererInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Termwind\HtmlRenderer;

final class Http implements RendererInterface
{
    public function __construct(
        private readonly HtmlRenderer $renderer,
    ) {
    }

    public function isSupport(Frame $frame): bool
    {
        return $frame->type === ProtoType::HTTP;
    }

    /**
     * @param Frame\Http $frame
     */
    public function render(OutputInterface $output, Frame $frame): void
    {
        $date = $frame->time->format('Y-m-d H:i:s.u');
        $color = match ($frame->request->getMethod()) {
            'GET' => 'blue',
            'POST', 'PUT', 'PATCH' => 'green',
            'DELETE' => 'red',
            default => 'gray'
        };

        $uri = (string)$frame->request->getUri();
        $method = $frame->request->getMethod();
        $body = $frame->request->getBody();

        $data = $this->renderData($frame->request);

        $this->renderer->render(
            <<<HTML
            <div class="mt-2">
                <table>
                    <tr>
                        <th>date</th>
                        <td>$date</td>
                    </tr>
                    <tr>
                        <th>uri</th>
                        <td>$uri</td>
                    </tr>
                </table>

                <h1 class="text-white my-1">
                    <span class="bg-blue px-1"><strong>HTTP</strong></span>
                    <span class="px-1 bg-$color">$method</span>
                </h1>

                $data

                <div class="mb-1">
                    $body
                </div>
            </div>
            HTML
            ,
            0
        );
    }

    private function renderData(ServerRequestInterface $request): string
    {
        $html = '';

        if ($request->getCookieParams() !== []) {
            $html .= $this->renderTable('Cookies', $request->getCookieParams());
        }

        if ($request->getQueryParams() !== []) {
            $html .= $this->renderTable('Query params', $request->getQueryParams());
        }

        if ($request->getHeaders() !== []) {
            $html .= $this->renderTable(
                'Headers',
                \array_map(fn(array $lines) => \implode("\n", $lines), $request->getHeaders()),
                ['Cookie']
            );
        }

        return $html;
    }

    private function renderTable(string $title, array $data, array $exclude = []): string
    {
        $html = '<h2 class="mt-1"><strong>' . $title . '</strong></h2><table>';
        foreach ($data as $key => $value) {
            if (\in_array($key, $exclude, true)) {
                continue;
            }
            $value = \is_string($value) ? $value : \json_encode($value);
            if (\strlen($value) > 100) {
                $value = \str_split($value, 100);
            }

            if (\is_array($value)) {
                $i = 0;
                foreach ($value as $line) {
                    if ($i > 0) {
                        $key = '';
                    }

                    $html .= <<<HTML
                    <tr>
                        <th>$key</th>
                        <td>$line</td>
                    </tr>
                    HTML;

                    $i++;
                }
            } else {
                $html .= <<<HTML
                <tr>
                    <th>$key</th>
                    <td>$value</td>
                </tr>
                HTML;
            }
        }
        $html .= '</table>';

        return $html;
    }
}
