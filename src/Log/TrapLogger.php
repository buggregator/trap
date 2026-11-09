<?php

declare(strict_types=1);

\trigger_error(
    'Class `Buggregator\Trap\Log\TrapLogger` is deprecated, use `Buggregator\Trap\Client\Log\TrapLogger` instead.',
    \E_USER_DEPRECATED,
);

\class_alias(\Buggregator\Trap\Client\Log\TrapLogger::class, 'Buggregator\Trap\Log\TrapLogger');
