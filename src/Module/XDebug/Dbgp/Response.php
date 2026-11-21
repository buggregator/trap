<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\XDebug\Dbgp;

/**
 * A parsed engine packet: `response`, `notify` or `init`.
 *
 * @psalm-type Variable = array{
 *     name: string, fullname?: string, type: string, class?: string, facet?: string, value?: string,
 *     size?: int, numchildren?: int, page?: int, pagesize?: int, children?: list<array>
 * }
 *
 * @internal
 */
final class Response
{
    private const XDEBUG_NS = 'https://xdebug.org/dbgp/xdebug';

    private function __construct(
        public readonly \SimpleXMLElement $xml,
    ) {}

    /**
     * Xdebug declares ISO-8859-1 and sends the bytes PHP has, UTF-8 as a rule: read as UTF-8 first.
     *
     * @throws ProtocolError
     */
    public static function parse(string $body): self
    {
        $previous = \libxml_use_internal_errors(true);
        try {
            $utf8 = (string) \preg_replace('/^(<\?xml[^>]*encoding=)"[^"]*"/', '$1"UTF-8"', $body, 1);
            $xml = \simplexml_load_string($utf8, options: \LIBXML_NONET);
            $xml === false and $xml = \simplexml_load_string($body, options: \LIBXML_NONET);
            \libxml_clear_errors();
        } finally {
            \libxml_use_internal_errors($previous);
        }
        $xml === false and throw new ProtocolError('Unreadable XML from Xdebug.');

        return new self($xml);
    }

    public function attr(string $name): string
    {
        return (string) ($this->xml->attributes()[$name] ?? '');
    }

    /**
     * @throws EngineError When Xdebug answered with an error.
     */
    public function assertOk(): self
    {
        $error = $this->xml->error;
        if ($error->count() > 0) {
            throw new EngineError((string) $error['code'], \trim((string) $error->message));
        }

        return $this;
    }

    /**
     * Where a `break` reply stopped, from `<xdebug:message filename lineno>`.
     *
     * @return array{string, int}|null
     */
    public function breakLocation(): ?array
    {
        $message = $this->xml->children(self::XDEBUG_NS)?->message;
        if ($message === null || $message->count() === 0) {
            return null;
        }
        // Not `[$name]`: an element of a namespace looks for attributes of that namespace
        $attributes = $message->attributes();
        $file = (string) ($attributes?->filename ?? '');

        return $file === '' ? null : [$file, (int) ($attributes?->lineno ?? 0)];
    }

    /**
     * @return list<array{level: int, where: string, file: string, line: int, type: string}>
     */
    public function stack(): array
    {
        $frames = [];
        foreach ($this->xml->stack as $frame) {
            $frames[] = [
                'level' => (int) $frame['level'],
                'where' => (string) $frame['where'],
                'file' => (string) $frame['filename'],
                'line' => (int) $frame['lineno'],
                'type' => (string) $frame['type'],
            ];
        }

        return $frames;
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function contexts(): array
    {
        $contexts = [];
        foreach ($this->xml->context as $context) {
            $contexts[] = ['id' => (int) $context['id'], 'name' => (string) $context['name']];
        }

        return $contexts;
    }

    /**
     * @return list<Variable>
     */
    public function properties(): array
    {
        $vars = [];
        foreach ($this->xml->property as $property) {
            $vars[] = self::variable($property);
        }

        return $vars;
    }

    /**
     * The text of the reply, as `source` gives it.
     */
    public function text(): string
    {
        return self::decode((string) $this->xml, $this->attr('encoding'));
    }

    /**
     * @return list<array{id: string, type: string, state: string, file: string, line: int, function: string, hit_count: int, resolved: string}>
     */
    public function breakpoints(): array
    {
        $list = [];
        foreach ($this->xml->breakpoint as $bp) {
            $list[] = [
                'id' => (string) $bp['id'],
                'type' => (string) $bp['type'],
                'state' => (string) $bp['state'],
                'file' => (string) $bp['filename'],
                'line' => (int) $bp['lineno'],
                'function' => (string) $bp['function'],
                'hit_count' => (int) $bp['hit_count'],
                'resolved' => (string) $bp['resolved'],
            ];
        }

        return $list;
    }

    /**
     * @return Variable
     */
    private static function variable(\SimpleXMLElement $p): array
    {
        $var = ['name' => (string) $p['name']];
        $fullname = (string) $p['fullname'];
        $class = (string) $p['classname'];
        // `extended_properties` moves names and values into child elements
        $p->name->count() > 0 and $var['name'] = self::decode((string) $p->name, (string) $p->name['encoding']);
        $p->fullname->count() > 0 and $fullname = self::decode((string) $p->fullname, (string) $p->fullname['encoding']);
        $p->classname->count() > 0 and $class = self::decode((string) $p->classname, (string) $p->classname['encoding']);
        $fullname === '' or $var['fullname'] = $fullname;
        $type = (string) $p['type'];
        $var['type'] = $type;
        $class === '' or $var['class'] = $class;
        (string) $p['facet'] === '' or $var['facet'] = (string) $p['facet'];

        $numChildren = (int) $p['numchildren'];
        if ($p->property->count() > 0 || $numChildren > 0) {
            $numChildren > 0 and $var['numchildren'] = $numChildren;
            isset($p['page']) and $var['page'] = (int) $p['page'];
            isset($p['pagesize']) and $var['pagesize'] = (int) $p['pagesize'];
            $children = [];
            foreach ($p->property as $child) {
                $children[] = self::variable($child);
            }
            $children === [] or $var['children'] = $children;
        } elseif ($type !== 'null' && $type !== 'uninitialized') {
            $var['value'] = $p->value->count() > 0
                ? self::decode((string) $p->value, (string) $p->value['encoding'])
                : self::decode((string) $p, (string) $p['encoding']);
        }

        // Only worth showing when `max_data` cut the value
        $size = (int) $p['size'];
        $size > 0 && $size !== \strlen($var['value'] ?? '') and $var['size'] = $size;

        return $var;
    }

    private static function decode(string $text, string $encoding): string
    {
        if ($encoding === 'base64') {
            $decoded = \base64_decode(\trim($text), true);
            return $decoded === false ? $text : $decoded;
        }

        return $text;
    }
}
