<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Console;

use Buggregator\Trap\Module\Console\ConsoleSender;
use Buggregator\Trap\Module\Console\FrameHandler;
use Buggregator\Trap\Module\Console\Renderer;
use Buggregator\Trap\Module\HttpDump\Console\Renderer as HttpDumpRenderer;
use Buggregator\Trap\Module\Mailer\Console\Renderer as MailerRenderer;
use Buggregator\Trap\Module\Monolog\Console\Renderer as MonologRenderer;
use Buggregator\Trap\Module\Profiler\Console\Renderer as ProfilerRenderer;
use Buggregator\Trap\Module\Ray\Console\Renderer as RayRenderer;
use Buggregator\Trap\Module\Sentry\Console\SentryEnvelope as SentryEnvelopeRenderer;
use Buggregator\Trap\Module\Sentry\Console\SentryStore as SentryStoreRenderer;
use Buggregator\Trap\Module\Tracing\Console\Renderer as TracingRenderer;
use Buggregator\Trap\Module\Tracing\SpanStore;
use Buggregator\Trap\Module\VarDumper\Console\Renderer as VarDumperRenderer;
use Buggregator\Trap\Server\Application\Module\Modules;
use Internal\Container\ObjectContainer;
use Symfony\Component\Console\Output\BufferedOutput;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Covers(ConsoleSender::class)]
#[Test]
final class ConsoleSenderTest
{
    public function testModuleRenderersInListOrderThenChannelOnesWithPlainLast(): void
    {
        $renderers = self::renderers(self::sender(new ObjectContainer()));

        Assert::same(\array_map(static fn(Renderer $renderer): string => $renderer::class, $renderers), [
            VarDumperRenderer::class,
            MailerRenderer::class,
            MonologRenderer::class,
            RayRenderer::class,
            SentryStoreRenderer::class,
            SentryEnvelopeRenderer::class,
            ProfilerRenderer::class,
            TracingRenderer::class,
            HttpDumpRenderer::class,
            Renderer\Binary::class,
            Renderer\Plain::class,
        ]);
    }

    public function testTracingRendererUsesTheSpanStoreOfTheContainer(): void
    {
        $container = new ObjectContainer();

        $renderers = self::renderers(self::sender($container));
        $tracing = \array_values(\array_filter(
            $renderers,
            static fn(Renderer $renderer): bool => $renderer instanceof TracingRenderer,
        ))[0];

        Assert::same((new \ReflectionProperty(TracingRenderer::class, 'store'))->getValue($tracing), $container->get(SpanStore::class));
    }

    private static function sender(ObjectContainer $container): ConsoleSender
    {
        return new ConsoleSender(new BufferedOutput(), $container->get(Modules::class), $container);
    }

    /**
     * @return list<Renderer>
     */
    private static function renderers(ConsoleSender $sender): array
    {
        $handler = (new \ReflectionProperty(ConsoleSender::class, 'handler'))->getValue($sender);

        return (new \ReflectionProperty(FrameHandler::class, 'renderers'))->getValue($handler);
    }
}
