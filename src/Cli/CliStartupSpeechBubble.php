<?php

declare(strict_types=1);

namespace Buggregator\Trap\Cli;

class CliStartupSpeechBubble
{
    public const LOGO = <<<CONSOLE
              ▄█▀                  ▀█▄
             ▐█▌      ▄█▀▀▀▀█▄      ▐█▌
             ██   ▀▄ █        █ ▄▀   ██
            ▐█▌     █▀▀▀▀▀▀▀▀▀▀█     ▐█▌
           ▄█▀     ▐▌  ▀▄  ▄▀  ▐▌     ▀█▄
           ▀█▄   ▄▄█     ██     █▄▄   ▄█▀
            ▐█▌     █  ▄▀  ▀▄  █     ▐█▌
             ██      █▄      ▄█      ██
             ▐█▌   ▄▀  ▀▄▄▄▄▀  ▀▄   ▐█▌
              ▀█▄                  ▄█▀

        CONSOLE;

    public const JOKES = [
        'Why do programmers always mix up Halloween and Christmas? Because Oct 31 == Dec 25.',
        'Two hard things in computer science: cache invalidation, naming things and stack overflow.',
        'Depressive programming style through dump and die.',
        'PHP was dead 84 years ago right?',
        'Submit a pull request to help us improve the Buggregator Trap codebase',
    ];

    public static function getStartupSpeechBubble(): string
    {
        $result = '';

        $jokeLines = self::getJokeLines();
        $maxJokeLineLen = \max(\array_map('\mb_strlen', $jokeLines)) + 2;

        foreach ($jokeLines as $line) {
            $rightSpaces = \str_repeat(' ', $maxJokeLineLen - \mb_strlen($line));
            $result .= "\e[44;97;1m" . $line .  $rightSpaces .  "\e[0m\n";
        }

        $logoLines = \explode("\n", self::LOGO);

        foreach ($logoLines as $line) {
            $rightSpaces = \str_repeat(' ', $maxJokeLineLen - \mb_strlen($line));
            $result .= "\e[44;97;1m" . $line .  $rightSpaces .  "\e[0m\n";
        }

        return $result;
    }

    /**
     * @return non-empty-array<string>
     */
    private static function getJokeLines(): array
    {
        $joke = self::JOKES[\mt_rand(0, \count(self::JOKES) - 1)];
        $jokeLen = \max(\strlen($joke), 13);
        $speechBubbleLeftSpace = \str_repeat(' ', 14);

        $lines = [];
        $lines[] = $speechBubbleLeftSpace.'⠘⡀⠀⠀'.\str_repeat(' ', $jokeLen).'⠀⠀ ⡜';
        $lines[] = $speechBubbleLeftSpace.' ⠘⡀⠀'.\str_pad($joke, $jokeLen, ' ', STR_PAD_BOTH).'⠀ ⡜';
        $lines[] = $speechBubbleLeftSpace.'  ⠑⡀'.\str_repeat(' ', $jokeLen).'⡔⠁';
        $lines[] = $speechBubbleLeftSpace.'   ⢸    '.\str_repeat('⣀', $jokeLen - 13).'⣀⣀⣀⣀⡀⠤⠄⠒⠈';
        $lines[] = $speechBubbleLeftSpace.'   ⠘⣀⠄⠊⠁';

        return $lines;
    }
}
