<?php

use Elastico\Eloquent\Casts\AsCollectionOf;
use Illuminate\Support\Collection;

enum CastTestMethod: string
{
    case Visa = 'visa';
    case Paypal = 'paypal';
}

class CastTestItem
{
    public function __construct(public array $attributes = []) {}
}

it('builds a cast string with of()', function () {
    expect(AsCollectionOf::of(CastTestItem::class))
        ->toBe(AsCollectionOf::class . ':' . CastTestItem::class);
});

it('hydrates backed enums', function () {
    $caster = AsCollectionOf::castUsing([CastTestMethod::class]);

    $result = $caster->get(null, 'methods', ['visa', 'paypal'], ['methods' => ['visa', 'paypal']]);

    expect($result)->toBeInstanceOf(Collection::class)
        ->and($result->all())->toBe([CastTestMethod::Visa, CastTestMethod::Paypal]);
});

it('serialises backed enums to values', function () {
    $caster = AsCollectionOf::castUsing([CastTestMethod::class]);

    $result = $caster->set(null, 'methods', collect([CastTestMethod::Visa]), []);

    expect($result)->toBe(['methods' => ['visa']]);
});

it('hydrates plain classes through their constructor', function () {
    $caster = AsCollectionOf::castUsing([CastTestItem::class]);

    $items = [['author' => 'John']];
    $result = $caster->get(null, 'items', $items, ['items' => $items]);

    expect($result->first())->toBeInstanceOf(CastTestItem::class)
        ->and($result->first()->attributes)->toBe(['author' => 'John']);
});

it('passes through already cast instances on get', function () {
    $caster = AsCollectionOf::castUsing([CastTestItem::class]);

    $item = new CastTestItem(['author' => 'Bob']);
    $result = $caster->get(null, 'items', [$item], ['items' => [$item]]);

    expect($result->first())->toBe($item);
});

it('passes through plain arrays on set', function () {
    $caster = AsCollectionOf::castUsing([CastTestItem::class]);

    $result = $caster->set(null, 'items', [['author' => 'Bob']], []);

    expect($result)->toBe(['items' => [['author' => 'Bob']]]);
});

it('handles null values', function () {
    $caster = AsCollectionOf::castUsing([CastTestItem::class]);

    expect($caster->get(null, 'items', null, ['items' => null]))->toBeNull()
        ->and($caster->set(null, 'items', null, []))->toBeNull();
});

it('rejects non iterable values on set', function () {
    $caster = AsCollectionOf::castUsing([CastTestItem::class]);

    expect(fn () => $caster->set(null, 'items', 'nope', []))->toThrow(Exception::class);
});
