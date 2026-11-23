<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Mailer;

use Buggregator\Trap\Module\Mailer\Alternative;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

#[Covers(Alternative::class)]
#[Test]
final class AlternativeTest
{
    #[DataSet(['text/watch-html', true], 'Apple Watch')]
    #[DataSet(['text/x-amp-html', true], 'AMP')]
    #[DataSet(['text/calendar', true], 'calendar')]
    #[DataSet(['application/ics', true], 'calendar of a non-text type')]
    #[DataSet(['message/delivery-status', true], 'delivery report')]
    #[DataSet(['text/csv', true], 'any other text')]
    #[DataSet(['text/plain', false], 'plain text body')]
    #[DataSet(['text/html', false], 'HTML body')]
    #[DataSet(['image/png', false], 'image')]
    #[DataSet(['message/rfc822', false], 'forwarded email')]
    public function testIsAlternativeType(string $type, bool $expected): void
    {
        Assert::same(Alternative::isAlternativeType($type), $expected);
    }

    #[DataSet(['', 'text/plain'], 'no Content-Type')]
    #[DataSet([' Text/Calendar ; method=REQUEST', 'text/calendar'], 'parameters and case')]
    public function testMediaType(string $contentType, string $expected): void
    {
        Assert::same(Alternative::mediaType($contentType), $expected);
    }

    public function testLabelsKnownTypeAndNamesOtherByType(): void
    {
        Assert::same(Alternative::toArray('text/watch-html', '<b>Hi</b>'), [
            'type' => 'text/watch-html',
            'label' => 'Apple Watch',
            'content' => '<b>Hi</b>',
            'size' => 9,
        ]);
        Assert::same(Alternative::toArray('text/csv', 'a,b')['label'], 'text/csv');
    }

    public function testCutsContentOnCharacterBoundary(): void
    {
        // The limit falls inside the second byte of the first `✓`
        $content = \str_repeat('x', Alternative::MAX_CONTENT - 1) . '✓✓';

        $part = Alternative::toArray('text/watch-html', $content);

        Assert::same($part['size'], \strlen($content));
        Assert::true($part['truncated'] ?? false);
        Assert::same($part['content'], \str_repeat('x', Alternative::MAX_CONTENT - 1));
    }
}
