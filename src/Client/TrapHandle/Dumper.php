<?php

declare(strict_types=1);

namespace Buggregator\Trap\Client\TrapHandle;

use Closure;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Debug\FileLinkFormatter;
use Symfony\Component\VarDumper\Caster\ReflectionCaster;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;
use Symfony\Component\VarDumper\Dumper\ContextProvider\CliContextProvider;
use Symfony\Component\VarDumper\Dumper\ContextProvider\ContextProviderInterface;
use Symfony\Component\VarDumper\Dumper\ContextProvider\RequestContextProvider;
use Symfony\Component\VarDumper\Dumper\ContextProvider\SourceContextProvider;
use Symfony\Component\VarDumper\Dumper\ContextualizedDumper;
use Symfony\Component\VarDumper\Dumper\HtmlDumper;
use Symfony\Component\VarDumper\Dumper\ServerDumper;

/**
 * @internal
 * @psalm-internal Buggregator\Trap\Client
 * @psalm-type DumperHandler = Closure(mixed $var, string|int|null $label, int $depth): void
 */
final class Dumper
{
    /** @var DumperHandler|null */
    private static ?Closure $handler;

    public static function dump(mixed $var, string|int|null $label = null, int $depth = 0)
    {
        return (self::$handler ??= self::registerHandler())($var, $label, $depth);
    }

    /**
     * @return DumperHandler|null The previous handler if any
     */
    public static function setHandler(callable $callable = null): ?Closure
    {
        return ([$callable, self::$handler] = [self::$handler, $callable(...)])[0];
    }

    /**
     * @return DumperHandler
     * @author Nicolas Grekas <p@tchwork.com>
     */
    private static function registerHandler(): Closure
    {
        $cloner = new VarCloner();
        $cloner->addCasters(ReflectionCaster::UNSET_CLOSURE_FILE_INFO);

        $format = $_SERVER['VAR_DUMPER_FORMAT'] ?? null;
        switch (true) {
            case 'html' === $format:
                $dumper = new HtmlDumper();
                break;
            case 'cli' === $format:
                $dumper = new CliDumper();
                break;
            case 'server' === $format:
            case $format && 'tcp' === parse_url($format, \PHP_URL_SCHEME):
                $host = 'server' === $format ? $_SERVER['VAR_DUMPER_SERVER'] ?? '127.0.0.1:9912' : $format;
                $dumper = \in_array(\PHP_SAPI, ['cli', 'phpdbg'], true) ? new CliDumper() : new HtmlDumper();
                $dumper = new ServerDumper($host, $dumper, self::getContextProviders());
                break;
            default:
                $dumper = \in_array(\PHP_SAPI, ['cli', 'phpdbg'], true) ? new CliDumper() : new HtmlDumper();
        }

        if (!$dumper instanceof ServerDumper) {
            $dumper = new ContextualizedDumper($dumper, [new SourceContextProvider()]);
        }

        return self::$handler = static function ($var, string|int|null $label = null, int $depth = 0) use ($cloner, $dumper): void {
            $var = $cloner->cloneVar($var);

            $label === null or $var = $var->withContext(['label' => $label]);
            $depth > 0 and $var = $var->withMaxDepth($depth);

            $dumper->dump($var);
        };
    }

    /**
     * @return array<array-key, ContextProviderInterface> The context providers
     * @author Nicolas Grekas <p@tchwork.com>
     */
    private static function getContextProviders(): array
    {
        $contextProviders = [];

        if (!\in_array(\PHP_SAPI, ['cli', 'phpdbg'], true) && \class_exists(Request::class)) {
            $requestStack = new RequestStack();
            $requestStack->push(Request::createFromGlobals());
            $contextProviders['request'] = new RequestContextProvider($requestStack);
        }

        $fileLinkFormatter = \class_exists(FileLinkFormatter::class) ? new FileLinkFormatter(null, $requestStack ?? null) : null;

        return $contextProviders + [
                'cli' => new CliContextProvider(),
                'source' => new ContextProvider\Source(null, null, $fileLinkFormatter),
            ];
    }
}
