<?php

// Fixture inserted before each test that needs it — call $this->seedFixtures()
// using beforeEach would insert on every test; instead each test calls it explicitly.

beforeEach(function () {
    $this->insert([
        ['_id' => '1', 'name' => 'Alice',   'status' => 'active',   'age' => 25, 'score' => 90.0],
        ['_id' => '2', 'name' => 'Bob',     'status' => 'inactive', 'age' => 30, 'score' => 70.0],
        ['_id' => '3', 'name' => 'Charlie', 'status' => 'active',   'age' => 35, 'score' => 85.0],
        ['_id' => '4', 'name' => 'Dave',    'status' => 'pending',  'age' => 28, 'score' => 75.0],
        ['_id' => '5', 'name' => 'Eve',     'status' => 'active',   'age' => 22, 'score' => 95.0],
    ]);
});

// --- Equality ---

test('where equals filters to matching documents', function () {
    $ids = $this->builder()->where('status', 'active')->get()->pluck('_id')->sort()->values()->all();

    expect($ids)->toBe(['1', '3', '5']);
});

test('where not-equal excludes matching documents', function () {
    $ids = $this->builder()->where('status', '<>', 'active')->get()->pluck('_id')->sort()->values()->all();

    expect($ids)->toBe(['2', '4']);
});

// --- Range ---

test('where greater-than filters correctly', function () {
    $ids = $this->builder()->where('age', '>', 29)->get()->pluck('_id')->sort()->values()->all();

    expect($ids)->toBe(['2', '3']);
});

test('where greater-than-or-equal includes boundary', function () {
    $ids = $this->builder()->where('age', '>=', 30)->get()->pluck('_id')->sort()->values()->all();

    expect($ids)->toBe(['2', '3']);
});

test('where less-than filters correctly', function () {
    $ids = $this->builder()->where('age', '<', 25)->get()->pluck('_id')->sort()->values()->all();

    expect($ids)->toBe(['5']);
});

test('where less-than-or-equal includes boundary', function () {
    $ids = $this->builder()->where('age', '<=', 25)->get()->pluck('_id')->sort()->values()->all();

    expect($ids)->toBe(['1', '5']);
});

test('whereBetween returns documents within range (exclusive)', function () {
    $ids = $this->builder()->whereBetween('age', [24, 31])->get()->pluck('_id')->sort()->values()->all();

    // gt 24 and lt 31 → age 25 (Alice), 28 (Dave), 30 (Bob)
    expect($ids)->toBe(['1', '2', '4']);
});

// --- In / NotIn ---

test('whereIn returns only matching documents', function () {
    $ids = $this->builder()->whereIn('name', ['Alice', 'Eve'])->get()->pluck('_id')->sort()->values()->all();

    expect($ids)->toBe(['1', '5']);
});

test('whereNotIn excludes matching documents', function () {
    $ids = $this->builder()->whereNotIn('status', ['active'])->get()->pluck('_id')->sort()->values()->all();

    expect($ids)->toBe(['2', '4']);
});

// --- Null ---

test('whereNull matches documents without the field', function () {
    $this->insert([
        ['_id' => 'noemail',  'name' => 'Ghost', 'status' => 'active'],
        ['_id' => 'hasemail', 'name' => 'Real',  'status' => 'active', 'email' => 'r@test.com'],
    ]);

    $ids = $this->builder()->whereNull('email')->get()->pluck('_id')->all();

    expect($ids)->toContain('noemail')
        ->and($ids)->not->toContain('hasemail');
});

test('whereNotNull matches only documents that have the field', function () {
    $this->insert([
        ['_id' => 'hasemail', 'name' => 'Real', 'status' => 'active', 'email' => 'real@test.com'],
        ['_id' => 'noemail2', 'name' => 'Ghost2', 'status' => 'active'],
    ]);

    $ids = $this->builder()->whereNotNull('email')->get()->pluck('_id')->all();

    expect($ids)->toContain('hasemail')
        ->and($ids)->not->toContain('noemail2');
});

// --- Sorting ---

test('orderBy ascending returns documents in correct order', function () {
    $names = $this->builder()->orderBy('age', 'asc')->get()->map(fn($h) => $h['_source']['name'])->all();

    expect($names)->toBe(['Eve', 'Alice', 'Dave', 'Bob', 'Charlie']);
});

test('orderBy descending returns documents in correct order', function () {
    $names = $this->builder()->orderBy('age', 'desc')->get()->map(fn($h) => $h['_source']['name'])->all();

    expect($names)->toBe(['Charlie', 'Bob', 'Dave', 'Alice', 'Eve']);
});

// --- Pagination ---

test('limit restricts the number of returned documents', function () {
    $results = $this->builder()->limit(2)->get();

    expect($results)->toHaveCount(2);
    expect($results->total())->toBe(5);
});

test('offset skips documents', function () {
    $all = $this->builder()->orderBy('age', 'asc')->get()->pluck('_id')->all();
    $paged = $this->builder()->orderBy('age', 'asc')->offset(2)->get()->pluck('_id')->all();

    expect($paged)->toBe(array_slice($all, 2));
});

// --- OR conditions ---

test('orWhere returns union of matching documents', function () {
    $ids = $this->builder()
        ->where('status', 'inactive')
        ->orWhere('status', 'pending')
        ->get()
        ->pluck('_id')->sort()->values()->all();

    expect($ids)->toBe(['2', '4']);
});

// --- Multiple AND conditions ---

test('chained where conditions are AND-ed', function () {
    $results = $this->builder()
        ->where('status', 'active')
        ->where('age', '<', 30)
        ->get();

    $ids = $results->pluck('_id')->sort()->values()->all();
    expect($ids)->toBe(['1', '5']);
});
