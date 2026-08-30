<?php

use Elastico\Query\Query;

enum WhereCorrectnessStatus: string
{
    case Active = 'active';
}

// Regressions for the 2026-08 correctness round: negation, empty whereIn,
// whereDate, value normalization, fail-loud unsupported clauses, and
// non-mutating compilation.

test('whereNot negates the clause', function () {
    $result = compileWhere(makeBuilder()->whereNot('status', 'active'));

    expect($result)->toBe([
        'bool' => [
            'must_not' => [
                ['term' => ['status' => ['value' => 'active']]],
            ],
        ],
    ]);
});

test('orWhereNot starts a new should group and negates', function () {
    $result = compileWhere(makeBuilder()->where('a', 1)->orWhereNot('b', 2));

    expect($result)->toBe([
        'bool' => [
            'should' => [
                ['bool' => ['must' => [['term' => ['a' => ['value' => 1]]]]]],
                ['bool' => ['must_not' => [['term' => ['b' => ['value' => 2]]]]]],
            ],
        ],
    ]);
});

test('empty whereIn matches nothing instead of dropping the clause', function () {
    $result = compileWhere(makeBuilder()->whereIn('id', []));

    expect($result)->toEqual([
        'bool' => [
            'filter' => [
                ['match_none' => new stdClass()],
            ],
        ],
    ]);
});

test('empty whereNotIn excludes nothing', function () {
    $builder = makeBuilder()->whereNotIn('id', []);

    expect($builder->grammar->compileWhereComponents($builder)->isEmpty())->toBeTrue();
});

test('whereDate compiles a day-rounded range', function () {
    $result = compileWhere(makeBuilder()->whereDate('created_at', '2024-01-01'));

    expect($result)->toBe([
        'bool' => [
            'filter' => [
                ['range' => ['created_at' => ['gte' => '2024-01-01||/d', 'lte' => '2024-01-01||/d']]],
            ],
        ],
    ]);
});

test('whereDate with comparison operator rounds toward the day boundary', function () {
    $result = compileWhere(makeBuilder()->whereDate('created_at', '>', '2024-01-01'));

    expect($result)->toBe([
        'bool' => [
            'filter' => [
                ['range' => ['created_at' => ['gt' => '2024-01-01||/d']]],
            ],
        ],
    ]);
});

test('where values are normalized: datetime, backed enum, stringable', function () {
    $date = new DateTimeImmutable('2024-01-01T12:00:00+00:00');

    $result = compileWhere(makeBuilder()->where('seen_at', '>', $date));

    expect($result)->toBe([
        'bool' => [
            'filter' => [
                ['range' => ['seen_at' => ['gt' => '2024-01-01T12:00:00+00:00']]],
            ],
        ],
    ]);

    $result = compileWhere(makeBuilder()->where('status', WhereCorrectnessStatus::Active));

    expect($result)->toBe([
        'bool' => [
            'must' => [
                ['term' => ['status' => ['value' => 'active']]],
            ],
        ],
    ]);
});

test('whereRaw with a string throws instead of silently vanishing', function () {
    $builder = makeBuilder()->whereRaw('{"term":{"a":1}}');

    compileWhere($builder);
})->throws(Exception::class, 'whereRaw()');

test('unsupported operators throw instead of UnhandledMatchError', function () {
    $builder = makeBuilder()->where('name', 'regexp', 'foo.*');

    compileWhere($builder);
})->throws(Exception::class, 'Unsupported operator [regexp]');

test('unsupported where types throw', function () {
    $builder = makeBuilder()->whereColumn('a', 'b');

    compileWhere($builder);
})->throws(Exception::class, 'Unsupported where type');

test('compileExists does not mutate the builder limit', function () {
    $builder = makeBuilder()->where('a', 1)->limit(50);

    $payload = $builder->grammar->compileExists($builder);

    expect($payload['terminate_after'])->toBe(1)
        ->and($payload['body']['size'])->toBe(0)
        ->and($builder->limit)->toBe(50);
});

test('compileDelete strips search-only clauses and maps limit to max_docs', function () {
    $builder = makeBuilder()->where('a', 1)->orderBy('b')->limit(500);

    $payload = $builder->grammar->compileDelete($builder);

    expect($payload)->not->toHaveKey('seq_no_primary_term')
        ->and($payload['body'])->not->toHaveKeys(['sort', 'size', '_source'])
        ->and($payload['max_docs'])->toBe(500);
});

test('compileUpdateByQuery forwards ignoreConflicts as an option', function () {
    $builder = makeBuilder()->where('a', 1)->ignoreConflicts();

    $payload = $builder->grammar->compileUpdateByQuery($builder, (new \Elastico\Scripting\UpdateParams(params: ['b']))->withModel(['b' => 2]));

    expect($payload['options'])->toBe(['ignore_conflicts' => true])
        ->and($payload['body']['query'])->toHaveKey('bool');
});

test('query compile calls getPayload exactly once per node', function () {
    $query = new class extends Query {
        protected string $type = 'probe';

        public int $calls = 0;

        public function getPayload(): array
        {
            ++$this->calls;

            return ['x' => 1];
        }
    };

    $query->compile();

    expect($query->calls)->toBe(1);
});
