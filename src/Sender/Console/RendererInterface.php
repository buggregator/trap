<?php

declare(strict_types=1);

namespace Buggregator\Client\Sender\Console;

use Buggregator\Client\Proto\Frame;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @template TFrame of Frame
 */
interface RendererInterface
{
    /**
     * @psalm-assert-if-true TFrame $frame
     */
    public function isSupport(Frame $frame): bool;

    /**
     * @param TFrame $frame
     */
    public function render(OutputInterface $output, Frame $frame): void;
}
