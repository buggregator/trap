<?php

declare(strict_types=1);

namespace Buggregator\Trap\Client\TrapHandle;

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
use Symfony\Component\VarDumper\Dumper\DataDumperInterface;
use Symfony\Component\VarDumper\Dumper\HtmlDumper;
use Symfony\Component\VarDumper\Dumper\ServerDumper;

/**
 * @internal
 * @psalm-internal Buggregator\Trap\Client
 */
final class Dumper
{
    /** @var null|\Closure(mixed, string|null, int): mixed */
    private static ?\Closure $handler = null;

    public static function dump(mixed $var, string|int|null $label = null, int $depth = 0): mixed
    {
        /** @psalm-suppress RiskyTruthyFalsyComparison */
        return (self::$handler ??= self::registerHandler())($var, empty($label) ? null : (string) $label, $depth);
    }

    /**
     * @return null|callable(mixed, string|null, int): mixed
     * @psalm-suppress MixedInferredReturnType, MixedPropertyTypeCoercion, MismatchingDocblockReturnType
     */
    public static function setHandler(callable $callable = null): ?\Closure
    {
        return ([$callable, self::$handler] = [self::$handler, $callable === null ? null : $callable(...)])[0];
    }

    /**
     * @return \Closure(mixed, string|null, int): mixed
     */
    public static function setDumper(?DataDumperInterface $dumper = null): \Closure
    {
        if ($dumper === null) {
            return self::registerHandler();
        }

        $cloner = new VarCloner();
        /** @psalm-suppress InvalidArgument */
        $cloner->addCasters(ReflectionCaster::UNSET_CLOSURE_FILE_INFO);

        return self::$handler = static function (mixed $var, string|null $label = null, int $depth = 0) use ($cloner, $dumper): ?string {
            $var = $cloner->cloneVar($var);

            /** @var array<array-key, mixed> $context*/
            $context = StaticState::getValue()?->dataContext ?? [];

            /** @var string|null $label */
            $label === null or $context['label'] = $label;
            $context === [] or $var = $var->withContext($context);
            $depth > 0 and $var = $var->withMaxDepth($depth);

            return $dumper->dump($var);
        };
    }

    /**
     * @return \Closure(mixed, string|null, int): mixed
     *
     * @author Nicolas Grekas <p@tchwork.com>
     * @psalm-suppress RiskyTruthyFalsyComparison
     */
    private static function registerHandler(): \Closure
    {
        $cloner = new VarCloner();
        /** @psalm-suppress InvalidArgument */
        $cloner->addCasters(ReflectionCaster::UNSET_CLOSURE_FILE_INFO);

        $format = $_SERVER['VAR_DUMPER_FORMAT'] ?? null;
        switch (true) {
            case $format === 'html':
                $dumper = new HtmlDumper();
                break;
            case $format === 'cli':
                $dumper = new CliDumper();
                break;
            case $format === 'server':
            case $format && \parse_url($format, \PHP_URL_SCHEME) === 'tcp':
                $host = $format === 'server' ? $_SERVER['VAR_DUMPER_SERVER'] ?? '127.0.0.1:9912' : $format;
                $dumper = \in_array(\PHP_SAPI, ['cli', 'phpdbg'], true) ? new CliDumper() : new HtmlDumper();
                $dumper = new ServerDumper($host, $dumper, self::getContextProviders());
                break;
            default:
                $dumper = \in_array(\PHP_SAPI, ['cli', 'phpdbg'], true) ? new CliDumper() : new HtmlDumper();
        }

        if (!$dumper instanceof ServerDumper) {
            $dumper = new ContextualizedDumper($dumper, [new SourceContextProvider()]);
        }

        return self::setDumper($dumper);
    }

    /**
     * @return array<array-key, ContextProviderInterface> The context providers
     * @author Nicolas Grekas <p@tchwork.com>
     *
     * @psalm-suppress UndefinedClass
     */
    private static function getContextProviders(): array
    {
        $contextProviders = [];

        if (!\in_array(\PHP_SAPI, ['cli', 'phpdbg'], true) && \class_exists(Request::class)) {
            $requestStack = new RequestStack();
            /** @psalm-suppress MixedMethodCall */
            $requestStack->push(Request::createFromGlobals());
            $contextProviders['request'] = new RequestContextProvider($requestStack);
        }

        /** @var null|FileLinkFormatter $fileLinkFormatter */
        $fileLinkFormatter = \class_exists(FileLinkFormatter::class)
            ? new FileLinkFormatter(null, $requestStack ?? null)
            : null;

        return $contextProviders + [
            'cli' => new CliContextProvider(),
            'source' => new ContextProvider\Source(null, null, $fileLinkFormatter),
        ];
    }
}
