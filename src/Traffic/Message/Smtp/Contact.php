<?php

declare(strict_types=1);

namespace Buggregator\Client\Traffic\Message\Smtp;

/**
 * @psalm-immutable
 * @internal
 * @psalm-internal Buggregator\Client
 */
final class Contact
{
    public function __construct(
        public readonly ?string $name,
        public readonly ?string $email,
    ) {
    }
}
