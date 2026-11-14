<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Support\Spx;

use Buggregator\Trap\Support\Spx\Archive;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Core\Exception\SkipTest;
use Testo\Data\DataSet;
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

    public function testStreamTellsTheSizeOfTheArchive(): void
    {
        $long = \str_repeat('n', 150);
        [$size, $chunks] = Archive::stream([
            'a.json' => [2, ['{}']],
            "$long.txt.gz" => [600, [\str_repeat('x', 400), \str_repeat('y', 200)]],
            'empty' => [0, []],
        ]);

        $archive = \implode('', \iterator_to_array($chunks, false));

        Assert::same(\strlen($archive), $size);
        Assert::same($size, 512 * 10);
        Assert::same(\substr($archive, -1024), \str_repeat("\0", 1024));
        Assert::same(self::unpack($archive), [
            'a.json' => '{}',
            "$long.txt.gz" => \str_repeat('x', 400) . \str_repeat('y', 200),
            'empty' => '',
        ]);
    }

    public function testStreamRejectsContentOfAnotherSize(): never
    {
        [, $chunks] = Archive::stream(['a.json' => [4, ['ab', 'c']]]);

        Expect::exception(\RuntimeException::class)->withMessage('A file of the archive has 3 bytes, not the 4 told.');

        \iterator_to_array($chunks, false);
    }

    public function testWritesUstarHeaderFields(): void
    {
        $before = \time();
        $header = \substr(Archive::pack(['a.json' => '{"k": 1}']), 0, 512);

        Assert::same(\substr($header, 0, 100), \str_pad('a.json', 100, "\0"));
        Assert::same(\substr($header, 100, 24), "0000644\0" . "0000000\0" . "0000000\0");
        Assert::same(\substr($header, 124, 12), "00000000010\0");
        Assert::int((int) \octdec(\substr($header, 136, 11)))->greaterThanOrEqual($before)->lessThanOrEqual(\time());
        Assert::same($header[147], "\0");
        Assert::same($header[156], '0');
        Assert::same(\substr($header, 257, 8), "ustar\x0000");
    }

    public function testWritesNameOfHundredBytesToUstarHeader(): void
    {
        $name = \str_repeat('n', 95) . '.json';

        $archive = Archive::pack([$name => '{}']);

        Assert::same(\strlen($archive), 512 * 4);
        Assert::same(\substr($archive, 0, 100), $name);
    }

    public function testWritesLongNameToPaxHeader(): void
    {
        $name = \str_repeat('a', 60) . \str_repeat('b', 60) . '.json';

        $archive = Archive::pack([$name => '{}']);

        Assert::same(\rtrim(\substr($archive, 0, 100), "\0"), 'PaxHeader/' . \md5($name));
        Assert::same($archive[156], 'x');
        Assert::same(\substr($archive, 512, 135), "135 path=$name\n");
        Assert::same(\rtrim(\substr($archive, 1024, 100), "\0"), \substr($name, 0, 100));
        Assert::same($archive[1024 + 156], '0');
    }

    #[DataSet([101], 'shortest pax name')]
    #[DataSet([990], 'pax length gains a digit')]
    #[DataSet([4096], 'longest name')]
    public function testReadsBackNameOfLength(int $length): void
    {
        $name = \str_repeat('n', $length - 5) . '.json';

        $files = self::unpack(Archive::pack([$name => '{}']));

        Assert::same($files, [$name => '{}']);
    }

    public function testReadsBackEmptyFiles(): void
    {
        $archive = Archive::pack(['a' => '', 'b' => 'x']);

        Assert::same(\strlen($archive), 512 * 5);
        Assert::same(self::unpack($archive), ['a' => '', 'b' => 'x']);
    }

    public function testReadsPaxRecordsBesidesPath(): void
    {
        $archive = self::pax("30 mtime=1700000000.123456789\n" . self::record('path', 'dir/a.json') . "11 comment\n" . "8 path=\n")
            . self::pax(self::record('path', 'dir/report.json') . "20 mtime=1700000000\n")
            . self::header('short', 2) . \str_pad('{}', 512, "\0") . \str_repeat("\0", 1024);

        Assert::same(self::unpack($archive), ['dir/report.json' => '{}']);
    }

    public function testReadsPathAfterAnotherPaxRecord(): void
    {
        $archive = self::pax("20 mtime=1700000000\n" . self::record('path', 'dir/a.json'))
            . self::header('short', 2) . \str_pad('{}', 512, "\0") . \str_repeat("\0", 1024);

        Assert::same(self::unpack($archive), ['dir/a.json' => '{}']);
    }

    public function testReadsPaxRecordsUnderThrowingErrorHandler(): void
    {
        $archive = self::pax(self::record('path', 'a=b=c.json') . "11 comment\n")
            . self::header('short', 2) . \str_pad('{}', 512, "\0") . \str_repeat("\0", 1024);

        \set_error_handler(static fn(int $code, string $message): never => throw new \ErrorException($message, 0, $code));
        try {
            $files = self::unpack($archive);
        } finally {
            \restore_error_handler();
        }

        Assert::same($files, ['a=b=c.json' => '{}']);
    }

    public function testReadsPaxHeaderOfTheLargestSize(): void
    {
        $name = \str_repeat('n', 8192 - 11);
        $record = self::record('path', $name);

        $files = self::unpack(self::pax($record) . self::header('short', 0) . \str_repeat("\0", 1024));

        Assert::same(\strlen($record), 8192);
        Assert::same(\array_keys($files), [$name]);
    }

    public function testRejectsTooLargePaxHeader(): never
    {
        $archive = self::pax(self::record('path', \str_repeat('n', 8192 - 10))) . self::header('short', 0);

        Expect::exception(\InvalidArgumentException::class)->withMessage('The pax header is too large.');

        self::unpack($archive);
    }

    #[DataSet(['path=a.json'], 'no length')]
    #[DataSet(["0 path=a.json\n"], 'zero length')]
    #[DataSet(["99 path=a.json\n"], 'length past the end')]
    #[DataSet(["xx path=a.json\n"], 'length not a number')]
    public function testRejectsInvalidPaxRecord(string $records): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage('Invalid pax header.');

        self::unpack(self::pax($records) . self::header('short', 0));
    }

    public function testReadsHeaderFieldsWithoutTerminators(): void
    {
        $header = self::header('a.json', 2, [
            108 => '00000000',
            116 => '00000007',
            124 => ' 00000000002',
            136 => '000000000007',
            345 => \str_repeat('p', 155),
            500 => 'garbage',
        ], '%08o');

        $files = self::unpack($header . \str_pad('{}', 512, "\0") . \str_repeat("\0", 1024));

        Assert::same($files, [\str_repeat('p', 155) . '/a.json' => '{}']);
    }

    #[DataSet(['', [], 'The archive has an entry with no name.'], 'no name')]
    #[DataSet(['a.json', [124 => '00000000x02'], 'Invalid tar entry size.'], 'size not octal')]
    #[DataSet(['a.json', [124 => '0000000 0 2'], 'Invalid tar entry size.'], 'size with spaces')]
    #[DataSet(['a.json', [257 => 'pax00'], 'Not a tar archive.'], 'no ustar magic')]
    #[DataSet(['a.json', [156 => '5'], 'The archive has an entry that is not a regular file.'], 'directory')]
    public function testRejectsInvalidHeader(string $name, array $fields, string $message): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage($message);

        self::unpack(self::header($name, 2, $fields) . \str_pad('{}', 512, "\0") . \str_repeat("\0", 1024));
    }

    /**
     * A ustar header of a regular file, its checksum counted after the fields are overwritten.
     *
     * @param array<int, string> $fields Bytes by offsets.
     */
    private static function header(string $name, int $size, array $fields = [], string $checksum = "%06o\0 "): string
    {
        $header = \str_pad($name, 100, "\0") . "0000644\0" . "0000000\0" . "0000000\0"
            . \sprintf('%011o', $size) . "\0" . "00000000000\0" . '        ' . '0'
            . \str_repeat("\0", 100) . "ustar\x0000";
        $header = \str_pad($header, 512, "\0");
        foreach ($fields as $offset => $bytes) {
            $header = \substr_replace($header, $bytes, $offset, \strlen($bytes));
        }
        $sum = \array_sum(\array_map(\ord(...), \str_split($header)));

        return \substr_replace($header, \sprintf($checksum, $sum), 148, 8);
    }

    private static function pax(string $records): string
    {
        return self::header('PaxHeader/a', \strlen($records), [156 => 'x'])
            . $records . \str_repeat("\0", (512 - \strlen($records) % 512) % 512);
    }

    /**
     * A pax record whose length counts its own digits.
     */
    private static function record(string $key, string $value): string
    {
        $length = \strlen(" $key=$value\n");
        $digits = \strlen((string) $length);
        \strlen((string) ($length + $digits)) > $digits and ++$digits;

        return ($length + $digits) . " $key=$value\n";
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
