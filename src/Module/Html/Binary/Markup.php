<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Html\Binary;

use Buggregator\Trap\Server\Info;
use Buggregator\Trap\Support\BinaryFormat;
use Buggregator\Trap\Support\Hexdump;
use Buggregator\Trap\Support\Json;
use Buggregator\Trap\Support\Measure;

/**
 * The markup of a binary event, self-contained: the UI shows it in a sandboxed frame that loads nothing.
 *
 * The card is a static hexdump: the feed runs no scripts. The page is the same hexdump with the bytes and a viewer
 * script that replaces it; the UI runs the scripts of the event page only.
 *
 * @internal
 * @psalm-internal Buggregator\Trap\Module\Html
 */
final class Markup
{
    private const VIEWER = Info::TRAP_ROOT . '/resources/binary-viewer/';

    /**
     * Byte classes as hexyl colours them: NUL, ASCII whitespace and controls, bytes over ASCII; printable ASCII
     * has no class.
     */
    private const CLASSES = ['z', 'c', 'h', ''];

    /**
     * @param string $bytes The first bytes to show.
     * @param int<0, max> $size Of all the data.
     */
    public static function card(string $bytes, int $size, ?BinaryFormat $format): string
    {
        return '<meta charset="utf-8"><style>' . self::resource('card.css') . '</style>'
            . self::dump($bytes, $size, $format);
    }

    /**
     * @param string $bytes The bytes the viewer gets, from the start.
     * @param int<0, max> $size Of all the data.
     * @param string $download URL of all the bytes.
     * @param string $fallback The bytes shown when the viewer does not run.
     *
     * @throws \JsonException
     */
    public static function page(
        string $bytes,
        int $size,
        ?BinaryFormat $format,
        string $title,
        string $download,
        string $fallback,
    ): string {
        $meta = Json::encode([
            'title' => $title,
            'size' => $size,
            'format' => $format === null ? null : ['id' => $format->id, 'name' => $format->name, 'hint' => $format->hint],
            'download' => $download,
        ], \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT);

        return '<!doctype html><html><head><meta charset="utf-8"><title>' . self::escape($title) . '</title>'
            . '<style>' . self::resource('card.css') . self::resource('viewer.css') . '</style></head><body>'
            . '<div id="fallback">' . self::dump($fallback, $size, $format) . '</div>'
            . '<script type="application/json" id="bin-meta">' . $meta . '</script>'
            // In an attribute, not a script: the Source tab of the UI highlights a script as JavaScript,
            // which takes minutes on a long run of base64. Base64 has no quote to end the attribute.
            . '<div id="bin-data" hidden data-bytes="' . \base64_encode($bytes) . '"></div>'
            . '<script>' . self::resource('viewer.js') . '</script>'
            . '</body></html>';
    }

    /**
     * @param int<0, max> $size
     */
    private static function dump(string $bytes, int $size, ?BinaryFormat $format): string
    {
        $summary = \number_format($size) . ' bytes' . ($format === null ? '' : ' · ' . $format->name);
        $rows = '';
        foreach ($bytes === '' ? [] : \str_split($bytes, Hexdump::ROW) as $i => $chunk) {
            [$hex, $ascii] = self::row($chunk);
            $rows .= \sprintf("<span class=\"o\">%08x</span>  %s  %s\n", $i * Hexdump::ROW, $hex, $ascii);
        }
        $more = $size - \strlen($bytes);

        return '<div class="bin"><p>' . self::escape($summary) . '</p><pre>' . $rows . '</pre>'
            . ($more > 0 ? '<p>… ' . Measure::memory($more) . ' more</p>' : '') . '</div>';
    }

    /**
     * @param non-empty-string $chunk Up to {@see Hexdump::ROW} bytes.
     *
     * @return array{string, string} The hex and the ASCII column, runs of a byte class in a span of it.
     */
    private static function row(string $chunk): array
    {
        /** @var list<array{string, string, string, string}> $runs Class, hex, ASCII, gap after: a run of a byte class */
        $runs = [];
        foreach (\str_split($chunk) as $i => $char) {
            $byte = \ord($char);
            $class = self::CLASSES[match (true) {
                $byte === 0 => 0,
                $byte < 0x20 || $byte === 0x7f => 1,
                $byte > 0x7f => 2,
                default => 3,
            }];
            $gap = match ($i) {
                0 => '',
                8 => '  ',
                default => ' ',
            };
            $last = \count($runs) - 1;
            if ($last >= 0 && $runs[$last][0] === $class) {
                $runs[$last][1] .= $gap . \bin2hex($char);
                $runs[$last][2] .= $byte >= 0x20 && $byte < 0x7f ? $char : '.';
                continue;
            }
            $last >= 0 and $runs[$last][3] = $gap;
            $runs[] = [$class, \bin2hex($char), $byte >= 0x20 && $byte < 0x7f ? $char : '.', ''];
        }

        $hex = '';
        $ascii = '';
        foreach ($runs as [$class, $runHex, $runAscii, $gap]) {
            $open = $class === '' ? '' : "<span class=\"$class\">";
            $close = $class === '' ? '' : '</span>';
            $hex .= $open . $runHex . $close . $gap;
            $ascii .= $open . self::escape($runAscii) . $close;
        }

        $length = \strlen($chunk);
        $length < Hexdump::ROW and $hex .= \str_repeat(' ', (Hexdump::ROW - $length) * 3 + ($length <= 8 ? 1 : 0));

        return [$hex, $ascii];
    }

    private static function escape(string $text): string
    {
        return \htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE | \ENT_HTML5, 'UTF-8');
    }

    /**
     * @param non-empty-string $name A file of the viewer.
     */
    private static function resource(string $name): string
    {
        /** @var array<non-empty-string, string> $files */
        static $files = [];

        return $files[$name] ??= (string) \file_get_contents(self::VIEWER . $name);
    }
}
