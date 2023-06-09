<?php

declare(strict_types=1);

namespace Buggregator\Client;

enum ProtoType: string
{
    case VarDumper = 'var-dumper';
    case HTTP = 'http';
    case SMTP = 'smtp';
    case Monolog = 'monolog';
    case Binary = 'binary';
}
