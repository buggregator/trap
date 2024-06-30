<?php

declare(strict_types=1);

namespace Buggregator\Trap\Client\TrapHandle\ContextProvider;

use Buggregator\Trap\Client\TrapHandle\StaticState;
use Symfony\Component\HttpKernel\Debug\FileLinkFormatter;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\ContextProvider\ContextProviderInterface;
use Symfony\Component\VarDumper\Dumper\HtmlDumper;
use Symfony\Component\VarDumper\VarDumper;
use Twig\Template;

/**
 * Tries to provide context from sources (class name, file, line, code excerpt, ...).
 *
 * @author Nicolas Grekas <p@tchwork.com>
 * @author Maxime Steinhausser <maxime.steinhausser@gmail.com>
 *
 * @link https://github.com/symfony/var-dumper/blob/7.0/Dumper/ContextProvider/SourceContextProvider.php
 * @link https://github.com/symfony/var-dumper/blob/6.3/Dumper/ContextProvider/SourceContextProvider.php
 *
 * @psalm-suppress all
 *
 * todo: rewrite and decompose
 */
final class Source implements ContextProviderInterface
{
    /**
     * @psalm-suppress UndefinedClass
     */
    public function __construct(private readonly ?string $charset = null, private ?string $projectDir = null, private readonly ?FileLinkFormatter $fileLinkFormatter = null, private readonly int $limit = 9) {}

    public function getContext(): ?array
    {
        \assert(StaticState::getValue() !== null);
        $trace = StaticState::getValue()->stackTraceWithObjects;

        $file = $trace[0]['file'];
        $line = $trace[0]['line'];
        $name = $file === '-' || $file === 'Standard input code' ? 'Standard input code' : false;
        $fileExcerpt = false;

        for ($i = 0; $i < $this->limit; ++$i) {
            if (isset($trace[$i]['class'], $trace[$i]['function'])
                && $trace[$i]['function'] === 'dump'
                && $trace[$i]['class'] === VarDumper::class
            ) {
                $file = $trace[$i]['file'] ?? $file;
                $line = $trace[$i]['line'] ?? $line;

                while (++$i < $this->limit) {
                    if (isset($trace[$i]['function'], $trace[$i]['file']) && empty($trace[$i]['class']) && !\str_starts_with($trace[$i]['function'], 'call_user_func')) {
                        $file = $trace[$i]['file'];
                        $line = $trace[$i]['line'];

                        break;
                    } elseif (isset($trace[$i]['object']) && $trace[$i]['object'] instanceof Template) {
                        $template = $trace[$i]['object'];
                        $name = $template->getTemplateName();
                        $src = \method_exists($template, 'getSourceContext') ? $template->getSourceContext()->getCode() : (\method_exists($template, 'getSource') ? $template->getSource() : false);
                        $info = $template->getDebugInfo();
                        if (isset($info[$trace[$i - 1]['line']])) {
                            $line = $info[$trace[$i - 1]['line']];
                            $file = \method_exists($template, 'getSourceContext') ? $template->getSourceContext()->getPath() : null;

                            if ($src) {
                                $src = \explode("\n", (string) $src);
                                $fileExcerpt = [];

                                for ($i = \max($line - 3, 1), $max = \min($line + 3, \count($src)); $i <= $max; ++$i) {
                                    $fileExcerpt[] = '<li' . ($i === $line ? ' class="selected"' : '') . '><code>' . $this->htmlEncode($src[$i - 1]) . '</code></li>';
                                }

                                $fileExcerpt = '<ol start="' . \max($line - 3, 1) . '">' . \implode("\n", $fileExcerpt) . '</ol>';
                            }
                        }

                        break;
                    }
                }

                break;
            }
        }

        if ($name === false) {
            $name = \str_replace('\\', '/', $file);
            $name = \substr($name, \strrpos($name, '/') + 1);
        }

        $context = ['name' => $name, 'file' => $file, 'line' => $line];
        $context['file_excerpt'] = $fileExcerpt;

        if ($this->projectDir !== null) {
            $context['project_dir'] = $this->projectDir;
            if (\str_starts_with((string) $file, $this->projectDir)) {
                $context['file_relative'] = \ltrim(\substr((string) $file, \strlen($this->projectDir)), \DIRECTORY_SEPARATOR);
            }
        }

        if ($this->fileLinkFormatter && $fileLink = $this->fileLinkFormatter->format($context['file'], $context['line'])) {
            $context['file_link'] = $fileLink;
        }

        return $context;
    }

    private function htmlEncode(string $s): string
    {
        $html = '';

        $dumper = new HtmlDumper(static function (string $line) use (&$html): void { $html .= $line; }, $this->charset);
        $dumper->setDumpHeader('');
        $dumper->setDumpBoundaries('', '');

        $cloner = new VarCloner();
        $dumper->dump($cloner->cloneVar($s));

        return \substr(\strip_tags($html), 1, -1);
    }
}
