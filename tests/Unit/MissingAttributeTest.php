<?php

use Elastico\Eloquent\Model;
use Elastico\Index\Config;
use Elastico\Mapping\Field;
use Illuminate\Database\Eloquent\MissingAttributeException;

/**
 * Under Model::shouldBeStrict(), Eloquent throws MissingAttributeException
 * for any attribute absent from the retrieved row — SQL semantics, where a
 * row always carries every column. Elasticsearch documents omit fields that
 * hold no value, so an absent-but-mapped field must read as null; only
 * names outside the mapping (genuine typos) keep the strict behaviour.
 */
class MissingAttributeModel extends Model
{
    protected static function indexConfig(): Config
    {
        return Config::make(index: 'missing_attribute_test');
    }

    public static function indexProperties(): array
    {
        return [
            Field::make(name: 'name', type: 'keyword'),
            Field::make(name: 'brand_id', type: 'keyword'),
        ];
    }
}

function makeRetrievedModel(): MissingAttributeModel
{
    $model = new MissingAttributeModel();
    $model->setRawAttributes(['name' => 'present'], true);
    $model->exists = true;

    return $model;
}

afterEach(fn () => Model::preventAccessingMissingAttributes(false));

test('an absent but mapped field reads as null in strict mode', function () {
    Model::preventAccessingMissingAttributes();

    expect(makeRetrievedModel()->brand_id)->toBeNull();
});

test('ES hit metadata reads as null in strict mode', function () {
    Model::preventAccessingMissingAttributes();

    $model = makeRetrievedModel();

    expect($model->_seq_no)->toBeNull()
        ->and($model->_primary_term)->toBeNull();
});

test('an unmapped attribute still throws in strict mode', function () {
    Model::preventAccessingMissingAttributes();

    expect(fn () => makeRetrievedModel()->brand_idd)
        ->toThrow(MissingAttributeException::class);
});

test('an unmapped attribute reads as null when strict mode is off', function () {
    expect(makeRetrievedModel()->brand_idd)->toBeNull();
});
