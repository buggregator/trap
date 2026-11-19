<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Console\Support;

use Buggregator\Trap\Support\Json;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @internal
 * @psalm-internal Buggregator\Trap
 */
final class Common
{
    private const NESTED_JSON_WIDTH = 80;

    public static function renderHeader1(OutputInterface $output, string $title, ?string ...$sub): void
    {
        $parts = ["<fg=white;bg=blue;options=bold> $title </>"];
        foreach ($sub as $color => $value) {
            if ($value === null) {
                continue;
            }
            $parts[] = \sprintf('<fg=white;bg=%s;options=bold> %s </>', \is_string($color) ? $color : 'gray', $value);
        }

        $output->writeln(['', \implode('', $parts), '']);
    }

    public static function renderHeader2(OutputInterface $output, string $title, string ...$sub): void
    {
        $parts = ["<fg=white;options=bold># $title </>"];
        foreach ($sub as $color => $value) {
            if ($value === '') {
                continue;
            }

            $color = \is_string($color) ? $color : 'gray';
            $parts[] = \sprintf('<fg=%s> %s </>', $color, $value);
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
     * @param array<array-key, mixed> $data
     */
    public static function renderMetadata(OutputInterface $output, array $data): void
    {
        $maxHeaderLength = \max(
            0,
            ...\array_map(
                static fn(string|int $key): int => \strlen((string) $key),
                \array_keys($data),
            ),
        );

        /** @var mixed $value */
        foreach ($data as $head => $value) {
            // Align headers to the right
            self::renderHeader(
                $output,
                \str_pad((string) $head, $maxHeaderLength, ' ', \STR_PAD_LEFT),
                $value,
                keyColor: Color::White,
                valueColor: Color::Gray,
                secondKeyColor: Color::Gray,
            );
        }
    }

    /**
     * @param array<int|string, string> $tags
     */
    public static function renderTags(OutputInterface $output, array $tags): void
    {
        if ($tags === []) {
            return;
        }

        $lines = [];
        $parts = [];
        $lineLen = 0;
        foreach ($tags as $name => $value) {
            if (\is_string($name)) {
                $currentLen = \strlen($name) + \strlen($value) + 5; // 4 paddings and 1 margin
                $tag = \sprintf('<fg=white;bg=gray> %s:</><fg=white;bg=green;options=bold> %s </>', $name, $value, );
            } else {
                $currentLen = \strlen($value) + 3; // 2 paddings and 1 margin
                $tag = \sprintf('<fg=white;bg=green;options=bold> %s </>', $value);
            }
            if ($lineLen === 0 || $lineLen + $currentLen < 80) {
                $parts[] = $tag;
                $lineLen += $currentLen;
            } else {
                $lines[] = \implode(' ', $parts);
                $parts = [$tag];
                $lineLen = $currentLen;
            }
        }
        $lines[] = \implode(' ', $parts);

        $output->writeln($lines);
    }

    public static function hr(OutputInterface $output, string $color = 'gray', int $padding = 0): void
    {
        $output->writeln(\sprintf(
            '%s<fg=%s>%s</>',
            \str_repeat(' ', $padding),
            $color,
            \str_repeat('─', 80 - $padding),
        ));
    }

    /**
     * @param array<array-key, mixed> $headers
     */
    public static function renderHeaders(OutputInterface $output, array $headers): void
    {
        /** @var mixed $value */
        foreach ($headers as $head => $value) {
            self::renderHeader($output, (string) $head, $value);
        }
    }

    public static function renderHeader(
        OutputInterface $output,
        string $name,
        mixed $value,
        Color $keyColor = Color::Green,
        Color $valueColor = Color::Default,
        Color $secondKeyColor = Color::Gray,
    ): void {
        $items = \is_array($value) ? $value : [$value];

        // A map, such as a log context, shows its keys under the name
        if (!\array_is_list($items)) {
            $output->writeln(\sprintf('<fg=%s;options=bold>%s</>:', $keyColor->value, $name));
            $indent = \str_repeat(' ', Helper::width($name) + 2);
            /** @var mixed $item */
            foreach ($items as $key => $item) {
                $key = (string) $key;
                $text = match (true) {
                    \is_bool($item), $item === null => Json::encode($item),
                    \is_array($item) => self::formatNested($item),
                    default => self::formatValue($item),
                };
                $output->writeln(\sprintf(
                    '%s<fg=%s>%s</>: <fg=%s>%s</>',
                    $indent,
                    $secondKeyColor->value,
                    OutputFormatter::escape($key),
                    $valueColor->value,
                    self::escapeLines($text, $indent . \str_repeat(' ', Helper::width($key) + 2)),
                ));
            }

            return;
        }

        // A list, such as a repeated HTTP header, repeats the name for every value
        $i = 0;
        /** @var mixed $item */
        foreach ($items as $item) {
            if ($i++ === 0) {
                $output->write(\sprintf('<fg=%s;options=bold>%s</>: ', $keyColor->value, $name));
            } else {
                $output->write(\sprintf('<fg=%s>%s</>: ', $secondKeyColor->value, $name));
            }

            // A scalar value may carry the caller's markup, the items of an array are user data
            $text = self::formatValue($item);
            $output->writeln(\sprintf(
                '<fg=%s>%s</>',
                $valueColor->value,
                \is_array($value) ? OutputFormatter::escape($text) : $text,
            ));
        }
    }

    /**
     * Escapes the markup in a value and indents its continuation lines by `$indent`.
     */
    private static function escapeLines(string $text, string $indent): string
    {
        return \implode("\n" . $indent, \array_map(
            OutputFormatter::escape(...),
            \preg_split('/\R/', $text) ?: [$text],
        ));
    }

    /**
     * Keeps a short array on one line; a long one, such as an exception with its trace, reads better spread out.
     *
     * @param array<array-key, mixed> $value
     */
    private static function formatNested(array $value): string
    {
        $json = Json::encode($value, \JSON_PARTIAL_OUTPUT_ON_ERROR);

        return Helper::width($json) > self::NESTED_JSON_WIDTH
            ? Json::encode($value, \JSON_PARTIAL_OUTPUT_ON_ERROR | \JSON_PRETTY_PRINT)
            : $json;
    }

    private static function formatValue(mixed $value): string
    {
        return match (true) {
            $value instanceof \DateTimeInterface => $value->format('u') === '000000'
                ? $value->format('Y-m-d H:i:s')
                : $value->format('Y-m-d H:i:s.u'),
            \is_scalar($value) || $value instanceof \Stringable => (string) $value,
            \is_array($value) => Json::encode($value, \JSON_PARTIAL_OUTPUT_ON_ERROR),
            default => \print_r($value, true),
        };
    }
}
