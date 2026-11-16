<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Server\Event\Stub;

use Buggregator\Trap\Server\Event\Record;
use Buggregator\Trap\Server\Event\StoreListener;

/**
 * Records the calls as `added:{uuid}`, `updated:{uuid}`, `deleted:{uuid}` and `cleared`.
 */
final class RecordingListener implements StoreListener
{
    /** @var list<non-empty-string> */
    public array $calls = [];

    public function added(Record $record): void
    {
        $this->calls[] = 'added:' . $record->event->uuid;
    }

    public function updated(Record $record): void
    {
        $this->calls[] = 'updated:' . $record->event->uuid;
    }

    public function deleted(Record $record): void
    {
        $this->calls[] = 'deleted:' . $record->event->uuid;
    }

    public function cleared(): void
    {
        $this->calls[] = 'cleared';
    }
}
