<?php

declare(strict_types=1);

namespace Buggregator\Client;

interface Sender
{
    public function send(string $data): void;
}