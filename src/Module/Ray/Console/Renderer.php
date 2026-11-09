<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Ray\Console;

use Buggregator\Trap\Module\Ray\Frame as RayFrame;
use Buggregator\Trap\Proto\Frame;
use Buggregator\Trap\Sender\Console\Renderer as RendererInterface;
use Buggregator\Trap\Sender\Console\Support\Common;
use Buggregator\Trap\Support\Json;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * A one-line summary of each payload of a Ray request.
 *
 * @implements RendererInterface<RayFrame>
 *
 * @internal
 */
final class Renderer implements RendererInterface
{
    private const MAX_LENGTH = 200;

    #[\Override]
    public function isSupport(Frame $frame): bool
    {
        return $frame instanceof RayFrame;
    }

    #[\Override]
    public function render(OutputInterface $output, Frame $frame): void
    {
        \assert($frame instanceof RayFrame);

        Common::renderHeader1($output, 'RAY');

        /** @var mixed $origin */
        $origin = $frame->payloads[0]['origin'] ?? null;
        $origin = \is_array($origin) ? $origin : [];
        $metadata = ['Time' => $frame->time];
        isset($origin['file']) and $metadata['File'] = self::text($origin['file']) . ':'
            . (isset($origin['line_number']) ? self::text($origin['line_number']) : '?');
        isset($origin['hostname']) and $metadata['Host'] = self::text($origin['hostname']);
        $project = self::text($frame->meta['project_name'] ?? '');
        $project === '' or $metadata['Project'] = $project;
        Common::renderMetadata($output, $metadata);

        $output->writeln('');
        foreach ($frame->payloads as $payload) {
            /** @var mixed $content */
            $content = $payload['content'] ?? null;
            $summary = self::summary($payload['type'], \is_array($content) ? $content : []);
            $output->writeln(\sprintf(
                '<fg=gray>%s</> %s',
                OutputFormatter::escape(\str_pad($payload['type'], 12)),
                OutputFormatter::escape($summary),
            ));
        }
    }

    private static function summary(string $type, array $content): string
    {
        return match ($type) {
            'log' => \implode(', ', \array_map(self::text(...), (array) ($content['values'] ?? []))),
            'custom' => self::labeled($content['label'] ?? '', self::text($content['content'] ?? '')),
            'label', 'color', 'size' => self::text($content[$type] ?? ''),
            'notify', 'json_string' => self::text($content['value'] ?? ''),
            'create_lock' => 'pause(), lock ' . self::text($content['name'] ?? ''),
            'exception' => self::labeled($content['class'] ?? '', self::text($content['message'] ?? '')),
            'table' => self::labeled($content['label'] ?? '', \count((array) ($content['values'] ?? [])) . ' rows'),
            'measure' => self::labeled($content['name'] ?? '', self::text($content['total_time'] ?? '') . ' ms'),
            'carbon' => self::text($content['formatted'] ?? ''),
            'caller' => self::frame($content['frame'] ?? null),
            'trace' => \count((array) ($content['frames'] ?? [])) . ' frames',
            default => $content === [] ? '' : self::text($content),
        };
    }

    private static function labeled(mixed $label, string $text): string
    {
        $label = self::text($label);
        return $label === '' ? $text : "$label: $text";
    }

    private static function frame(mixed $frame): string
    {
        if (!\is_array($frame)) {
            return '';
        }

        $call = \is_string($frame['class'] ?? null) && \is_string($frame['method'] ?? null)
            ? "{$frame['class']}::{$frame['method']}() "
            : '';

        return $call . self::text($frame['file_name'] ?? '') . ':' . self::text($frame['line_number'] ?? '');
    }

    /**
     * Values of non-scalar dumps come as HTML.
     */
    private static function text(mixed $value): string
    {
        $text = match (true) {
            \is_string($value) => \html_entity_decode(\strip_tags(self::dropDumperAssets($value)), \ENT_QUOTES | \ENT_HTML5),
            \is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            \is_scalar($value) => (string) $value,
            default => Json::encode($value),
        };
        $text = \trim((string) \preg_replace('/\s+/', ' ', $text));

        return \mb_strlen($text) > self::MAX_LENGTH ? \mb_substr($text, 0, self::MAX_LENGTH) . '…' : $text;
    }

    /**
     * strip_tags() keeps the text of `<style>` and `<script>` that the Symfony HTML dumper prepends.
     */
    private static function dropDumperAssets(string $html): string
    {
        return (string) \preg_replace('~<(script|style)\b.*?</\1>~si', '', $html);
    }
}
