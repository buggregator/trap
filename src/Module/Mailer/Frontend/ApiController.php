<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Mailer\Frontend;

use Buggregator\Trap\Module\Frontend\Event\AttachedFile;
use Buggregator\Trap\Module\Frontend\EventStorage;
use Buggregator\Trap\Module\Frontend\Message\Success;
use Buggregator\Trap\Module\Mailer\Frontend\Message\Attachments;
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
        private readonly EventStorage $eventsStorage,
    ) {}

    #[RegexpRoute(Method::Get, '#^api/smtp/(?<uuid>[a-f0-9-]++)/attachments$#i')]
    #[AssertSuccess(Method::Get, 'api/smtp/018ff30e-a452-7316-b60f-a2d1c3fe16ab/attachments', [
        'uuid' => '018ff30e-a452-7316-b60f-a2d1c3fe16ab',
    ]),]
    public function smtpAttachments(string $uuid): Attachments|Success
    {
        $this->logger->debug('[UI Service] Show SMTP %s attachments', $uuid);
        $event = $this->eventsStorage->get($uuid);

        if ($event === null) {
            return new Success(status: false);
        }

        $attaches = [];
        foreach ($event->assets ?? [] as $attachment) {
            $attachment instanceof AttachedFile and $attaches[] = $attachment;
        }

        return new Attachments($event, files: $attaches);
    }
}
