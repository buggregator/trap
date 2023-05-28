<?php

declare(strict_types=1);

namespace Buggregator\Client\Traffic\Message\Smtp;

class Contact
{
    public function __construct(
        public readonly ?string $name,
        public readonly ?string $email,
    ) {
    }
}
