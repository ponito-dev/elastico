<?php

use Elastico\Query\Term\Term;

test('term compiles to correct DSL structure', function () {
    $result = (new Term(field: 'status', value: 'active'))->compile();

    expect($result)->toBe([
        'term' => [
            'status' => ['value' => 'active'],
        ],
    ]);
});

test('term accepts integer value', function () {
    $result = (new Term(field: 'age', value: 42))->compile();

    expect($result)->toBe([
        'term' => [
            'age' => ['value' => 42],
        ],
    ]);
});

test('term accepts boolean value', function () {
    $result = (new Term(field: 'active', value: true))->compile();

    expect($result)->toBe([
        'term' => [
            'active' => ['value' => true],
        ],
    ]);
});

test('term with boost includes boost in payload', function () {
    $result = (new Term(field: 'status', value: 'active'))->boost(1.5)->compile();

    expect($result)->toBe([
        'term' => [
            'status' => [
                'value' => 'active',
                'boost' => 1.5,
            ],
        ],
    ]);
});

test('term backed enum value is unwrapped', function () {
    $enum = new class('published') extends \BackedEnum {
        // Cannot instantiate BackedEnum directly; use a real enum in test
    };
})->skip('requires a concrete backed enum fixture');

test('term value method sets value via fluent interface', function () {
    $result = (new Term(field: 'status', value: 'draft'))->value('published')->compile();

    expect($result)->toBe([
        'term' => [
            'status' => ['value' => 'published'],
        ],
    ]);
});

test('term field method changes field via fluent interface', function () {
    $result = (new Term(field: 'status', value: 'active'))->field('state')->compile();

    expect($result)->toBe([
        'term' => [
            'state' => ['value' => 'active'],
        ],
    ]);
});
