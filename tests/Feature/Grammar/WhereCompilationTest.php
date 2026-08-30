<?php

// Helper: compile wheres from a builder to the ES DSL array
function compileWhere(\Elastico\Query\Builder $builder): array
{
    return $builder->grammar->compileWhereComponents($builder)->compile();
}

test('where equals produces term in must', function () {
    $result = compileWhere(makeBuilder()->where('status', 'active'));

    expect($result)->toBe([
        'bool' => [
            'must' => [
                ['term' => ['status' => ['value' => 'active']]],
            ],
        ],
    ]);
});

test('where equals with explicit operator produces term in must', function () {
    $result = compileWhere(makeBuilder()->where('status', '=', 'active'));

    expect($result)->toBe([
        'bool' => [
            'must' => [
                ['term' => ['status' => ['value' => 'active']]],
            ],
        ],
    ]);
});

test('where greater-than produces range gt in filter', function () {
    $result = compileWhere(makeBuilder()->where('age', '>', 18));

    expect($result)->toBe([
        'bool' => [
            'filter' => [
                ['range' => ['age' => ['gt' => 18]]],
            ],
        ],
    ]);
});

test('where greater-than-or-equal produces range gte in filter', function () {
    $result = compileWhere(makeBuilder()->where('age', '>=', 18));

    expect($result)->toBe([
        'bool' => [
            'filter' => [
                ['range' => ['age' => ['gte' => 18]]],
            ],
        ],
    ]);
});

test('where less-than produces range lt in filter', function () {
    $result = compileWhere(makeBuilder()->where('age', '<', 65));

    expect($result)->toBe([
        'bool' => [
            'filter' => [
                ['range' => ['age' => ['lt' => 65]]],
            ],
        ],
    ]);
});

test('where less-than-or-equal produces range lte in filter', function () {
    $result = compileWhere(makeBuilder()->where('age', '<=', 65));

    expect($result)->toBe([
        'bool' => [
            'filter' => [
                ['range' => ['age' => ['lte' => 65]]],
            ],
        ],
    ]);
});

test('where not-equal produces mustNot term', function () {
    $result = compileWhere(makeBuilder()->where('status', '<>', 'deleted'));

    expect($result)->toBe([
        'bool' => [
            'must_not' => [
                ['term' => ['status' => ['value' => 'deleted']]],
            ],
        ],
    ]);
});

test('whereIn produces terms query in filter', function () {
    $result = compileWhere(makeBuilder()->whereIn('status', ['active', 'pending']));

    expect($result)->toBe([
        'bool' => [
            'filter' => [
                ['terms' => ['status' => ['active', 'pending']]],
            ],
        ],
    ]);
});

test('whereNotIn produces terms query in mustNot', function () {
    $result = compileWhere(makeBuilder()->whereNotIn('status', ['deleted', 'banned']));

    expect($result)->toBe([
        'bool' => [
            'must_not' => [
                ['terms' => ['status' => ['deleted', 'banned']]],
            ],
        ],
    ]);
});

test('whereNull produces mustNot exists', function () {
    $result = compileWhere(makeBuilder()->whereNull('email'));

    expect($result)->toBe([
        'bool' => [
            'must_not' => [
                ['exists' => ['field' => 'email']],
            ],
        ],
    ]);
});

test('whereNotNull produces filter exists', function () {
    $result = compileWhere(makeBuilder()->whereNotNull('email'));

    expect($result)->toBe([
        'bool' => [
            'filter' => [
                ['exists' => ['field' => 'email']],
            ],
        ],
    ]);
});

test('whereBetween produces range gt and lt in filter', function () {
    $result = compileWhere(makeBuilder()->whereBetween('age', [18, 65]));

    expect($result)->toBe([
        'bool' => [
            'filter' => [
                ['range' => ['age' => ['gt' => 18]]],
                ['range' => ['age' => ['lt' => 65]]],
            ],
        ],
    ]);
});

test('multiple and-where conditions produce multiple musts', function () {
    $result = compileWhere(makeBuilder()->where('status', 'active')->where('verified', true));

    expect($result)->toBe([
        'bool' => [
            'must' => [
                ['term' => ['status' => ['value' => 'active']]],
                ['term' => ['verified' => ['value' => true]]],
            ],
        ],
    ]);
});

test('orWhere produces should clauses', function () {
    $result = compileWhere(makeBuilder()->where('status', 'active')->orWhere('status', 'pending'));

    expect($result)->toBe([
        'bool' => [
            'should' => [
                ['bool' => ['must' => [['term' => ['status' => ['value' => 'active']]]]]],
                ['bool' => ['must' => [['term' => ['status' => ['value' => 'pending']]]]]],
            ],
        ],
    ]);
});

test('no where conditions produces empty bool', function () {
    $builder = makeBuilder();
    /** @var \Elastico\Query\Compound\Boolean $bool */
    $bool = $builder->grammar->compileWhereComponents($builder);

    expect($bool->isEmpty())->toBeTrue();
});

test('where bang-equal produces mustNot term like <>', function () {
    $result = compileWhere(makeBuilder()->where('status', '!=', 'deleted'));

    expect($result)->toBe([
        'bool' => [
            'must_not' => [
                ['term' => ['status' => ['value' => 'deleted']]],
            ],
        ],
    ]);
});

test('where like produces match query in must', function () {
    $result = compileWhere(makeBuilder()->where('name', 'like', '%phone%'));

    expect($result)->toBe([
        'bool' => [
            'must' => [
                ['match' => ['name' => 'phone']],
            ],
        ],
    ]);
});

