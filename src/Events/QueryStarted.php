<?php

namespace Elastico\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired before every Elasticsearch round-trip. Pair with QueryExecuted
 * via the shared query_identifier to build spans.
 */
class QueryStarted
{
    use Dispatchable;

    public function __construct(
        public readonly string $query_identifier,
        public readonly string $query_name,
        public readonly string $method,
        public readonly null|string|array $index = null,
    ) {}
}
