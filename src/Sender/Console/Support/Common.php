<?php

declare(strict_types=1);

namespace Buggregator\Client\Sender\Console\Support;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * @internal
 * @psalm-internal Buggregator\Client\Sender\Console
 */
final class Common
{
    public static function renderHeader1(OutputInterface $output, string $title, string ...$sub): void
    {
        $parts = ["<fg=white;bg=blue;options=bold> $title </>"];
        foreach ($sub as $color => $value) {
            $parts[] = \sprintf('<fg=white;bg=%s;options=bold> %s </>', \is_string($color) ? $color : 'gray', $value);
        }

        $output->writeln(['', \implode('', $parts), '']);
    }

    public static function renderHeader2(OutputInterface $output, string $title, string ...$sub): void
    {
        $parts = ["<fg=white;options=bold># $title </>"];
        foreach ($sub as $color => $value) {
            $parts[] = \sprintf('<fg=gray> %s </>', $value);
        }

        $output->writeln(['', \implode('', $parts), '']);
    }

    public static function renderHeader3(OutputInterface $output, string $title, string ...$sub): void
    {
        $parts = ["<fg=white;options=bold>## $title </>"];
        foreach ($sub as $color => $value) {
            $parts[] = \sprintf('<fg=gray> %s </>', $value);
        }

        $output->writeln(['', \implode('', $parts)]);
    }

    public static function renderHighlightedLine(OutputInterface $output, string $line): void
    {
        $output->writeln(\sprintf('<fg=black;bg=white;options=bold> %s </>', $line));
    }

    /**
     * @param array<array-key, string|string[]> $data
     */
    public static function renderMetadata(OutputInterface $output, array $data): void
    {
        $maxHeaderLength = \max(\array_map('strlen', \array_keys($data)));

        foreach ($data as $head => $value) {
            // Align headers to the right
            self::renderHeader(
                $output,
                \str_pad((string)$head, $maxHeaderLength, ' ', \STR_PAD_LEFT),
                $value,
                keyColor: Color::White,
                valueColor: Color::Gray,
                secondKeyColor: Color::Gray,
            );
        }
    }

    /**
     * @param array<array-key, string|string[]> $headers
     */
    public static function renderHeaders(OutputInterface $output, array $headers): void
    {
        foreach ($headers as $head => $value) {
            self::renderHeader($output, (string)$head, $value);
        }
    }

    /**
     * @param string|string[]|array $value
     */
    public static function renderHeader(
        OutputInterface $output,
        string $name,
        string|array $value,
        Color $keyColor = Color::Green,
        Color $valueColor = Color::Default,
        Color $secondKeyColor = Color::Gray,
    ): void {
        $i = 0;
        foreach ((array)$value as $item) {
            if ($i++ === 0) {
                $output->write(\sprintf('<fg=%s;options=bold>%s</>: ', $keyColor->value, $name));
            } else {
                $output->write(\sprintf('<fg=%s>%s</>: ', $secondKeyColor->value, $name));
            }

            if (!\is_scalar($item)) {
                $item = \print_r($item, true);
            }
            $output->writeln(\sprintf('<fg=%s>%s</>', $valueColor->value, $item));
        }
    }
}
