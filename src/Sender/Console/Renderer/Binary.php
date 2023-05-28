<?php

declare(strict_types=1);

namespace Buggregator\Client\Sender\Console\Renderer;

use Buggregator\Client\Proto\Frame;
use Buggregator\Client\ProtoType;
use Buggregator\Client\Sender\Console\RendererInterface;
use Buggregator\Client\Sender\Console\Support\RenderFile;
use Fiber;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @implements RendererInterface<Frame\Binary>
 */
final class Binary implements RendererInterface
{
    use RenderFile;

    public function __construct(
        public readonly int $printBytes = 1024,
    ) { }

    public function isSupport(Frame $frame): bool
    {
        return $frame->type === ProtoType::Binary;
    }

    public function render(OutputInterface $output, Frame $frame): void
    {
        \assert($frame instanceof Frame\Binary);

        $output->writeln([
            '',
            '<fg=white;bg=blue> BINARY </>',
            '',
            \sprintf('<info>Time:</> <fg=gray>%s</>', $frame->time->format('Y-m-d H:i:s.u')),
            \sprintf('<info>Size:</> <fg=gray>%s</>', $this->normalizeSize($frame->getSize())),
            '',
        ]);

        if ($frame->getSize() === 0) {
            return;
        }

        // Render body
        $stream = $frame->stream;
        $stream->rewind();
        Fiber::suspend();

        // Print header if needed
        if ($this->printBytes < $frame->getSize()) {
            $output->writeln(
                \sprintf(
                    '<fg=white;options=bold>First %d bytes of %d </>',
                    $this->printBytes,
                    $frame->getSize(),
                ),
            );
        }
        $read = $stream->read(\min($this->printBytes, $frame->getSize()));

        // Render table
        $output->writeln(' <info>Offset    0  1  2  3  4  5  6  7   8  9 10 11 12 13 14 15</info>');
        $output->writeln('<fg=gray>────────  ────────────────────────────────────────────────  ────────────────</>');

        $hexes = \array_map(
            static fn(string $byte): string => \str_pad($byte, 2, '0', \STR_PAD_LEFT),
            \str_split(\bin2hex($read), 2)
        );
        $lines = \array_chunk($hexes, 16);
        $offset = 0;
        foreach ($lines as $line) {
            $hexes = \str_pad(\implode(' ', $line), 47, ' ');
            $hexes = \substr($hexes, 0, 23) . ' ' . \substr($hexes, 23); // Add space between in the middle
            $output->writeln(\sprintf(
                '<info>%s</info>  %s  %s',
                \str_pad(\dechex($offset), 8, '0', \STR_PAD_LEFT),
                $hexes,
                \preg_replace('/[^[:print:]]/', '.', substr($read, $offset, 16)),
            ));
            $offset += 16;
        }
        $output->writeln('<fg=gray>────────  ────────────────────────────────────────────────  ────────────────</>');
        $output->writeln('');
    }
}
