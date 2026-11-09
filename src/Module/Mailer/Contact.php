<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Mailer;

/**
 * @psalm-immutable
 * @internal
 * @psalm-internal Buggregator\Trap
 */
final class Contact
{
    public function __construct(
        public readonly ?string $name,
        public readonly ?string $email,
    ) {}
}
