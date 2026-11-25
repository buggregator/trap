<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Html\Console;

use Buggregator\Trap\Module\Console\Renderer as RendererInterface;
use Buggregator\Trap\Module\Console\Support\Common;
use Buggregator\Trap\Module\Html\Frame as HtmlFrame;
use Buggregator\Trap\Server\Proto\Frame;
use Buggregator\Trap\Support\HtmlText;
use Buggregator\Trap\Support\Measure;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The title, size and project of a page, and the start of its visible text: the page itself is for the web UI.
 *
 * @implements RendererInterface<HtmlFrame>
 *
 * @internal
 */
final class Renderer implements RendererInterface
{
    /** Characters of the visible text shown. */
    public const EXCERPT_LENGTH = 200;

    #[\Override]
    public function isSupport(Frame $frame): bool
    {
        return $frame instanceof HtmlFrame;
    }

    #[\Override]
    public function render(OutputInterface $output, Frame $frame): void
    {
        \assert($frame instanceof HtmlFrame);

        Common::renderHeader1(
            $output,
            'HTML',
            ...($frame->project === null ? [] : ['cyan' => OutputFormatter::escape($frame->project)]),
        );

        $text = HtmlText::visible($frame->html, self::EXCERPT_LENGTH + 1);
        \mb_strlen($text) > self::EXCERPT_LENGTH and $text = \mb_substr($text, 0, self::EXCERPT_LENGTH) . '…';

        Common::renderMetadata($output, [
            'Time' => $frame->time,
            ...\array_map(OutputFormatter::escape(...), \array_filter([
                'Title' => $frame->title,
                'Size' => Measure::memory(\strlen($frame->html)),
                'Text' => $text,
            ], static fn(string $value): bool => $value !== '')),
        ]);
    }
}
