<?php

namespace Elastico\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when a query exceeds the connection's slow_query_ms threshold.
 */
class SlowQuery
{
    use Dispatchable;

    public function __construct(
        public readonly string $connection,
        public readonly string $query,
        public readonly float $time_ms,
        public readonly float $threshold_ms,
    ) {}
}
