<?php

declare(strict_types=1);

namespace Buggregator\Client\Sender\Console\Support;

use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;

trait RenderTable
{
    private function renderKeyValueTable(OutputInterface $output, string $title, array $data): void
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
                    $value = \json_encode($value, JSON_THROW_ON_ERROR);
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
