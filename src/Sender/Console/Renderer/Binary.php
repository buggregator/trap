<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Console\Renderer;

use Buggregator\Trap\Proto\Frame;
use Buggregator\Trap\ProtoType;
use Buggregator\Trap\Sender\Console\Renderer;
use Buggregator\Trap\Sender\Console\Support\Common;
use Buggregator\Trap\Support\Measure;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @implements Renderer<Frame\Binary>
 *
 * @internal
 */
final class Binary implements Renderer
{
    private const BYTE_REPLACES = [
        '[ ]' => ' ',
        '[\\x00]' => '<fg=red>0</>',
        // '[\\x01]' => '<fg=red>1</>',
        // '[\\x02]' => '<fg=red>2</>',
        // '[\\x03]' => '<fg=red>3</>',
        // '[\\x04]' => '<fg=red>4</>',
        // '[\\x05]' => '<fg=red>5</>',
        // '[\\x06]' => '<fg=red>6</>',
        '[\\x07]' => '<fg=green>a</>',
        '[\\x08]' => '<fg=green>b</>',
        '[\\x09]' => '<fg=green>t</>',
        '[\\x0a]' => '<fg=green>n</>',
        '[\\x0b]' => '<fg=green>v</>',
        '[\\x0c]' => '<fg=green>f</>',
        '[\\x0d]' => '<fg=green>r</>',
        // '[\\x0e]' => '<fg=red>e</>',
        // '[\\x0f]' => '<fg=red>f</>',
        // '[\\x10]' => '<fg=yellow>0</>',
        // '[\\x11]' => '<fg=yellow>1</>',
        // '[\\x12]' => '<fg=yellow>2</>',
        // '[\\x13]' => '<fg=yellow>3</>',
        // '[\\x14]' => '<fg=yellow>4</>',
        // '[\\x15]' => '<fg=yellow>5</>',
        // '[\\x16]' => '<fg=yellow>6</>',
        // '[\\x17]' => '<fg=yellow>7</>',
        // '[\\x18]' => '<fg=yellow>8</>',
        // '[\\x19]' => '<fg=yellow>9</>',
        // '[\\x1a]' => '<fg=yellow>a</>',
        '[\\x1b]' => '<fg=green>e</>',
        // '[\\x7f]' => '<fg=green>d</>',
        '/[^[:print:]]/' => '<fg=gray>.</>',
    ];

    public function __construct(
        public readonly int $printBytes = 512,
    ) {}

    public function isSupport(Frame $frame): bool
    {
        return $frame->type === ProtoType::Binary;
    }

    public function render(OutputInterface $output, Frame $frame): void
    {
        \assert($frame instanceof Frame\Binary);

        Common::renderHeader1($output, 'BINARY');

        $size = $frame->getSize();
        Common::renderMetadata($output, [
            'Time' => $frame->time,
            'Size' => Measure::memory($size) . ($size > 1024 ? \sprintf(' (%d bytes)', $size) : ''),
        ]);

        if ($size === 0) {
            return;
        }

        // Render body
        $stream = $frame->stream;
        $stream->rewind();
        \Fiber::suspend();

        // Print header if needed
        if ($this->printBytes < $size) {
            Common::renderHeader3($output, \sprintf('First %d bytes of %d', $this->printBytes, $size));
        } else {
            // Just empty line
            $output->writeln('');
        }
        $read = $stream->read(\min($this->printBytes, $size));

        // Render table
        $output->writeln(' <info>Offset    0  1  2  3  4  5  6  7   8  9 10 11 12 13 14 15</info>');
        $output->writeln('<fg=gray>────────  ────────────────────────────────────────────────  ────────────────</>');

        $hexes = \array_map(
            static fn(string $byte): string => \str_pad($byte, 2, '0', \STR_PAD_LEFT),
            \str_split(\bin2hex($read), 2),
        );
        $lines = \array_chunk($hexes, 16);
        $offset = 0;
        $s = '<fg=yellow>';
        foreach ($lines as $line) {
            $hexes = \str_pad(\implode(' ', $line), 47, ' ');
            $hexes = \substr($hexes, 0, 23) . ' ' . \substr($hexes, 23); // Add space between in the middle
            $output->writeln(\sprintf(
                '<info>%s</info>  %s  %s',
                \str_pad(\dechex($offset), 8, '0', \STR_PAD_LEFT),
                $hexes,
                \preg_replace(
                    \array_keys(self::BYTE_REPLACES),
                    \array_values(self::BYTE_REPLACES),
                    \substr($read, $offset, 16),
                ),
            ));
            $offset += 16;
        }
        $output->writeln('<fg=gray>────────  ────────────────────────────────────────────────  ────────────────</>');
        $output->writeln('');
    }
}
