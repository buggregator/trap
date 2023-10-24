<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Console\Renderer;

use Buggregator\Trap\Proto\Frame;
use Buggregator\Trap\ProtoType;
use Buggregator\Trap\Sender\Console\RendererInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @internal
 */
final class SentryStore implements RendererInterface
{
    public function __construct(
        private readonly TemplateRenderer $renderer,
    ) {
    }

    public function isSupport(Frame $frame): bool
    {
        return $frame->type === ProtoType::Sentry && $frame instanceof Frame\SentryStore;
    }

    /**
     * @param Frame\SentryStore $frame
     * @throws \JsonException
     */
    public function render(OutputInterface $output, Frame $frame): void
    {
        $exception = $frame->message;

        $frames = \array_reverse($exception['stacktrace']['frames']);
        $editorFrame = \reset($frames);

        $this->renderer->render(
            'sentry-store',
            [
                'date' => $frame->time->format('Y-m-d H:i:s'),
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
