<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Server\Traffic\Message\Multipart;

use Buggregator\Trap\Server\Traffic\Message\Multipart\Field;
use Buggregator\Trap\Server\Traffic\Message\Multipart\File;
use Buggregator\Trap\Server\Traffic\Message\Multipart\Part;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(Part::class)]
final class PartTest
{
    public static function provideParts(): iterable
    {
        yield 'form field' => [['Content-Disposition' => ['form-data; name="title"']], Field::class, null];
        yield 'inline text' => [['Content-Type' => ['text/plain'], 'Content-Disposition' => ['inline']], Field::class, null];
        yield 'text type in upper case' => [['Content-Type' => ['TEXT/PLAIN; charset=US-ASCII']], Field::class, null];
        yield 'text type in mixed case' => [['Content-Type' => [' Text/Html']], Field::class, null];
        yield 'text/ inside another type' => [['Content-Type' => ['application/x-text/plain']], File::class, null];
        yield 'text attachment without a file name' => [
            ['Content-Type' => ['text/plain'], 'Content-Disposition' => ['attachment']],
            File::class,
            null,
        ];
        yield 'capitalized attachment disposition' => [
            ['Content-Type' => ['text/plain'], 'Content-Disposition' => ['Attachment']],
            File::class,
            null,
        ];
        yield 'quoted file name' => [
            ['Content-Disposition' => ['attachment; filename="report 1.txt"']],
            File::class,
            'report 1.txt',
        ];
        yield 'percent-encoded UTF-8 file name' => [
            ['Content-Type' => ['text/plain'], 'Content-Disposition' => ["attachment; filename*=UTF-8''%D0%BE%D1%82%D1%87%D1%91%D1%82.txt"]],
            File::class,
            'отчёт.txt',
        ];
        yield 'extended file name wins over the plain one' => [
            ['Content-Disposition' => ["attachment; filename=\"report.txt\"; filename*=utf-8'en'%E2%82%AC.txt"]],
            File::class,
            '€.txt',
        ];
        yield 'file name split into encoded and plain pieces' => [
            ['Content-Disposition' => ["attachment;\tfilename*1=\"-long.txt\"; filename*0*=UTF-8''%D1%84"]],
            File::class,
            'ф-long.txt',
        ];
        yield 'ISO-8859-1 file name' => [
            ['Content-Disposition' => ["attachment; filename*=ISO-8859-1''caf%E9.txt"]],
            File::class,
            'café.txt',
        ];
        yield 'unknown charset keeps the bytes' => [
            ['Content-Disposition' => ["attachment; filename*=x-unknown''a%20b.txt"]],
            File::class,
            'a b.txt',
        ];
    }

    #[DataProvider('provideParts')]
    public function createsPartByHeaders(array $headers, string $class, ?string $fileName): void
    {
        $part = Part::create($headers);

        Assert::instanceOf($part, $class);
        $part instanceof File and Assert::same($part->getClientFilename(), $fileName);
    }
}
