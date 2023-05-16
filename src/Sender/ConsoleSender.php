<?php

declare(strict_types=1);

namespace Buggregator\Client\Sender;

use Buggregator\Client\Proto\Frame;
use Buggregator\Client\ProtoType;
use Buggregator\Client\Sender;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;
use Symfony\Component\VarDumper\VarDumper;

class ConsoleSender implements Sender
{
    /**
     * @param iterable<int, Frame> $frames
     */
    public function send(iterable $frames): void
    {
        foreach ($frames as $frame) {
            if ($frame->type === ProtoType::VarDumper) {
                // $data = \base64_decode($frame->data);
                // $cloner = (new VarCloner());
                // (new CliDumper())->dump($data);
                // \dump();
            } else {
                echo $frame->data;
            }
        }
    }
}
