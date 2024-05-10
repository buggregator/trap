<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Console\Support;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * @internal
 *
 * @psalm-internal Buggregator\Trap\Sender\Console
 */
final class Files
{
    /**
     * @param non-negative-int|null $size
     *
     * Render file info. Example:
     *  ┌───┐  logo.ico
     *  │ico│  20.06 KiB
     *  └───┘  image/x-icon
     * @param string ...$additional Additional info that will be rendered as a list under the name:
     *
     *  ┌───┐  logo.ico
     *  │   │  name: attached-file      <= named argument
     *  │   │  any value                <= positional argument
     *  │ico│  unknown size
     *  └───┘  image/x-icon
     */
    public static function renderFile(
        OutputInterface $output,
        string $fileName,
        ?int $size,
        string $type,
        string ...$additional
    ): void {
        // File extension
        $dotPos = \strrpos($fileName, '.');
        $ex = false === $dotPos || \strlen($fileName) - $dotPos > 4
            ? '   '
            : \str_pad(\substr($fileName, $dotPos + 1), 3, ' ', \STR_PAD_BOTH);

        // File size
        $sizeStr = self::normalizeSize($size) ?? 'unknown size';

        // Header with top border
        $output->writeln("<bg=black;fg=magenta> ┌───┐</>  <info>$fileName</info>");

        // Additional info
        foreach ($additional as $key => $line) {
            $output->writeln(
                \sprintf('<bg=black;fg=magenta> │   │</>  <fg=gray>%s</>', \is_string($key) ? "$key: $line" : $line),
            );
        }
        // File size
        $output->writeln("<bg=black;fg=magenta> │{$ex}│</>  <fg=gray>$sizeStr</>");

        // MIME type
        $output->writeln("<bg=black;fg=magenta> └───┘</>  <fg=gray>$type</>");
    }

    /**
     * @param int<0, max>|null $size
     */
    public static function normalizeSize(?int $size): ?string
    {
        if (null === $size) {
            return null;
        }

        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'];
        $power = (int) \floor(\log($size, 1024));
        $float = 0 < $power ? \round($size / (1024 ** $power), 2) : $size;

        \assert(0 <= $power && 5 >= $power);

        return \sprintf('%s %s', \number_format($float, 2), $units[$power]);
    }
}
