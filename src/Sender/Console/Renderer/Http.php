<?php

declare(strict_types=1);

namespace Buggregator\Client\Sender\Console\Renderer;

use Buggregator\Client\Proto\Frame;
use Buggregator\Client\ProtoType;
use Buggregator\Client\Sender\Console\RendererInterface;
use Buggregator\Client\Sender\Console\Support\Color;
use Buggregator\Client\Sender\Console\Support\Common;
use Buggregator\Client\Sender\Console\Support\Files;
use Psr\Http\Message\UploadedFileInterface;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\OutputInterface;

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
            'GET' => Color::Blue->value,
            'POST', 'PUT', 'PATCH' => Color::Green->value,
            'DELETE' => Color::Red->value,
            default => Color::Gray->value,
        };

        Common::renderHeader1($output, 'HTTP', ...[$color => $method]);

        Common::renderMetadata($output, [
            'Time' => $date,
            'URI' => $uri,
        ]);

        if ($request->getQueryParams() !== []) {
            Common::renderHeader3($output, 'Query params');
            Common::renderHeaders($output, $request->getQueryParams());
        }

        if ($request->getCookieParams() !== []) {
            Common::renderHeader3($output, 'Cookies');
            Common::renderHeaders($output, $request->getCookieParams());
        }

        if ($request->getHeaders() !== []) {
            Common::renderHeader3($output, 'Headers');
            // Exclude Cookies from Headers rendering
            $headers = $request->withoutHeader('Cookie')->getHeaders();
            Common::renderHeaders($output, $headers);
        }

        $hasParsedBody = $request->getParsedBody() !== null;
        $hasFiles = $request->getUploadedFiles() !== [];

        // Parsed Body block
        if ($hasParsedBody) {
            Common::renderHeader3($output, 'Parsed Body');
            $output->writeln(\print_r($request->getParsedBody(), true), OutputInterface::OUTPUT_NORMAL);
        }

        // Uploaded files block
        if ($hasFiles) {
            Common::renderHeader3($output, 'Uploaded files');
            /** @var UploadedFileInterface[]|UploadedFileInterface[][] $uploadedFiles */
            $uploadedFiles = $request->getUploadedFiles();
            foreach ($uploadedFiles as $name => $fileSet) {
                $fileSet = \is_array($fileSet) ? $fileSet : [$fileSet];
                foreach ($fileSet as $subName => $file) {
                    Files::renderFile(
                        $output,
                        (string)$file->getClientFilename(),
                        $file->getSize(),
                        (string)$file->getClientMediaType(),
                        Field: \sprintf("%s[%s]", $name, $subName)
                    );
                }
            }
        }

        // Decoded Body block
        if (!$hasParsedBody && !$hasFiles && $body->getSize() > 0) {
            $toRead = (int) \min(256, $body->getSize());
            Common::renderHeader3($output, \sprintf(
                'Body (first %d bytes of %d)',
                $toRead,
                (int) $body->getSize()
            ));
            $body->rewind();
            $read = $body->read($toRead);
            // replace all non-printable characters
            $read = \preg_replace('/[\x00-\x09\x0F-\x1F]/', '.', $read);
            $output->write($read, true, OutputInterface::OUTPUT_RAW);
        }
    }
}
