<?php

use Elastico\Exceptions\BulkException;

function bulkResponse(array $items): array
{
    return ['took' => 5, 'errors' => true, 'items' => $items];
}

test('fromResponse collects only failed items with bounded reasons', function () {
    $exception = BulkException::fromResponse(bulkResponse([
        ['update' => ['_id' => 'a', 'status' => 200, 'result' => 'updated']],
        ['update' => ['_id' => 'b', 'status' => 409, 'error' => ['type' => 'version_conflict_engine_exception', 'reason' => str_repeat('x', 2000)]]],
        ['create' => ['_id' => 'c', 'status' => 400, 'error' => ['type' => 'mapper_parsing_exception', 'reason' => 'bad field']]],
    ]));

    expect($exception->totalItems)->toBe(3)
        ->and($exception->failedCount())->toBe(2)
        ->and($exception->statusCounts())->toBe([409 => 1, 400 => 1])
        ->and($exception->errorTypes())->toBe(['version_conflict_engine_exception' => 1, 'mapper_parsing_exception' => 1])
        ->and(strlen($exception->failedItems[0]['reason']))->toBeLessThanOrEqual(500)
        ->and($exception->getMessage())->toContain('2 of 3 items errored')
        ->and(strlen($exception->getMessage()))->toBeLessThan(1000);
});

test('onlyConflicts is true iff every failure is a version conflict', function () {
    $conflictsOnly = BulkException::fromResponse(bulkResponse([
        ['update' => ['_id' => 'a', 'status' => 409, 'error' => ['type' => 'version_conflict_engine_exception', 'reason' => 'seq_no']]],
        ['update' => ['_id' => 'b', 'status' => 409, 'error' => ['type' => 'version_conflict_engine_exception', 'reason' => 'seq_no']]],
    ]));

    $mixed = BulkException::fromResponse(bulkResponse([
        ['update' => ['_id' => 'a', 'status' => 409, 'error' => ['type' => 'version_conflict_engine_exception', 'reason' => 'seq_no']]],
        ['create' => ['_id' => 'b', 'status' => 400, 'error' => ['type' => 'mapper_parsing_exception', 'reason' => 'bad']]],
    ]));

    expect($conflictsOnly->onlyConflicts())->toBeTrue()
        ->and($mixed->onlyConflicts())->toBeFalse()
        ->and(BulkException::fromResponse(bulkResponse([]))->onlyConflicts())->toBeFalse();
});

test('firstErrors caps the returned items', function () {
    $items = array_map(
        fn (int $i) => ['index' => ['_id' => (string) $i, 'status' => 400, 'error' => ['type' => 't', 'reason' => 'r']]],
        range(1, 20)
    );

    expect(BulkException::fromResponse(bulkResponse($items))->firstErrors(5))->toHaveCount(5);
});
