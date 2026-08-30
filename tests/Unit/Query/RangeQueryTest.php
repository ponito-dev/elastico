<?php

use Elastico\Query\Term\Range;

test('range with gt compiles correctly', function () {
    $result = (new Range(field: 'age'))->gt(18)->compile();

    expect($result)->toBe([
        'range' => ['age' => ['gt' => 18]],
    ]);
});

test('range with gte compiles correctly', function () {
    $result = (new Range(field: 'age'))->gte(18)->compile();

    expect($result)->toBe([
        'range' => ['age' => ['gte' => 18]],
    ]);
});

test('range with lt compiles correctly', function () {
    $result = (new Range(field: 'age'))->lt(65)->compile();

    expect($result)->toBe([
        'range' => ['age' => ['lt' => 65]],
    ]);
});

test('range with lte compiles correctly', function () {
    $result = (new Range(field: 'age'))->lte(65)->compile();

    expect($result)->toBe([
        'range' => ['age' => ['lte' => 65]],
    ]);
});

test('range with combined bounds compiles correctly', function () {
    $result = (new Range(field: 'age'))->gte(18)->lt(65)->compile();

    expect($result)->toBe([
        'range' => ['age' => ['gte' => 18, 'lt' => 65]],
    ]);
});

test('range with boost compiles correctly', function () {
    $result = (new Range(field: 'price'))->gt(0)->boost(1.5)->compile();

    expect($result)->toBe([
        'range' => ['price' => ['gt' => 0, 'boost' => 1.5]],
    ]);
});

test('range accepts date string', function () {
    $result = (new Range(field: 'created_at'))->gte('2024-01-01')->compile();

    expect($result)->toBe([
        'range' => ['created_at' => ['gte' => '2024-01-01']],
    ]);
});
