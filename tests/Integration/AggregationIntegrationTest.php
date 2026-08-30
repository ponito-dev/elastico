<?php

use Elastico\Aggregations\Metric\Avg;
use Elastico\Aggregations\Metric\Sum;
use Elastico\Aggregations\Metric\Min;
use Elastico\Aggregations\Metric\Max;
use Elastico\Aggregations\Metric\Cardinality;
use Elastico\Aggregations\Bucket\Terms as TermsBucket;

beforeEach(function () {
    $this->insert([
        ['_id' => '1', 'status' => 'active',   'price' => 10.0, 'score' => 90.0],
        ['_id' => '2', 'status' => 'inactive', 'price' => 20.0, 'score' => 70.0],
        ['_id' => '3', 'status' => 'active',   'price' => 30.0, 'score' => 80.0],
        ['_id' => '4', 'status' => 'pending',  'price' => 40.0, 'score' => 60.0],
        ['_id' => '5', 'status' => 'active',   'price' => 50.0, 'score' => 100.0],
    ]);
});

// --- Metric aggregations ---

test('avg aggregation returns correct average', function () {
    $result = $this->builder()
        ->addAggregation('avg_price', new Avg('price'))
        ->limit(0)
        ->get()
        ->aggregation('avg_price')
        ->value();

    expect($result)->toBe(30.0);
});

test('sum aggregation returns correct sum', function () {
    $result = $this->builder()
        ->addAggregation('total', new Sum('price'))
        ->limit(0)
        ->get()
        ->aggregation('total')
        ->value();

    expect($result)->toBe(150.0);
});

test('min aggregation returns the smallest value', function () {
    $result = $this->builder()
        ->addAggregation('lowest', new Min('price'))
        ->limit(0)
        ->get()
        ->aggregation('lowest')
        ->value();

    expect($result)->toBe(10.0);
});

test('max aggregation returns the largest value', function () {
    $result = $this->builder()
        ->addAggregation('highest', new Max('price'))
        ->limit(0)
        ->get()
        ->aggregation('highest')
        ->value();

    expect($result)->toBe(50.0);
});

test('cardinality aggregation counts distinct values', function () {
    $result = $this->builder()
        ->addAggregation('unique_statuses', new Cardinality('status'))
        ->limit(0)
        ->get()
        ->aggregation('unique_statuses')
        ->value();

    expect($result)->toBe(3); // active, inactive, pending
});

// --- Bucket aggregations ---

test('terms bucket aggregation returns correct buckets', function () {
    $agg = $this->builder()
        ->addAggregation('by_status', new TermsBucket('status', size: 10))
        ->limit(0)
        ->get()
        ->aggregation('by_status');

    $buckets = collect($agg->buckets())
        ->keyBy(fn($b) => $b['key'])
        ->map(fn($b) => $b['doc_count'])
        ->all();

    expect($buckets)->toBe([
        'active'   => 3,
        'inactive' => 1,
        'pending'  => 1,
    ]);
});

test('terms bucket with filter returns buckets for matching subset', function () {
    $agg = $this->builder()
        ->where('price', '>', 15.0)
        ->addAggregation('by_status', new TermsBucket('status', size: 10))
        ->limit(0)
        ->get()
        ->aggregation('by_status');

    $total = collect($agg->buckets())->sum(fn($b) => $b['doc_count']);

    expect($total)->toBe(4); // docs 2,3,4,5
});

// --- Aggregation on a scoped query ---

test('avg aggregation respects where filters', function () {
    $result = $this->builder()
        ->where('status', 'active')
        ->addAggregation('avg_price', new Avg('price'))
        ->limit(0)
        ->get()
        ->aggregation('avg_price')
        ->value();

    // active docs: prices 10, 30, 50 → avg = 30
    expect($result)->toBe(30.0);
});
