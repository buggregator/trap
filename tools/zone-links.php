<?php

/**
 * Writes src/Module/Mailer/ZoneLinks.php from the `backward` file of tzdata.
 *
 * Usage: php tools/zone-links.php <tzdata directory>
 * The directory is an unpacked https://data.iana.org/time-zones/tzdata-latest.tar.gz with `backward` and `version`.
 */

declare(strict_types=1);

// Ahistorical System V zones `backward` defines with the US rules of today: their place with the same rules
const SYSTEM_V_ZONES = [
    'EST5EDT' => 'America/New_York',
    'CST6CDT' => 'America/Chicago',
    'MST7MDT' => 'America/Denver',
    'PST8PDT' => 'America/Los_Angeles',
];

$dir = $argv[1] ?? '' ?: throw new InvalidArgumentException('Pass the directory of the unpacked tzdata.');
$version = \trim((string) \file_get_contents("$dir/version"));
$lines = \file("$dir/backward", \FILE_IGNORE_NEW_LINES) ?: throw new RuntimeException("No $dir/backward.");

$links = [];
foreach ($lines as $line) {
    $fields = \preg_split('/\s+/', \trim((string) \preg_replace('/#.*/', '', $line)));
    if ($fields[0] === 'Link' && \count($fields) === 3) {
        $links[$fields[2]] = $fields[1];
    } elseif ($fields[0] === 'Zone' && isset(SYSTEM_V_ZONES[$fields[1] ?? ''])) {
        $links[$fields[1]] = SYSTEM_V_ZONES[$fields[1]];
    }
}

// The names of UTC and GMT: PHP lists `UTC` as its zone of no offset, and no `Etc/` zone
foreach ($links as $link => $target) {
    \in_array($target, ['Etc/UTC', 'Etc/GMT'], true) and $links[$link] = 'UTC';
}
unset($links['UTC']);

\ksort($links, \SORT_STRING);
$entries = '';
foreach ($links as $link => $target) {
    $entries .= \sprintf("        %s => %s,\n", \var_export($link, true), \var_export($target, true));
}

\file_put_contents(__DIR__ . '/../src/Module/Mailer/ZoneLinks.php', <<<PHP
    <?php

    declare(strict_types=1);

    namespace Buggregator\\Trap\\Module\\Mailer;

    /**
     * The zone names of the `backward` file of tzdata {$version}, with the zones they stand for.
     * Builds on the system tzdata, as Debian without `tzdata-legacy`, know none of these names.
     * Written by `php tools/zone-links.php <tzdata directory>`: change that script, not this file.
     *
     * @internal
     */
    final class ZoneLinks
    {
        /** @var array<non-empty-string, non-empty-string> */
        public const TARGETS = [
    {$entries}    ];
    }

    PHP);

echo \count($links), " links of tzdata $version\n";
