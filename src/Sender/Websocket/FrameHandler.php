<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Websocket;

use Buggregator\Trap\Proto\Frame;
use Buggregator\Trap\Sender\FrameHandler as HandlerInterface;

/**
 * @internal
 */
final class FrameHandler implements HandlerInterface
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    public function handle(Frame $frame): void
    {
        // todo Replace with buffer
        $this->connectionPool->send(\Buggregator\Trap\Traffic\Websocket\Frame::text(
            '{"push":{"channel":"events","pub":{"data":{"event":"event.received","data":{"projectId":null,"uuid":"018c4fe7-cc08-71fc-9e5d-cebbaf02d9e5","type":"var-dump","payload":{"payload":{"type":"boolean","value":""},"context":{"timestamp":1702147640.315089,"cli":{"command_line":"D:\\git\\buggregator\\trap\\bin\\trap test","identifier":"387029ce"},"source":{"name":"Test.php","file":"D:\\git\\buggregator\\trap\\src\\Command\\Test.php","line":58,"file_excerpt":false}}},"timestamp":1702147640.329395}}}}}'
        ));
    }
}
