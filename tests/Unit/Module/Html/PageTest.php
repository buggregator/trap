<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Html;

use Buggregator\Trap\Module\Html\Page;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Test;

#[Covers(Page::class)]
#[Test]
final class PageTest
{
    public const PAGE = "<html><head><title> Nightly \n &amp; report </title></head><body><h1>OK</h1></body></html>";

    public function testRawMarkupTitledByPage(): void
    {
        Assert::same(Page::parse('text/html; charset=utf-8', self::PAGE), ['Nightly & report', self::PAGE]);
    }

    #[DataSet(['application/json', '{"title":"Trap page","html":"<a href=\"http://x\">open</a>"}', 'Trap page', '<a href="http://x">open</a>'], 'title')]
    #[DataSet(['Application/JSON; charset=utf-8', '{"html":"<title>From page</title>x"}', 'From page', '<title>From page</title>x'], 'no title')]
    #[DataSet(['application/json', '{"title":"  ","html":"<p>x</p>"}', '', '<p>x</p>'], 'blank title, none in page')]
    #[DataSet(['application/json', '{"title":"Two\nlines","html":"x"}', 'Two lines', 'x'], 'title on one line')]
    public function testJson(string $contentType, string $body, string $title, string $html): void
    {
        Assert::same(Page::parse($contentType, $body), [$title, $html]);
    }

    #[DataSet(['', '<p>hi</p>', '<p>hi</p>'], 'no content type')]
    #[DataSet(['text/html; charset=iso-8859-1', "<p>caf\xe9</p>", '<p>café</p>'], 'charset of content type')]
    #[DataSet(['text/html; charset="KOI8-R"', "<p>\xf0\xd2\xc9\xd7\xc5\xd4</p>", '<p>Привет</p>'], 'quoted charset')]
    #[DataSet(['text/html', "<meta charset=\"windows-1251\"><p>\xcf\xf0\xe8\xe2\xe5\xf2</p>", '<meta charset="windows-1251"><p>Привет</p>'], 'meta charset')]
    #[DataSet(['text/html', "<p>caf\xe9</p>", '<p>café</p>'], 'undeclared and not UTF-8')]
    #[DataSet(['text/html', "\xEF\xBB\xBF<p>x</p>", '<p>x</p>'], 'byte order mark')]
    #[DataSet(['text/html; charset=windows-1252', "\xEF\xBB\xBF<p>caf\xc3\xa9</p>", '<p>café</p>'], 'byte order mark over the content type')]
    #[DataSet(['text/html', "\xFF\xFE<\x00p\x00>\x00h\x00i\x00", '<p>hi'], 'UTF-16LE with a byte order mark')]
    #[DataSet(['text/html', "\xFE\xFF\x00<\x00p\x00>\x00h\x00i", '<p>hi'], 'UTF-16BE with a byte order mark')]
    #[DataSet(['text/html', "\xFF\xFE<\x00p\x00>\x00h\x00i\x00X", '<p>hi'], 'UTF-16 with a stray last byte')]
    public function testCharset(string $contentType, string $body, string $html): void
    {
        Assert::same(Page::parse($contentType, $body)[1], $html);
    }

    public function testTitleCutTo300Characters(): void
    {
        [$title] = Page::parse('text/html', '<title>' . \str_repeat('я', 400) . '</title>x');

        Assert::same($title, \str_repeat('я', Page::MAX_TITLE));
    }

    #[DataSet(['<titles>x</titles>', ''], 'another element')]
    #[DataSet(['<TITLE lang="en">A &lt;b&gt;</Title>', 'A <b>'], 'attributes and case')]
    #[DataSet(['<title>never closed', ''], 'unclosed')]
    #[DataSet(['<title-bar>Menu</title-bar><title>Report</title>', 'Report'], 'custom element named after it')]
    public function testPageTitle(string $markup, string $title): void
    {
        Assert::same(Page::pageTitle($markup), $title);
    }

    /**
     * A mebibyte of title tags that never close: a search that tries again from each one takes tens of seconds.
     */
    #[DataSet(['<title '])]
    #[DataSet(['<TITLE/'])]
    public function testTitleOfMebibyteOfUnclosedTagsInLinearTime(string $repeated): void
    {
        $markup = \str_repeat($repeated, \intdiv(1024 * 1024, \strlen($repeated)));
        $start = \microtime(true);

        Assert::same(Page::pageTitle($markup), '');
        Assert::true(\microtime(true) - $start < 2.0);
    }

    #[DataSet(['text/html', ' '], 'empty markup')]
    #[DataSet(['application/json', '{'], 'bad JSON')]
    #[DataSet(['application/json', '{"title":"x"}'], 'JSON without markup')]
    #[DataSet(['application/json', '{"title":1,"html":"x"}'], 'title not a string')]
    #[DataSet(['application/json', '["x"]'], 'not an object')]
    #[DataSet(['application/json', '{"html":"  "}'], 'blank JSON markup')]
    public function testRejects(string $contentType, string $body): void
    {
        Expect::exception(\InvalidArgumentException::class);

        Page::parse($contentType, $body);
    }
}
