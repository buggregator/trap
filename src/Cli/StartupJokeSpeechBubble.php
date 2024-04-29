<?php

declare(strict_types=1);

namespace Buggregator\Trap\Cli;

class StartupJokeSpeechBubble
{
    public const JOKES = [
        'Why do programmers always mix up Halloween and Christmas? Because Oct 31 == Dec 25.',
        'Two hard things in computer science: cache invalidation, naming things and stack overflow.',
        'Depressive programming style through dump and die.',
        'PHP was dead 84 years ago right?',
    ];

    public static function randomStartupJokeSpeechBubble(): string
    {
        $joke = self::JOKES[mt_rand(0, \count(self::JOKES) - 1)];
        $jokeLen = max(\strlen($joke), 13);
        $speechBubbleLeftSpace = str_repeat(' ', 14);

        $lines = [];
        $lines[] = $speechBubbleLeftSpace . '⠘⡀⠀⠀' . str_repeat(' ', $jokeLen) . '⠀⠀ ⡜';
        $lines[] = $speechBubbleLeftSpace . ' ⠘⡀⠀' . str_pad($joke, $jokeLen, ' ', STR_PAD_BOTH) . '⠀ ⡜';
        $lines[] = $speechBubbleLeftSpace . '  ⠑⡀' . str_repeat(' ', $jokeLen) . '⡔⠁';
        $lines[] = $speechBubbleLeftSpace . '   ⢸    ' . str_repeat('⣀', $jokeLen - 13) . '⣀⣀⣀⣀⡀⠤⠄⠒⠈';
        $lines[] = $speechBubbleLeftSpace . '   ⠘⣀⠄⠊⠁';

        return "\n" . implode(chr(10), $lines) .  "\n";
    }
}
