<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Sentry\Mcp;

use Buggregator\Trap\Module\Sentry\Mcp\ListFeedback;
use Buggregator\Trap\Server\Event\Event;
use Buggregator\Trap\Server\Event\EventStore;
use Buggregator\Trap\Server\Telemetry\Batch;
use Buggregator\Trap\Server\Telemetry\Feedback;
use Buggregator\Trap\Server\Telemetry\TelemetryStore;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Covers(ListFeedback::class)]
#[Test]
final class ListFeedbackTest
{
    public function testListsFeedbackNewestFirstWithItsErrorEvent(): void
    {
        [$tool] = self::tool();

        $result = $tool->call([]);

        Assert::same($result['total'], 3);
        Assert::same(\array_column($result['feedback'], 'message'), ['Third', 'Second', 'First']);
        Assert::same($result['feedback'][2]['error_uuid'], 'aa01');
        Assert::null($result['feedback'][1]['error_uuid']);
        Assert::same($result['feedback'][2]['received'], '1970-01-01T00:00:01.000+00:00');
    }

    public function testNarrowsByProjectEventIdAndLimit(): void
    {
        [$tool] = self::tool();

        Assert::same(\array_column($tool->call(['project' => 'shop'])['feedback'], 'message'), ['Third', 'First']);
        Assert::same(\array_column($tool->call(['event_id' => 'E1E1E1E1-E1E1'])['feedback'], 'message'), ['First']);
        $limited = $tool->call(['limit' => 1]);
        Assert::same([\count($limited['feedback']), $limited['total']], [1, 3]);
    }

    /**
     * @return array{ListFeedback}
     */
    private static function tool(): array
    {
        $events = new EventStore();
        $events->add(new Event('aa01', 'sentry', ['event_id' => 'e1e1e1e1e1e1'], 1.0, 'shop'));
        $telemetry = new TelemetryStore();
        foreach ([['shop', 'e1e1e1e1e1e1', 'First'], ['billing', '', 'Second'], ['shop', '', 'Third']] as $i => [$project, $eventId, $message]) {
            $batch = new Batch($project);
            $batch->feedback[] = new Feedback("f$i", $project, $eventId, '', 'Ann', '', $message, '', Feedback::SOURCE_FEEDBACK, 1.0 + $i);
            $telemetry->add($batch);
        }

        return [new ListFeedback($telemetry, $events)];
    }
}
