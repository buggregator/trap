<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Console\Renderer;

use Buggregator\Trap\Proto\Frame;
use Buggregator\Trap\ProtoType;
use Buggregator\Trap\Sender\Console\Renderer;
use Buggregator\Trap\Sender\Console\Support\Common;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\VarDumper\Cloner\Data;
use Symfony\Component\VarDumper\Cloner\Stub;
use Symfony\Component\VarDumper\Command\Descriptor\DumpDescriptorInterface;
use Symfony\Component\VarDumper\Dumper\CliDumper;

/**
 * @implements Renderer<Frame\VarDumper>
 *
 * @internal
 */
final class VarDumper implements Renderer
{
    public function isSupport(Frame $frame): bool
    {
        return $frame->type === ProtoType::VarDumper;
    }

    public function render(OutputInterface $output, Frame $frame): void
    {
        \assert($frame instanceof Frame\VarDumper);

        /** @var array{Data, array}|false $payload */
        $payload = @\unserialize(\base64_decode($frame->dump, true), ['allowed_classes' => [Data::class, Stub::class]]);

        // Impossible to decode the message, give up.
        $payload === false and throw new \RuntimeException("Unable to decode the message.");

        [$data, $context] = $payload;
        $this
            ->getDescriber($output)
            ->describe(new SymfonyStyle(new ArrayInput([]), $output), $data, $context, 0);
    }

    private function getDescriber(OutputInterface $output): DumpDescriptorInterface
    {
        return new class(new CliDumper($output)) implements DumpDescriptorInterface {
            public function __construct(
                private readonly CliDumper $dumper,
            ) {}

            /**
             * @psalm-suppress RiskyTruthyFalsyComparison, MixedArrayAccess, MixedArgument
             */
            public function describe(OutputInterface $output, Data $data, array $context, int $clientId): void
            {
                Common::renderHeader1($output, 'DUMP');

                $this->dumper->setColors($output->isDecorated());

                $meta = [];
                $meta['Time'] = (new \DateTimeImmutable())->setTimestamp((int) $context['timestamp']);

                try {
                    if (isset($context['source'])) {
                        $source = $context['source'];
                        \assert(\is_array($source));

                        $sourceInfo = \sprintf('%s:%d', $source['name'], $source['line']);
                        if (isset($source['file_link'])) {
                            $sourceInfo = \sprintf('<href=%s>%s</>', $source['file_link'], $sourceInfo);
                            $meta['Source'] = $sourceInfo;
                        }

                        if (isset($source['file_relative'])) {
                            $meta['File'] = $source['file_relative'];
                        } else {
                            $meta['File'] = \sprintf('%s:%s', $source['file'], $source['line']);
                        }
                    }

                    if (isset($context['request'])) {
                        $request = $context['request'];
                        \assert(\is_array($request));

                        empty($request['method'] ?? '') or $meta['Method'] = $request['method'];
                        empty($request['uri'] ?? '') or $meta['URI'] = $request['uri'];
                        if ($controller = $request['controller']) {
                            $meta['Controller'] = \rtrim((string) $this->dumper->dump($controller, true), "\n");
                        }
                    } elseif (isset($context['cli'])) {
                        $meta['Command'] = $context['cli']['command_line'];
                    }
                } catch (\Throwable) {
                    // Do nothing.
                }

                /** @psalm-suppress InternalMethod, InternalClass */
                Common::renderMetadata($output, $meta);
                $output->writeln('');

                // Render Data context
                if ($data->getContext() !== []) {
                    Common::renderHeader3($output, 'Data context');
                    // todo the context may contain mixed data
                    // todo need to consider it and render the context in a good way
                    Common::renderMetadata($output, $data->getContext());
                    // $output->writeln(\print_r($data->getContext(), true));
                    $output->writeln('');
                }

                $output->write((string) $this->dumper->dump($data, true), true, OutputInterface::OUTPUT_RAW);
            }
        };
    }
}
