<?php

declare(strict_types=1);

namespace Buggregator\Client\Sender\Console\Renderer;

use Buggregator\Client\Proto\Frame;
use Buggregator\Client\ProtoType;
use Buggregator\Client\Sender\Console\RendererInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class SentryStore implements RendererInterface
{
    public function __construct(
        private readonly TemplateRenderer $renderer,
    ) {
    }

    public function isSupport(Frame $frame): bool
    {
        if ($frame->type !== ProtoType::HTTP) {
            return false;
        }

        \assert($frame instanceof Frame\Http);

        $request = $frame->request;
        $url = \rtrim($request->getUri()->getPath(), '/');

        return ($request->getHeaderLine('X-Buggregator-Event') === 'sentry'
                || $request->getAttribute('event-type') === 'sentry'
                || $request->hasHeader('X-Sentry-Auth')
                || $request->getUri()->getUserInfo() === 'sentry')
            && \str_ends_with($url, '/store');
    }

    /**
     * @param Frame\Http $frame
     * @throws \JsonException
     */
    public function render(OutputInterface $output, Frame $frame): void
    {
        /**
         * @var array{
         *     event_id: non-empty-string,
         *     timestamp: positive-int,
         *     platform: non-empty-string,
         *     sdk: array{
         *      name: non-empty-string,
         *      version: non-empty-string,
         *     },
         *     logger: non-empty-string,
         *     server_name: non-empty-string,
         *     transaction: non-empty-string,
         *     modules: array<non-empty-string, non-empty-string>,
         *     exception: array<array-key, array{
         *      type: non-empty-string,
         *      value: non-empty-string,
         *      stacktrace: array{
         *          frames: array<array-key, array{
         *           filename: non-empty-string,
         *           lineno: positive-int,
         *           abs_path: non-empty-string,
         *           context_line: non-empty-string,
         *          }
         *      }
         *     }>
         * } $payload
         */
        $payload = \json_decode((string)$frame->request->getBody(), true, 512, \JSON_THROW_ON_ERROR);

        foreach ($payload['exception']['values'] as $exception) {
            $this->renderException($exception);
        }
    }

    private function renderException(array $exception): void
    {
        $frames = \array_reverse($exception['stacktrace']['frames']);
        $editorFrame = \reset($frames);

        $this->renderer->render(
            'sentry-store',
            [
                'date' => date('r'),
                'type' => $exception['type'],
                'message' => $exception['value'],
                'trace' => \iterator_to_array($this->prepareTrace($frames)),
                'codeSnippet' => $this->renderCodeSnippet($editorFrame),
            ]
        );
    }

    /**
     * Renders the trace of the exception.
     */
    protected function prepareTrace(array $frames): \Generator
    {
        foreach ($frames as $i => $frame) {
            $file = $frame['filename'];
            $line = $frame['lineno'];
            $class = empty($frame['class']) ? '' : $frame['class'] . '::';
            $function = $frame['function'] ?? '';
            $pos = \str_pad((string)((int)$i + 1), 4, ' ');

            yield $pos => [
                'file' => $file,
                'line' => $line,
                'class' => $class,
                'function' => $function,
            ];

            if ($i >= 10) {
                yield $pos => '+ more ' . \count($frames) - 10 . ' frames';

                break;
            }
        }
    }

    /**
     * Renders the editor containing the code that was the
     * origin of the exception.
     */
    protected function renderCodeSnippet(array $frame): array
    {
        $line = (int)$frame['lineno'];
        $startLine = 0;
        $content = '';
        if (isset($frame['pre_context'])) {
            $startLine = $line - \count($frame['pre_context']) + 1;
            foreach ($frame['pre_context'] as $row) {
                $content .= $row . "\n";
            }
        }

        if (isset($frame['context_line'])) {
            $content .= $frame['context_line'] . "\n";
        }

        if (isset($frame['post_context'])) {
            foreach ($frame['post_context'] as $row) {
                $content .= $row . "\n";
            }
        }

        return [
            'file' => $frame['filename'],
            'line' => $line,
            'start_line' => $startLine,
            'content' => $content,
        ];
    }
}
