<?php

use Elastico\Query\Term\Exists;

test('exists compiles to correct DSL structure', function () {
    $result = (new Exists(field: 'email'))->compile();

    expect($result)->toBe([
        'exists' => ['field' => 'email'],
    ]);
});

test('exists with boost includes it in payload', function () {
    $result = (new Exists(field: 'email'))->boost(2)->compile();

    expect($result)->toBe([
        'exists' => ['field' => 'email', 'boost' => 2],
    ]);
});
