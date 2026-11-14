<?php

declare(strict_types=1);

function work(): int
{
    return \strlen('work');
}

function cleanup(): void
{
    work();
}

\register_shutdown_function(static function (): void {
    cleanup();
});
work();
