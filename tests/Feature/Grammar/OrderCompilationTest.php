<?php

function compileOrder(\Elastico\Query\Builder $builder): array
{
    return $builder->grammar->compileOrderComponents($builder);
}

test('orderBy produces ascending sort by default', function () {
    $result = compileOrder(makeBuilder()->orderBy('created_at'));

    expect($result)->toBe([
        ['created_at' => ['order' => 'asc']],
    ]);
});

test('orderBy desc produces descending sort', function () {
    $result = compileOrder(makeBuilder()->orderBy('created_at', 'desc'));

    expect($result)->toBe([
        ['created_at' => ['order' => 'desc']],
    ]);
});

test('orderBy with missing parameter includes it', function () {
    $result = compileOrder(makeBuilder()->orderBy('price', 'asc', '_last'));

    expect($result)->toBe([
        ['price' => ['order' => 'asc', 'missing' => '_last']],
    ]);
});

test('multiple orderBy calls produce multiple sort entries', function () {
    $result = compileOrder(makeBuilder()->orderBy('score', 'desc')->orderBy('created_at', 'asc'));

    expect($result)->toBe([
        ['score' => ['order' => 'desc']],
        ['created_at' => ['order' => 'asc']],
    ]);
});

test('no orderBy produces empty sort array', function () {
    $result = compileOrder(makeBuilder());

    expect($result)->toBe([]);
});

test('orderBy with invalid direction throws', function () {
    makeBuilder()->orderBy('field', 'invalid');
})->throws(\InvalidArgumentException::class);
