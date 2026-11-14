<?php

declare(strict_types=1);

function handle(string $uri): string
{
    return \strtoupper($uri);
}

// SPX escapes `\t`, but writes `\x01` raw, which JSON doesn't allow
spx_profiler_full_report_set_custom_metadata_str("release=1.2.3\tname=Café\x01");
echo handle($_SERVER['REQUEST_URI']);
