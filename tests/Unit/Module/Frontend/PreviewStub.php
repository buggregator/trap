<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Frontend;

use Buggregator\Trap\Server\Event\Previewable;

/**
 * A payload whose preview and whole form tell apart which one an endpoint sent.
 */
final class PreviewStub implements Previewable
{
    public function preview(): array
    {
        return ['html' => 'card'];
    }

    public function jsonSerialize(): array
    {
        return ['html' => 'page'];
    }
}
