<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Frontend;

use Buggregator\Trap\Server\Event\Previewable;
use Buggregator\Trap\Server\Event\Searchable;

/**
 * A payload whose search text is in none of its fields.
 */
final class SearchableStub implements Previewable, Searchable
{
    public function preview(): array
    {
        return ['message' => 'card'];
    }

    public function jsonSerialize(): array
    {
        return ['message' => 'page'];
    }

    public function searchText(): string
    {
        return 'kept text';
    }
}
