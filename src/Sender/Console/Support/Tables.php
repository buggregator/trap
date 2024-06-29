<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Console\Support;

use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;

/**
 * @internal
 * @psalm-internal Buggregator\Trap\Sender\Console
 */
final class Tables
{
    /**
     * @param array<string, string> $data
     */
    public static function renderKeyValueTable(OutputInterface $output, string $title, array $data): void
    {
        $table = (new Table($output))->setHeaderTitle($title);
        if ($data === []) {
            $table->setRows([['<fg=green> There is no data </>']])->render();
            return;
        }

        $keyLength = \max(\array_map(static fn($key) => \strlen((string) $key), \array_keys($data)));
        $valueLength = \max(1, (new Terminal())->getWidth() - 7 - $keyLength);

        $table->setRows([...(static function (array $data) use ($valueLength): iterable {
            /** @var array<string, string> $data */
            foreach ($data as $key => $value) {
                $values = \strlen($value) > $valueLength
                    ? \str_split($value, $valueLength)
                    : [$value];

                yield [$key, \array_shift($values)];
                foreach ($values as $str) {
                    yield ['', $str];
                }
            }
        })($data)])->render();
    }

    /**
     * @param array<array-key, array<array-key, scalar|null>> $data
     * @param 'default'|'borderless'|'compact'|'symfony-style-guide'|'box'|'box-double' $style
     */
    public static function renderMultiColumnTable(
        OutputInterface $output,
        string $title,
        array $data,
        string $style = 'default',
    ): void {
        $table = (new Table($output))->setHeaderTitle($title);
        if ($data === []) {
            $table->setRows([['<fg=green> There is no data </>']])->render();
            return;
        }

        $headers = \array_keys($data[0]);
        $table->setHeaders($headers)
            ->setStyle($style)
            ->setRows($data)
            ->render();
    }
}
