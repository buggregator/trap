<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Websocket;

use Buggregator\Trap\Info;
use Buggregator\Trap\Logger;
use Buggregator\Trap\Support\Json;
use Buggregator\Trap\Support\Uuid;
use JsonSerializable;

/**
 * @internal
 */
final class RPC
{
    public function __construct(
        private readonly Logger $logger,
        private readonly EventsStorage $eventsStorage,
    ) {
    }

    public function handleMessage(string $message): ?object
    {
        try {
            if ($message === '') {
                return (object)[];
            }

            $json = \json_decode($message, true, 512, \JSON_THROW_ON_ERROR);
            if (!\is_array($json)) {
                return null;
            }
            $id = $json['id'] ?? 1;

            if (isset($json['connect'])) {
                return new RPC\Connected(id: $id, client: Uuid::uuid4());
            }

            if (isset($json['rpc']['method'])) {
                $method = $json['rpc']['method'];

                return $this->callMethod($id, $method);
            }
        } catch (\Throwable $e) {
            $this->logger->exception($e);
        }
        return null;
    }

    private function callMethod(int|string $id, string $initMethod): ?JsonSerializable
    {
        [$method, $path] = \explode(':', $initMethod, 2);

        switch ($method) {
            case 'delete':
                if (\str_starts_with($path, 'api/event/')) {
                    $uuid = \substr($path, 10);
                    $this->eventsStorage->delete($uuid);
                    return new RPC\Success(id: $id, code: 200, status: true);
                }
                if (\str_starts_with($path, 'api/events')) {
                    $this->eventsStorage->clear();
                    return new RPC\Success(id: $id, code: 200, status: true);
                }
                break;
            default:
                $this->logger->error('Unknown RPC method: ' . $initMethod);
        }

        return null;
    }
}
