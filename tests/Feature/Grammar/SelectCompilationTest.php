<?php

use Elastico\Aggregations\Metric\Avg;

test('buildPayload includes index and basic structure', function () {
    $builder = makeBuilder()->where('status', 'active');
    $result = $builder->grammar->compileSelect($builder);

    expect($result)->toHaveKey('index', 'test_index');
    expect($result)->toHaveKey('seq_no_primary_term', true);
    expect($result['body'])->toHaveKey('query');
});

test('buildPayload omits null body fields', function () {
    $builder = makeBuilder();
    $result = $builder->grammar->compileSelect($builder);

    // No where, no limit, no offset → body has no query/from/size
    expect($result['body'])->not->toHaveKey('query');
    expect($result['body'])->not->toHaveKey('from');
    expect($result['body'])->not->toHaveKey('size');
});

test('buildPayload includes size when limit is set', function () {
    $builder = makeBuilder()->limit(25);
    $result = $builder->grammar->compileSelect($builder);

    expect($result['body'])->toHaveKey('size', 25);
});

test('buildPayload includes from when offset is set', function () {
    $builder = makeBuilder()->offset(100);
    $result = $builder->grammar->compileSelect($builder);

    expect($result['body'])->toHaveKey('from', 100);
});

test('buildPayload includes ignore_unavailable only when requested', function () {
    $default = makeBuilder();
    expect($default->grammar->compileSelect($default))->not->toHaveKey('ignore_unavailable');

    $builder = makeBuilder()->ignoreUnavailable();
    expect($builder->grammar->compileSelect($builder))->toHaveKey('ignore_unavailable', true);
});

test('buildPayload includes sort when orderBy is set', function () {
    $builder = makeBuilder()->orderBy('created_at', 'desc');
    $result = $builder->grammar->compileSelect($builder);

    expect($result['body'])->toHaveKey('sort');
    expect($result['body']['sort'])->toBe([
        ['created_at' => ['order' => 'desc']],
    ]);
});

test('buildPayload includes aggs when aggregations are set', function () {
    $builder = makeBuilder()->addAggregation('avg_price', new Avg('price'));
    $result = $builder->grammar->compileSelect($builder);

    expect($result['body'])->toHaveKey('aggs');
    expect($result['body']['aggs'])->toHaveKey('avg_price');
    expect($result['body']['aggs']['avg_price'])->toBe(['avg' => ['field' => 'price']]);
});

test('buildPayload includes _source includes when columns are selected', function () {
    $builder = makeBuilder()->select(['name', 'email']);
    $result = $builder->grammar->compileSelect($builder);

    expect($result['body']['_source']['includes'])->toBe(['name', 'email']);
});

test('buildPayload includes collapse when set', function () {
    $builder = makeBuilder()->collapse('user_id');
    $result = $builder->grammar->compileSelect($builder);

    expect($result['body'])->toHaveKey('collapse', ['field' => 'user_id']);
});

test('compileCount strips sort and aggs from payload', function () {
    $builder = makeBuilder()->where('status', 'active')->orderBy('name')->addAggregation('total', new Avg('price'));
    $result = $builder->grammar->compileCount($builder);

    expect($result['body'])->not->toHaveKey('sort');
    expect($result['body'])->not->toHaveKey('aggs');
    expect($result['body'])->toHaveKey('query');
});

test('compileCount strips collapse, which the _count API rejects', function () {
    $builder = makeBuilder()->where('status', 'active')->collapse('user_id');
    $result = $builder->grammar->compileCount($builder);

    expect($result['body'])->not->toHaveKey('collapse');
    expect($result['body'])->toHaveKey('query');
});

test('compileFindMany builds correct mget body', function () {
    $builder = makeBuilder();
    $result = $builder->grammar->compileFindMany('my_index', ['abc', 'def'], ['*']);

    expect($result['body']['docs'])->toBe([
        ['_index' => 'my_index', '_id' => 'abc', '_source' => ['*']],
        ['_index' => 'my_index', '_id' => 'def', '_source' => ['*']],
    ]);
});
