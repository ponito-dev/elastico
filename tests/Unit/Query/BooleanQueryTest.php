<?php

use Elastico\Query\Compound\Boolean;
use Elastico\Query\Term\Term;
use Elastico\Query\Term\Range;
use Elastico\Query\Term\Exists;

test('boolean with must clause compiles correctly', function () {
    $result = Boolean::make()
        ->must(new Term('status', 'active'))
        ->compile();

    expect($result)->toBe([
        'bool' => [
            'must' => [
                ['term' => ['status' => ['value' => 'active']]],
            ],
        ],
    ]);
});

test('boolean with filter clause compiles correctly', function () {
    $result = Boolean::make()
        ->filter(new Term('status', 'active'))
        ->compile();

    expect($result)->toBe([
        'bool' => [
            'filter' => [
                ['term' => ['status' => ['value' => 'active']]],
            ],
        ],
    ]);
});

test('boolean with mustNot clause compiles correctly', function () {
    $result = Boolean::make()
        ->mustNot(new Term('status', 'deleted'))
        ->compile();

    expect($result)->toBe([
        'bool' => [
            'must_not' => [
                ['term' => ['status' => ['value' => 'deleted']]],
            ],
        ],
    ]);
});

test('boolean with should clause compiles correctly', function () {
    $result = Boolean::make()
        ->should(new Term('status', 'active'))
        ->should(new Term('status', 'pending'))
        ->compile();

    expect($result)->toBe([
        'bool' => [
            'should' => [
                ['term' => ['status' => ['value' => 'active']]],
                ['term' => ['status' => ['value' => 'pending']]],
            ],
        ],
    ]);
});

test('boolean with multiple clause types compiles correctly', function () {
    $result = Boolean::make()
        ->must(new Term('status', 'active'))
        ->filter((new Range('age'))->gte(18))
        ->mustNot(new Term('banned', true))
        ->compile();

    expect($result)->toBe([
        'bool' => [
            'must' => [['term' => ['status' => ['value' => 'active']]]],
            'filter' => [['range' => ['age' => ['gte' => 18]]]],
            'must_not' => [['term' => ['banned' => ['value' => true]]]],
        ],
    ]);
});

test('boolean with minimum_should_match includes it in payload', function () {
    $result = Boolean::make()
        ->should(new Term('a', 'x'))
        ->should(new Term('b', 'y'))
        ->min(1)
        ->compile();

    expect($result['bool'])->toHaveKey('minimum_should_match', 1);
});

test('boolean with boost includes it in payload', function () {
    $result = Boolean::make()
        ->must(new Term('status', 'active'))
        ->boost(1.5)
        ->compile();

    expect($result['bool'])->toHaveKey('boost', 1.5);
});

test('boolean isEmpty returns true when no clauses added', function () {
    expect(Boolean::make()->isEmpty())->toBeTrue();
});

test('boolean isEmpty returns false when clauses are present', function () {
    expect(Boolean::make()->must(new Term('a', 'b'))->isEmpty())->toBeFalse();
    expect(Boolean::make()->filter(new Term('a', 'b'))->isEmpty())->toBeFalse();
    expect(Boolean::make()->mustNot(new Term('a', 'b'))->isEmpty())->toBeFalse();
    expect(Boolean::make()->should(new Term('a', 'b'))->isEmpty())->toBeFalse();
});

test('single-item filter Boolean unwraps to inner payload', function () {
    $inner = Boolean::make()->must(new Term('status', 'active'));
    $outer = Boolean::make()->filter($inner);

    // The outer bool has one filter which is itself a Boolean → unwraps
    expect($outer->compile())->toBe([
        'bool' => [
            'must' => [['term' => ['status' => ['value' => 'active']]]],
        ],
    ]);
});

test('single-item must Boolean unwraps to inner payload', function () {
    $inner = Boolean::make()->filter(new Term('status', 'active'));
    $outer = Boolean::make()->must($inner);

    expect($outer->compile())->toBe([
        'bool' => [
            'filter' => [['term' => ['status' => ['value' => 'active']]]],
        ],
    ]);
});
