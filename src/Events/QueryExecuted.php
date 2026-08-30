<?php

namespace Elastico\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired after every Elasticsearch round-trip — searches, cursor pages,
 * point-in-time management, bulk writes — with server-side metadata.
 * Complements Illuminate's QueryExecuted (still dispatched via logQuery),
 * which only carries the truncated DSL and wall time.
 */
class QueryExecuted
{
    use Dispatchable;

    public function __construct(
        public readonly string $query_identifier,
        public readonly string $query_name,
        public readonly string $method,
        public readonly null|string|array $index,
        public readonly float $wall_ms,
        public readonly ?int $took_ms = null,
        public readonly ?int $status_code = null,
        public readonly ?int $affected_docs = null,
        public readonly ?bool $timed_out = null,
        public readonly ?array $shards = null,
        public readonly ?string $error = null,
    ) {}
}
