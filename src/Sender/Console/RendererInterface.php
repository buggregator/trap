<?php

declare(strict_types=1);

namespace Buggregator\Client\Sender\Console;

use Buggregator\Client\Proto\Frame;
use Symfony\Component\Console\Output\OutputInterface;

interface RendererInterface
{
    public function isSupport(Frame $frame): bool;
    public function render(OutputInterface $output, Frame $frame): void;
}
