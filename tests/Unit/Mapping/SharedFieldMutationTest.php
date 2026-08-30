<?php

use Elastico\Eloquent\Concerns\HasIndexProperties;
use Elastico\Mapping\Field;

/**
 * Regression: getIndexProperties() memoises Field objects per class, and
 * Field::toArray() runs eachProperty callbacks directly on what it is given.
 * When the cache handed out its own instances, one embedding's callback
 * (->index(false)->copyTo(...)) rewrote the shared definitions, corrupting
 * every later embedding of the same class — Product's `name` mutations
 * leaked into `full_text`, whose fields were then created unsearchable on
 * the live index (index is immutable once a field exists).
 */
class SharedMutationDao
{
    use HasIndexProperties;

    public static function indexProperties(): array
    {
        return [
            Field::make(name: 'en', type: 'text')
                ->analyzer('analyzer_en')
                ->fields(['no-stem' => ['type' => 'text', 'analyzer' => 'no_stem_en']]),
            Field::make(name: 'de', type: 'text')
                ->analyzer('analyzer_de')
                ->fields(['no-stem' => ['type' => 'text', 'analyzer' => 'no_stem_de']]),
        ];
    }
}

test('an eachProperty callback on one embedding does not leak into another', function () {
    $name = Field::make(name: 'name', type: 'object')
        ->object(SharedMutationDao::class)
        ->eachProperty(fn (Field $p) => $p->copyTo(['full_text.'.$p->getName()])->index(false));

    $fullText = Field::make(name: 'full_text', type: 'object')
        ->object(SharedMutationDao::class);

    // Render the mutating field first — the order that corrupted Product.
    $nameConfig = $name->toArray();
    $fullTextConfig = $fullText->toArray();

    expect($nameConfig['properties']['en'])->toBe([
        'type' => 'text',
        'index' => false,
        'analyzer' => 'analyzer_en',
        'copy_to' => ['full_text.en'],
        'fields' => ['no-stem' => ['type' => 'text', 'analyzer' => 'no_stem_en']],
    ]);

    expect($fullTextConfig['properties']['en'])->toBe([
        'type' => 'text',
        'analyzer' => 'analyzer_en',
        'fields' => ['no-stem' => ['type' => 'text', 'analyzer' => 'no_stem_en']],
    ])->and($fullTextConfig['properties']['en'])->not->toHaveKeys(['index', 'copy_to']);
});

test('getIndexProperties returns independent instances on every call', function () {
    [$first] = SharedMutationDao::getIndexProperties();
    [$second] = SharedMutationDao::getIndexProperties();

    expect($first)->not->toBe($second);

    $first->index(false)->copyTo(['elsewhere']);

    expect($second->toArray())->not->toHaveKeys(['index', 'copy_to']);
});

test('cloned fields do not share nested multi-field arrays', function () {
    $original = Field::make(name: 'en', type: 'text')
        ->fields(['no-stem' => Field::make(name: 'no-stem', type: 'text')->analyzer('no_stem_en')]);

    $clone = clone $original;
    $clone->fields(['no-stem' => Field::make(name: 'no-stem', type: 'text')->analyzer('changed')]);

    expect($original->toArray()['fields']['no-stem']['analyzer'])->toBe('no_stem_en');
});
