<?php

declare(strict_types=1);

namespace Buggregator\Client\Sender\Console\Renderer;

use Buggregator\Client\Proto\Frame;
use Buggregator\Client\ProtoType;
use Buggregator\Client\Sender\Console\RendererInterface;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\VarDumper\Cloner\Data;
use Symfony\Component\VarDumper\Cloner\Stub;
use Symfony\Component\VarDumper\Command\Descriptor\CliDescriptor;
use Symfony\Component\VarDumper\Dumper\CliDumper;

final class VarDumper implements RendererInterface
{
    public function isSupport(Frame $frame): bool
    {
        return $frame->type === ProtoType::VarDumper;
    }

    /**
     * @param Frame\VarDumper $frame
     */
    public function render(OutputInterface $output, Frame $frame): void
    {
        $payload = @\unserialize(\base64_decode($frame->dump), ['allowed_classes' => [Data::class, Stub::class]]);

        // Impossible to decode the message, give up.
        if (false === $payload) {
            throw new RuntimeException("Unable to decode a message.");
        }

        $descriptor = new CliDescriptor(new CliDumper());

        [$data, $context] = $payload;

        $descriptor->describe(new SymfonyStyle(new ArrayInput([]), $output), $data, $context, 0);
    }
}
