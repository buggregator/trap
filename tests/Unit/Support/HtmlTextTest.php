<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Support;

use Buggregator\Trap\Support\HtmlText;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

#[Covers(HtmlText::class)]
#[Test]
final class HtmlTextTest
{
    #[DataSet(['<style>p{}</style><script>alert(1)</script><p>Hello &amp; <b>bye</b></p>', 'Hello & bye'], 'hidden blocks')]
    #[DataSet(["<html><head><title>T</title><meta charset=utf-8></head>\n<body><h1>Report</h1></body></html>", 'Report'], 'head')]
    #[DataSet(['<head><title>T</title><body>Shown', 'Shown'], 'head ended by the body')]
    #[DataSet(['<head><meta charset=utf-8>Shown', 'Shown'], 'head never ended')]
    #[DataSet(['<script>var s = "<!--";</script><h1>Report</h1>', 'Report'], 'comment opening in a script')]
    #[DataSet(['<style>/* <!-- */ p{}</style><p>Visible</p>', 'Visible'], 'comment opening in a style')]
    #[DataSet(['<textarea><!-- x <b></textarea><p>Visible</p>', '<!-- x <b> Visible'], 'textarea text as it is')]
    #[DataSet(['a<!-->b<!--->c', 'a b c'], 'empty comments')]
    #[DataSet(['<iframe src=x>fallback</iframe><noembed>old</noembed>Main', 'Main'], 'content browsers never render')]
    #[DataSet(['<p title="a>b" data-x=\'{"n":1}\'>text</p>', 'text'], 'quoted > in attributes')]
    #[DataSet(['<a href=x\'y>Hello</a> it\'s fine', 'Hello it\'s fine'], 'quote inside an unquoted value')]
    #[DataSet(['<div class=a"b>Hello</div> say "hi"', 'Hello say "hi"'], 'double quote inside an unquoted value')]
    #[DataSet(['<script>a</script foo>shown<textarea>x</textarea y>after', 'shown x after'], 'end tags with attributes')]
    #[DataSet(['<title-bar>Nightly</title-bar><header-nav>Menu</header-nav><p>Tests passed</p>', 'Nightly Menu Tests passed'], 'custom elements named after hidden ones')]
    #[DataSet(['<head><title>T</title><body-x>Hidden</body-x><body>Shown', 'Shown'], 'custom element named after the body')]
    #[DataSet(['<SCRIPT type="x">a</Script >b<template><p>c</p></template>d', 'b d'], 'tag case and template')]
    #[DataSet(['a<!-- <p>hidden</p> -->b<!-- open', 'a b'], 'comments')]
    #[DataSet(['<p>a < b</p><p>c&nbsp;d</p>', 'a < b c d'], 'a lone angle bracket is text')]
    #[DataSet(['a<script>never closed', 'a'], 'unclosed script')]
    #[DataSet(['<header>Top</header><headline>x', 'Top x'], 'names that start like hidden ones')]
    public function testVisibleText(string $markup, string $text): void
    {
        Assert::same(HtmlText::visible($markup), $text);
    }

    public function testCutsToCharacters(): void
    {
        Assert::same(HtmlText::visible('<p>Привет, мир</p>', 6), 'Привет');
        Assert::same(HtmlText::visible('<p>abc</p>', 10), 'abc');
    }

    /**
     * A lazy pattern over a megabyte would hit the PCRE backtrack limit and lose the whole text.
     */
    public function testLargePage(): void
    {
        $markup = '<script>' . \str_repeat('x', 2_000_000) . '</script><p>' . \str_repeat('word ', 200_000) . '</p>';

        Assert::same(HtmlText::visible($markup, 9), 'word word');
    }

    /**
     * A tag of a mebibyte of attributes runs PCRE past its backtrack limit: the text is still there.
     */
    #[DataSet(["''"])]
    #[DataSet(['='])]
    public function testTagPastBacktrackLimitKeepsText(string $repeated): void
    {
        $markup = '<a ' . \str_repeat($repeated, \intdiv(1024 * 1024, \strlen($repeated))) . '> tail';

        Assert::same(HtmlText::visible($markup), 'tail');
    }

    /**
     * A mebibyte, as a POST to `/html` may send. A scan that searches the rest of the markup again for each
     * unclosed tag takes tens of seconds on these, a linear one a fraction of a second.
     */
    #[DataSet(['', '<head>', 174_762], 'heads never ended')]
    #[DataSet(['<head>', '<head>x', 149_796], 'heads with text')]
    #[DataSet(['', '<head></head', 87_381], 'heads with broken end tags')]
    #[DataSet(['', '<head><body', 95_325], 'heads ended by bodies')]
    #[DataSet(['', '<script></script ', 61_680], 'scripts with broken end tags')]
    #[DataSet(['', '<title><!--', 95_325], 'titles and comments never ended')]
    #[DataSet(['', '<a', 524_288], 'tags never ended')]
    #[DataSet(['', '<title ', 174_762], 'title tags never closed')]
    #[DataSet(['', '</script ', 116_508], 'end tags never closed')]
    #[DataSet(['', '<a "', 262_144], 'quotes never closed')]
    #[DataSet(['', "<a '\" ", 174_762], 'mixed quotes')]
    #[DataSet(['', '<script>"<!--"</script>', 41_943], 'comment openings in scripts')]
    #[DataSet(['', '<a x="', 174_762], 'values never closed')]
    public function testMebibyteOfUnclosedTagsInLinearTime(string $prefix, string $repeated, int $times): void
    {
        $markup = $prefix . \str_repeat($repeated, $times);
        $start = \microtime(true);
        HtmlText::visible($markup);

        Assert::true(\microtime(true) - $start < 2.0);
    }
}
