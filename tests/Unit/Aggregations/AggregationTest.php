<?php

use Elastico\Aggregations\Metric\Avg;
use Elastico\Aggregations\Metric\Sum;
use Elastico\Aggregations\Metric\Min;
use Elastico\Aggregations\Metric\Max;
use Elastico\Aggregations\Metric\Cardinality;
use Elastico\Aggregations\Metric\ValueCount;
use Elastico\Aggregations\Bucket\Terms as TermsBucket;
use Elastico\Aggregations\Bucket\AutoDateHistogram;
use Elastico\Aggregations\Bucket\DateHistogram;
use Elastico\Aggregations\Bucket\Histogram;

// --- Metric aggregations ---

test('avg aggregation compiles correctly', function () {
    expect((new Avg('price'))->compile())->toBe([
        'avg' => ['field' => 'price'],
    ]);
});

test('sum aggregation compiles correctly', function () {
    expect((new Sum('revenue'))->compile())->toBe([
        'sum' => ['field' => 'revenue'],
    ]);
});

test('min aggregation compiles correctly', function () {
    expect((new Min('price'))->compile())->toBe([
        'min' => ['field' => 'price'],
    ]);
});

test('max aggregation compiles correctly', function () {
    expect((new Max('price'))->compile())->toBe([
        'max' => ['field' => 'price'],
    ]);
});

test('cardinality aggregation compiles correctly', function () {
    expect((new Cardinality('user_id'))->compile())->toBe([
        'cardinality' => ['field' => 'user_id'],
    ]);
});

test('value_count aggregation compiles correctly', function () {
    expect((new ValueCount('id'))->compile())->toBe([
        'value_count' => ['field' => 'id'],
    ]);
});

// --- Bucket aggregations ---

test('terms bucket aggregation compiles with default size', function () {
    expect((new TermsBucket('category'))->compile())->toBe([
        'terms' => ['field' => 'category', 'size' => 10],
    ]);
});

test('terms bucket aggregation respects custom size', function () {
    expect((new TermsBucket('category', size: 50))->compile())->toBe([
        'terms' => ['field' => 'category', 'size' => 50],
    ]);
});

test('terms bucket aggregation includes min_doc_count when set', function () {
    $compiled = (new TermsBucket('category', min_doc_count: 5))->compile();

    expect($compiled['terms'])->toHaveKey('min_doc_count', 5);
});

test('terms bucket aggregation includes missing when set', function () {
    $compiled = (new TermsBucket('category', missing: 'unknown'))->compile();

    expect($compiled['terms'])->toHaveKey('missing', 'unknown');
});

test('auto date histogram includes time_zone only when set', function () {
    expect((new AutoDateHistogram('@timestamp', 50))->compile()['auto_date_histogram'])
        ->not->toHaveKey('time_zone');

    expect((new AutoDateHistogram('@timestamp', 50, time_zone: 'Europe/Zurich'))->compile()['auto_date_histogram'])
        ->toHaveKey('time_zone', 'Europe/Zurich');
});

test('terms bucket aggregation includes order when set', function () {
    $compiled = (new TermsBucket('category', order: ['last_seen' => 'desc']))->compile();

    expect($compiled['terms'])->toHaveKey('order', ['last_seen' => 'desc']);

    $fluent = TermsBucket::make(field: 'category')->order(['_key' => 'asc'])->compile();

    expect($fluent['terms'])->toHaveKey('order', ['_key' => 'asc']);
});

test('terms bucket aggregation includes nested sub-aggregation', function () {
    $agg = (new TermsBucket('category'))
        ->addAggregation('total', new Sum('price'));

    $compiled = $agg->compile();

    expect($compiled)->toHaveKey('aggs');
    expect($compiled['aggs'])->toHaveKey('total');
    expect($compiled['aggs']['total'])->toBe(['sum' => ['field' => 'price']]);
});

test('aggregation with no sub-aggregations omits aggs key', function () {
    $compiled = (new TermsBucket('category'))->compile();

    expect($compiled)->not->toHaveKey('aggs');
});

// --- Composite aggregation ---

test('composite aggregation compiles correctly', function () {
    $agg = \Elastico\Aggregations\Bucket\Composite::terms('category', 'product.category', size: 500);

    expect($agg->compile())->toBe([
        'composite' => [
            'sources' => [['category' => ['terms' => ['field' => 'product.category']]]],
            'size' => 500,
        ],
    ]);
});

test('composite aggregation includes after key when set', function () {
    $agg = \Elastico\Aggregations\Bucket\Composite::terms('category', 'product.category')
        ->after(['category' => 'Shoes']);

    expect($agg->compile()['composite']['after'])->toBe(['category' => 'Shoes']);
});

test('composite response exposes after_key and treats a missing key as end-of-stream', function () {
    $agg = \Elastico\Aggregations\Bucket\Composite::terms('category', 'product.category');

    $page = $agg->toResponse(['buckets' => [['key' => ['category' => 'Shoes'], 'doc_count' => 3]], 'after_key' => ['category' => 'Shoes']]);
    $lastPage = $agg->toResponse(['buckets' => []]);

    expect($page)->toBeInstanceOf(\Elastico\Query\Response\Aggregation\CompositeResponse::class)
        ->and($page->afterKey())->toBe(['category' => 'Shoes'])
        ->and($lastPage->afterKey())->toBeNull();
});
