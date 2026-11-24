<?php

/**
 * Writes src/Module/Mailer/ZoneLinks.php from the `backward` file of tzdata: the obsolete names only,
 * and tests/Stub/tzdata/zone-tab.txt, the current names the tests check the map against.
 *
 * Usage: php tools/zone-links.php <tzdata directory>
 * The directory is an unpacked https://data.iana.org/time-zones/tzdata-latest.tar.gz
 * with `backward`, `backzone`, `zone.tab` and `version`.
 */

declare(strict_types=1);

// Ahistorical System V zones `backward` defines with the US rules of today: their place with the same rules
const SYSTEM_V_ZONES = [
    'EST5EDT' => 'America/New_York',
    'CST6CDT' => 'America/Chicago',
    'MST7MDT' => 'America/Denver',
    'PST8PDT' => 'America/Los_Angeles',
];

$read = static fn(string $file): array => \file("{$argv[1]}/$file", \FILE_IGNORE_NEW_LINES)
    ?: throw new \RuntimeException("No $file in {$argv[1]}.");
($argv[1] ?? '') !== '' or throw new \InvalidArgumentException('Pass the directory of the unpacked tzdata.');
$version = \trim($read('version')[0] ?? '') ?: throw new \RuntimeException('The version file is empty.');

// `backward` also links zones merged since 2022 that are still the zones of their countries, as `Europe/Oslo`
// to `Europe/Berlin`: `zone.tab` lists every such current name, `zone1970.tab` does not
$current = [];
foreach ($read('zone.tab') as $line) {
    $fields = \explode("\t", $line);
    $line === '' || $line[0] === '#' || !isset($fields[2]) or $current[$fields[2]] = true;
}
isset($current['Europe/Oslo'], $current['Asia/Kolkata']) or throw new \RuntimeException('zone.tab misses current zones.');

/**
 * The fields of a data line without its comment, and the comment.
 *
 * @return array{list<string>, string}
 */
$parse = static function (string $line): array {
    [$data, $comment] = \explode('#', $line, 2) + [1 => ''];
    $data = \trim($data);
    return [$data === '' ? [] : \preg_split('/\s+/', $data), \trim($comment)];
};

// A link `backward` sends to the zone of another country with the same clocks, as `Iceland` to `Africa/Abidjan`:
// the zone of its own country is the `#= ` hint of `backward` or the link of `backzone`, when that zone is current
$backzone = [];
foreach ($read('backzone') as $line) {
    if (\preg_match('/^(?:#PACKRATLIST\s+\S+\s+)?Link\b/', $line) !== 1) {
        continue;
    }

    \preg_match('/^(?:#PACKRATLIST\s+zone\.tab\s+)?Link\s+(\S+)\s+(\S+)\s*(?:#.*)?$/', $line, $m) === 1
        or throw new \RuntimeException("A link of an unexpected shape in `backzone`: $line");
    $backzone[$m[2]] = $m[1];
}

$ownZones = [];
foreach ($backzone as $name => $target) {
    if (isset($current[$target])) {
        $ownZones[$name] = $target;
        continue;
    }

    // A chain to a current zone is not followed: a new tzdata that needs one stops the script
    isset($backzone[$target]) && isset($current[$backzone[$target]]) and throw new \RuntimeException(
        "`backzone` links `$name` to `$target`, a link to the current zone `{$backzone[$target]}`.",
    );
}

$links = [];
foreach ($read('backward') as $line) {
    [$fields, $comment] = $parse($line);
    if ($fields === []) {
        continue;
    }

    if ($fields[0] === 'Link') {
        \count($fields) === 3 or throw new \RuntimeException("A malformed link in `backward`: $line");
        [, $target, $name] = $fields;
        if (isset($current[$name])) {
            continue;
        }

        $hint = \preg_match('/^=\s*(\S+)/', $comment, $m) === 1 && isset($current[$m[1]]) ? $m[1] : null;
        $links[$name] = $hint ?? $ownZones[$name] ?? $target;
    } elseif ($fields[0] === 'Zone') {
        $links[$fields[1]] = SYSTEM_V_ZONES[$fields[1]]
            ?? throw new \RuntimeException("`backward` has a zone `{$fields[1]}` with no place in SYSTEM_V_ZONES.");
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
     * The obsolete zone names of the `backward` file of tzdata {$version}, with the zones they stand for;
     * the current names of `zone.tab` stay out. Builds on the system tzdata, as Debian without `tzdata-legacy`,
     * know none of these names.
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

$names = \array_keys($current);
\sort($names, \SORT_STRING);
@\mkdir(__DIR__ . '/../tests/Stub/tzdata');
\file_put_contents(
    __DIR__ . '/../tests/Stub/tzdata/zone-tab.txt',
    "# The zone names of zone.tab of tzdata $version, written by tools/zone-links.php\n" . \implode("\n", $names) . "\n",
);

echo \count($links), " links and ", \count($names), " current zones of tzdata $version\n";
