<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Console\Renderer;

use Buggregator\Trap\Proto\Frame;
use Buggregator\Trap\ProtoType;
use Buggregator\Trap\Sender\Console\Renderer;
use Buggregator\Trap\Sender\Console\Support\Common;
use DateTimeImmutable;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @implements Renderer<Frame\Profiler>
 *
 * @internal
 */
final class Profiler implements Renderer
{
    public function isSupport(Frame $frame): bool
    {
        return $frame->type === ProtoType::Profiler;
    }

    public function render(OutputInterface $output, Frame $frame): void
    {
        \assert($frame instanceof Frame\Profiler);

        $subtitle = $frame->payload->type->value;
        Common::renderHeader1($output, 'PROFILER', $subtitle);

        $metadata = $frame->payload->getMetadata();
        $data = [];
        isset($metadata['date']) && \is_numeric($metadata['date'])
        and $data['Time'] = new DateTimeImmutable('@' . $metadata['date']);
        isset($metadata['hostname']) and $data['Hostname'] = $metadata['hostname'];
        isset($metadata['filename']) and $data['File name'] = $metadata['filename'];

        Common::renderMetadata($output, $data);
    }
}
