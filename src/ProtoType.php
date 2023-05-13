<?php

declare(strict_types=1);

namespace Buggregator\Client;

enum ProtoType: string
{
    case VarDumper = 'var-dumper';

    public function getDefaultPort(): int
    {
        return match ($this) {
            self::VarDumper => 9912,
        };
    }
}