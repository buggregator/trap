<?php

declare(strict_types=1);

namespace Buggregator\Trap\Client\Caster;

use Symfony\Component\VarDumper\Caster\Caster;
use Symfony\Component\VarDumper\Cloner\Stub;

/**
 * @internal
 */
final class TraceCaster
{
    public static function cast(Trace $tick, array $a, Stub $stub, bool $isNested): array
    {
        $stub->class = $tick->__toString();
        $stub->type = $stub::TYPE_OBJECT;
        $stub->attr = $tick->stack;
        $stub->handle = 0;

        $result = [];
        foreach ($tick->stack as $line) {
            $result[Caster::PREFIX_VIRTUAL . self::renderMethod($line)] = new TraceFile($line);
        }


        return $result;
    }

    public static function castLine(TraceFile $line, array $a, Stub $stub, bool $isNested): array
    {
        $stub->type = $stub::TYPE_REF;
        $stub->attr = $line->line;
        $stub->handle = 0;
        $stub->class = $line->__toString();
        return [];
    }

    /**
     * @param array{
     *     function: string,
     *     line?: int,
     *     file?: string,
     *     class?: class-string,
     *     type?: string,
     *     args?: list<mixed>
     * } $line The stack trace line.
     */
    private static function renderMethod(array $line): string
    {
        if (!isset($line['class'])) {
            return $line['function'] . '()';
        }

        $line['type'] ??= "::";

        return "{$line['class']}{$line['type']}{$line['function']}()";
    }
}
