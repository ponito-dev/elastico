<?php

namespace Elastico\Exceptions;

use Exception;

/**
 * A bulk write with item-level failures. Carries the failed items in
 * structured form; the message stays bounded no matter how large the
 * bulk was — a one-item failure in a 2000-doc batch must not put the
 * whole response into logs and failed_jobs.
 */
class BulkException extends Exception
{
    /**
     * @param array<int, array{operation: ?string, _id: ?string, status: ?int, type: ?string, reason: ?string}> $failedItems
     */
    public function __construct(
        public readonly array $failedItems = [],
        public readonly int $totalItems = 0,
    ) {
        parent::__construct($this->buildMessage());
    }

    public static function fromResponse(array $response): static
    {
        $failed = [];

        foreach ($response['items'] ?? [] as $item) {
            $operation = array_key_first($item);
            $result = $item[$operation] ?? [];

            if (!empty($result['error'])) {
                $failed[] = [
                    'operation' => $operation,
                    '_id' => $result['_id'] ?? null,
                    'status' => $result['status'] ?? null,
                    'type' => $result['error']['type'] ?? null,
                    'reason' => isset($result['error']['reason']) ? mb_substr($result['error']['reason'], 0, 500) : null,
                ];
            }
        }

        return new static($failed, count($response['items'] ?? []));
    }

    public function failedCount(): int
    {
        return count($this->failedItems);
    }

    /** @return array<int, int> status code => count */
    public function statusCounts(): array
    {
        return array_count_values(array_filter(array_column($this->failedItems, 'status')));
    }

    /** @return array<string, int> error type => count */
    public function errorTypes(): array
    {
        return array_count_values(array_filter(array_column($this->failedItems, 'type')));
    }

    public function firstErrors(int $count = 5): array
    {
        return array_slice($this->failedItems, 0, $count);
    }

    public function onlyConflicts(): bool
    {
        return [] !== $this->failedItems
            && ['version_conflict_engine_exception'] === array_keys($this->errorTypes());
    }

    protected function buildMessage(): string
    {
        $statuses = collect($this->statusCounts())
            ->map(fn (int $count, int $status): string => "{$status}×{$count}")
            ->implode(', ');

        $first = $this->failedItems[0] ?? null;

        return sprintf(
            'Bulk write failed: %d of %d items errored%s.%s',
            $this->failedCount(),
            $this->totalItems,
            $statuses ? " (statuses: {$statuses})" : '',
            $first ? sprintf(
                ' First: [%s] %s on _id %s: %s',
                $first['type'] ?? 'unknown',
                $first['operation'] ?? 'op',
                $first['_id'] ?? '?',
                mb_substr($first['reason'] ?? '', 0, 200)
            ) : ''
        );
    }
}
