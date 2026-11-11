<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Console;

use Buggregator\Trap\Server\Proto\Frame;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @template-covariant TFrame of Frame
 */
interface Renderer
{
    /**
     * @psalm-assert-if-true TFrame $frame
     */
    public function isSupport(Frame $frame): bool;

    /**
     * @psalm-assert-if-true TFrame $frame
     */
    public function render(OutputInterface $output, Frame $frame): void;
}
