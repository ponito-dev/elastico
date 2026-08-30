<?php

use Elastico\Query\Term\Terms;

test('terms compiles to correct DSL structure', function () {
    $result = (new Terms(field: 'status', values: ['active', 'pending']))->compile();

    expect($result)->toBe([
        'terms' => [
            'status' => ['active', 'pending'],
        ],
    ]);
});

test('terms accepts integer values', function () {
    $result = (new Terms(field: 'category_id', values: [1, 2, 3]))->compile();

    expect($result)->toBe([
        'terms' => [
            'category_id' => [1, 2, 3],
        ],
    ]);
});

test('terms with boost includes boost in payload', function () {
    $result = (new Terms(field: 'status', values: ['active']))->boost(2.0)->compile();

    expect($result)->toBe([
        'terms' => [
            'status' => ['active'],
            'boost' => 2.0,
        ],
    ]);
});

test('terms throws when constructed with empty values', function () {
    new Terms(field: 'status', values: []);
})->throws(\RuntimeException::class, 'Empty Values');

test('terms values method reindexes array', function () {
    $result = (new Terms(field: 'status', values: ['a']))->values(['x', 'y'])->compile();

    expect($result['terms']['status'])->toBe(['x', 'y']);
});
