<?php

declare(strict_types=1);

namespace Buggregator\Client\Sender\Console\Renderer;

use Buggregator\Client\Proto\Frame;
use Buggregator\Client\ProtoType;
use Buggregator\Client\Sender\Console\RendererInterface;
use Buggregator\Client\Sender\Console\Support\RenderTable;
use Psr\Http\Message\UploadedFileInterface;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @implements RendererInterface<Frame\Http>
 */
final class Http implements RendererInterface
{
    use RenderTable;

    public function isSupport(Frame $frame): bool
    {
        return $frame->type === ProtoType::HTTP;
    }

    public function render(OutputInterface $output, Frame $frame): void
    {
        $this->renderData($output, $frame);
    }

    private function renderData(OutputInterface $output, Frame\Http $frame): void
    {
        $request = $frame->request;
        $date = $frame->time->format('Y-m-d H:i:s.u');
        $uri = (string)$request->getUri();
        $method = $request->getMethod();
        $body = $request->getBody();

        $color = match ($frame->request->getMethod()) {
            'GET' => 'blue',
            'POST', 'PUT', 'PATCH' => 'green',
            'DELETE' => 'red',
            default => 'gray'
        };

        $output->writeln(['', \sprintf('<fg=white;bg=blue> HTTP </><fg=white;bg=%s> %s </>', $color, $method), '']);

        $this->renderKeyValueTable($output, '', [
            'Time' => $date,
            'URI' => $uri,
        ]);

        if ($request->getQueryParams() !== []) {
            $output->writeln('');
            $this->renderKeyValueTable($output, 'Query params', $request->getQueryParams());
        }

        if ($request->getCookieParams() !== []) {
            $output->writeln('');
            $this->renderKeyValueTable($output, 'Cookies', $request->getCookieParams());
        }

        if ($request->getHeaders() !== []) {
            $output->writeln('');
            // Exclude Cookies from Headers rendering
            $headers = $request->withoutHeader('Cookie')->getHeaders();
            $this->renderKeyValueTable(
                $output,
                'Headers',
                \array_map(static fn(array $lines): string => \implode("\n", $lines), $headers),
            );
        }

        $hasParsedBody = $request->getParsedBody() !== null;
        $hasFiles = $request->getUploadedFiles() !== [];

        if ($hasParsedBody) {
            $output->writeln('');
            $this->renderKeyValueTable($output, 'Parsed Body', (array)$request->getParsedBody());
        }
        if ($hasFiles) {
            $output->writeln('');
            (new Table($output))
                ->setHeaderTitle('Uploaded files')
                ->setHeaders(['Field Name', 'Client Name', 'MIME Type', 'Size'])
                ->setRows([...(static function (array $files): iterable {
                    /** @var UploadedFileInterface[]|UploadedFileInterface[][] $files*/
                    foreach ($files as $key => $group) {
                        if (!\is_array($group)) {
                            $group = [$group];
                        }
                        foreach ($group as $file) {
                            yield [$key, $file->getClientFilename(), $file->getClientMediaType(), $file->getSize()];
                        }
                    }
                })($request->getUploadedFiles())])
                ->render();
        }

        if (!$hasParsedBody && !$hasFiles && $body->getSize() > 0) {
            $output->writeln('');
            $toRead = \min(256, $body->getSize());
            $output->writeln(\sprintf(
                '<fg=black;bg=white;options=bold> Raw body (first %d bytes of %d) </>',
                $toRead,
                $body->getSize()),
            );
            $body->rewind();
            $read = $body->read($toRead);
            // repcace all non-printable characters
            $read = \preg_replace('/[\x00-\x09\x0F-\x1F]/', '.', $read);
            $output->write($read, true, OutputInterface::OUTPUT_RAW);
        }
    }
}
