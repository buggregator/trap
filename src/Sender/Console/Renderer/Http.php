<?php

declare(strict_types=1);

namespace Buggregator\Client\Sender\Console\Renderer;

use Buggregator\Client\Proto\Frame;
use Buggregator\Client\ProtoType;
use Buggregator\Client\Sender\Console\RendererInterface;
use Psr\Http\Message\UploadedFileInterface;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;

/**
 * @implements RendererInterface<Frame\Http>
 */
final class Http implements RendererInterface
{
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

        $output->writeln(['', \sprintf('<fg=white;bg=%s> HTTP %s </>', $color, $method), '']);

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
            $this->renderKeyValueTable(
                $output,
                'Headers',
                \array_map(static fn(array $lines) => \implode("\n", $lines), $request->getHeaders()),
                ['Cookie'],
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

    private function renderKeyValueTable(OutputInterface $output, string $title, array $data, array $exclude = []): void
    {
        $table = (new Table($output))->setHeaderTitle($title);
        if ($data === []) {
            $table->setRows([['<fg=green> There is no data </>']])->render();
            return;
        }

        $keyLength = \max(\array_map(static fn($key) => \strlen($key), \array_keys($data)));
        $valueLength = \max(1, (new Terminal())->getWidth() - 7 - $keyLength);

        $table->setRows([...(static function (array $data, array $exclude) use ($valueLength): iterable {
                foreach ($data as $key => $value) {
                    if (\in_array($key, $exclude, true)) {
                        continue;
                    }
                    if (!\is_string($value)) {
                        $value = \json_encode($value, JSON_THROW_ON_ERROR);
                    }
                    $values = \strlen($value) > $valueLength
                        ? \str_split($value, $valueLength)
                        : [$value];

                    yield [$key, \array_shift($values)];
                    foreach ($values as $str) {
                        yield ['', $str];
                    }
                }
            })($data, $exclude)])->render();
    }
}
