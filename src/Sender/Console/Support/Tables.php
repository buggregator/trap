<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Console\Support;

use Buggregator\Trap\Support\Json;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;

/**
 * @internal
 * @psalm-internal Buggregator\Trap\Sender\Console
 */
final class Tables
{
    public static function renderKeyValueTable(OutputInterface $output, string $title, array $data): void
    {
        $table = (new Table($output))->setHeaderTitle($title);
        if ($data === []) {
            $table->setRows([['<fg=green> There is no data </>']])->render();
            return;
        }

        $keyLength = \max(\array_map(static fn($key) => \strlen($key), \array_keys($data)));
        $valueLength = \max(1, (new Terminal())->getWidth() - 7 - $keyLength);

        $table->setRows([...(static function (array $data) use ($valueLength): iterable {
            foreach ($data as $key => $value) {
                if (!\is_string($value)) {
                    $value = Json::encode($value);
                }
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
}
