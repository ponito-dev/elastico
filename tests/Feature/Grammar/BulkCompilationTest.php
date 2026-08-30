<?php

use Elastico\Scripting\Script;

// --- _source echo on bulk update operations, driven by select() ---

test('compileUpsert omits _source when nothing is selected', function () {
    $builder = makeBuilder();

    $compiled = $builder->grammar->compileUpsert($builder, [['_id' => '1', 'price' => 10]], ['_id'], null);

    expect($compiled['body'][1])->not->toHaveKey('_source');
});

test('compileUpsert echoes selected fields on doc upserts', function () {
    $builder = makeBuilder()->select(['price_change']);

    $compiled = $builder->grammar->compileUpsert($builder, [['_id' => '1', 'price' => 10]], ['_id'], null);

    expect($compiled['body'][0])->toBe(['update' => ['_id' => '1']])
        ->and($compiled['body'][1]['_source'])->toBe(['price_change'])
        ->and($compiled['body'][1]['doc'])->toBe(['price' => 10]);
});

test('compileUpsert echoes the full source on scripted upserts when selecting *', function () {
    $builder = makeBuilder()->select('*');

    $script = new Script(source: 'ctx._source.counter = 1');

    $compiled = $builder->grammar->compileUpsert($builder, [['_id' => '1', 'price' => 10]], ['_id'], [$script]);

    expect($compiled['body'][1])->toHaveKey('script')
        ->and($compiled['body'][1]['_source'])->toBeTrue();
});

test('compileBulkOperation echoes selected fields on update but never on create', function () {
    $builder = makeBuilder()->select(['price_change']);

    $update = $builder->grammar->compileBulkOperation($builder, [['_id' => '1', 'price' => 10]], 'update');
    $create = $builder->grammar->compileBulkOperation($builder, [['_id' => '1', 'price' => 10]], 'create');

    expect($update['body'][1]['_source'])->toBe(['price_change'])
        ->and($create['body'][1])->not->toHaveKey('_source');
});
