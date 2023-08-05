<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Console;

use Buggregator\Trap\Proto\Frame;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @template TFrame of Frame
 * @template-covariant
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
