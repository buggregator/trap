<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Support\Spx;

use Buggregator\Trap\Support\Spx\Archive;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Core\Exception\SkipTest;
use Testo\Expect;
use Testo\Test;

#[Covers(Archive::class)]
#[Test]
final class ArchiveTest
{
    public function testReadsBackWhatItPacks(): void
    {
        $files = self::unpack(Archive::pack(['a.json' => '{}', 'a.txt.gz' => \str_repeat('x', 600)]));

        Assert::same($files, ['a.json' => '{}', 'a.txt.gz' => \str_repeat('x', 600)]);
    }

    public function testKeepsLongNamesWhole(): void
    {
        // A long host name makes the report key long; SPX finds the trace by the key in the metadata
        $key = 'spx-full-20250518_120000-' . \str_repeat('node.', 40) . 'cluster.local-42-1804289383';

        $files = self::unpack(Archive::pack(["$key.json" => '{}', "$key.txt.gz" => 'trace']));

        Assert::same(\array_keys($files), ["$key.json", "$key.txt.gz"]);
    }

    public function testIsReadableByPhar(): void
    {
        $path = \sys_get_temp_dir() . '/' . \uniqid('trap-spx-', true) . '.tar';
        \file_put_contents($path, Archive::pack(['spx-full-1.json' => '{"key": 1}', 'spx-full-1.txt.gz' => 'trace']));

        try {
            $files = [];
            foreach (new \PharData($path) as $file) {
                $files[$file->getFilename()] = \file_get_contents($file->getPathname());
            }
        } finally {
            \unlink($path);
        }

        Assert::same($files, ['spx-full-1.json' => '{"key": 1}', 'spx-full-1.txt.gz' => 'trace']);
    }

    public function testLongNamesAreExtractedWholeByTar(): void
    {
        $key = 'spx-full-1-' . \str_repeat('h', 120);
        $dir = \sys_get_temp_dir() . '/' . \uniqid('trap-spx-', true);
        \mkdir($dir);
        \file_put_contents("$dir/report.tar", Archive::pack(["$key.json" => '{"key": 1}', "$key.txt.gz" => 'trace']));

        try {
            \exec(\sprintf('tar -xf %s -C %s 2>&1', \escapeshellarg("$dir/report.tar"), \escapeshellarg($dir)), $output, $code);
            $code === 0 or throw new SkipTest('No tar to extract with: ' . \implode(' ', $output));
            $files = \array_map(\basename(...), (array) \glob("$dir/spx-full-*"));
            \sort($files);
        } finally {
            \array_map(\unlink(...), (array) \glob("$dir/*"));
            \rmdir($dir);
        }

        Assert::same($files, ["$key.json", "$key.txt.gz"]);
    }

    public function testReadsNameWithUstarPrefix(): void
    {
        $archive = Archive::pack(['report.json' => '{}']);
        $header = \substr_replace(\substr($archive, 0, 512), \str_pad('spx', 155, "\0"), 345, 155);
        $header = \substr_replace($header, '        ', 148, 8);
        $sum = \array_sum(\array_map(\ord(...), \str_split($header)));
        $header = \substr_replace($header, \sprintf('%06o', $sum) . "\0 ", 148, 8);

        Assert::same(\array_keys(self::unpack($header . \substr($archive, 512))), ['spx/report.json']);
    }

    public function testRejectsTooLongName(): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage('The file name is longer than 4096 bytes.');

        Archive::pack([\str_repeat('x', 4097) => '']);
    }

    public function testRejectsCorruptedHeader(): never
    {
        $archive = Archive::pack(['a.json' => '{}']);
        $archive[0] = 'b';

        Expect::exception(\InvalidArgumentException::class)->withMessage('Invalid tar header checksum.');

        self::unpack($archive);
    }

    public function testRejectsTruncatedHeader(): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage('The archive is truncated.');

        self::unpack('ustar');
    }

    /**
     * @return array<string, string>
     */
    private static function unpack(string $archive): array
    {
        $offset = 0;
        $read = static function (int $length) use ($archive, &$offset): string {
            $data = (string) \substr($archive, $offset, $length);
            $offset += $length;
            return $data;
        };

        $files = [];
        while (($entry = Archive::next($read)) !== null) {
            [$name, $size] = $entry;
            $files[$name] = $read($size);
            $read(Archive::paddingOf($size));
        }

        return $files;
    }
}
