<?php

namespace Elastico\Eloquent\Concerns;

use ReflectionClass;
use ReflectionProperty;
use Elastico\Mapping\Field;
use Illuminate\Support\Collection;
use ReflectionAttribute;

trait HasIndexProperties
{
    public static function indexProperties(): array
    {
        return [];
    }

    /**
     * Field definitions for the class, built once and memoised. Always
     * returns fresh clones: callers mutate Field objects (Field::toArray()
     * runs eachProperty callbacks like ->index(false)->copyTo(...) directly
     * on them), and handing out the cached instances let one embedding
     * field's mutations leak into every other embedding of the same class —
     * Product's `name` callback silently rewrote `full_text`'s definition.
     */
    public static function getIndexProperties(): array
    {
        static $cache = [];

        if (isset($cache[static::class])) {
            return array_map(static fn (Field $field): Field => clone $field, $cache[static::class]);
        }

        $properties = collect(static::indexProperties());

        $reflectionClass = new ReflectionClass(static::class);

        collect($reflectionClass->getAttributes(Field::class))
            ->map(static fn(ReflectionAttribute $attribute): Field => $attribute->newInstance())
            ->tap(static fn(Collection $props) => $properties->push(...$props));


        collect($reflectionClass->getProperties())
            ->flatMap(static fn(ReflectionProperty $property) => collect($property->getAttributes(Field::class))
                ->map(static fn(ReflectionAttribute $attribute): Field => $attribute->newInstance())
                ->each(static fn(Field $field) => $field->name($property->getName())))
            ->tap(static fn(Collection $props) => $properties->push(...$props));

        collect(class_uses_recursive(static::class))
            ->each(static function ($trait) use ($properties): void {
                collect((new ReflectionClass($trait))->getAttributes(Field::class))
                    ->map(fn(ReflectionAttribute $attribute): Field => $attribute->newInstance())
                    ->tap(static fn(Collection $props) => $properties->push(...$props));
            })
            ->each(static function ($trait) use ($properties): void {
                collect((new ReflectionClass($trait))->getProperties())
                    ->flatMap(static fn(ReflectionProperty $property) => collect($property->getAttributes(Field::class))
                        ->map(static fn(ReflectionAttribute $attribute): Field => $attribute->newInstance())
                        ->each(static fn(Field $field) => $field->name($property->getName())))
                    ->tap(static fn(Collection $props) => $properties->push(...$props));
            });


        $cache[static::class] = $properties->all();

        return array_map(static fn (Field $field): Field => clone $field, $cache[static::class]);
    }
}
