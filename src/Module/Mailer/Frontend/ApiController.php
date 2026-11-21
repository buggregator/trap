<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Mailer\Frontend;

use Buggregator\Trap\Module\Frontend\Message\Error;
use Buggregator\Trap\Module\Mailer\Frontend\Message\Attachments;
use Buggregator\Trap\Server\Event\AttachedFile;
use Buggregator\Trap\Server\Event\EventStore;
use Buggregator\Trap\Server\Handler\Router\Attribute\AssertRouteSuccess as AssertSuccess;
use Buggregator\Trap\Server\Handler\Router\Attribute\RegexpRoute;
use Buggregator\Trap\Server\Handler\Router\Method;
use Buggregator\Trap\Server\Logger;

/**
 * @internal
 */
final class ApiController
{
    public function __construct(
        private readonly Logger $logger,
        private readonly EventStore $eventsStorage,
    ) {}

    #[RegexpRoute(Method::Get, '#^api/smtp/attachments/(?<uuid>[a-f0-9-]++)$#i')]
    #[AssertSuccess(Method::Get, 'api/smtp/attachments/018ff30e-a452-7316-b60f-a2d1c3fe16ab', [
        'uuid' => '018ff30e-a452-7316-b60f-a2d1c3fe16ab',
    ]),]
    public function smtpAttachments(string $uuid): Attachments|Error
    {
        $this->logger->debug('[UI Service] Show SMTP %s attachments', $uuid);
        $event = $this->eventsStorage->get($uuid);

        if ($event === null) {
            return Error::notFound('Event not found');
        }

        $attaches = [];
        foreach ($event->assets ?? [] as $attachment) {
            $attachment instanceof AttachedFile and $attaches[] = $attachment;
        }

        return new Attachments($event, files: $attaches);
    }
}
