<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Frontend;

/**
 * Whether the web UI runs in this process. Trap runs without it unless `--ui` is given.
 *
 * A container singleton, for modules whose behavior needs a user in the UI: a Ray pause waits
 * for the UI buttons, and nothing else would ever release it.
 *
 * @internal
 */
final class UiChannel
{
    private bool $enabled = false;

    public function enable(): void
    {
        $this->enabled = true;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
