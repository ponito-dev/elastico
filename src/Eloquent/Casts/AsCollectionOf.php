<?php

namespace Elastico\Eloquent\Casts;

use BackedEnum;
use Exception;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Support\Collection;
use Spatie\LaravelData\Contracts\BaseData;
use Spatie\LaravelData\Contracts\TransformableData;

class AsCollectionOf extends AsCollection
{
    /**
     * Specify the class each item in the collection should be cast to.
     *
     * @param class-string $class
     */
    public static function of($class): string
    {
        return static::class . ':' . $class;
    }

    public static function castUsing(array $arguments)
    {
        $data_class = $arguments[0];

        return new class($data_class) implements CastsAttributes
        {
            public function __construct(public string $data_class) {}

            public function get($model, $key, $value, $attributes)
            {
                if (!isset($attributes[$key])) {
                    return;
                }

                if (!is_array($value)) {
                    return null;
                }

                return Collection::make($value)->map(fn ($item) => $this->castItem($item));
            }

            public function set($model, $key, $value, $attributes)
            {
                if ($value === null) {
                    return null;
                }

                if ($value instanceof Collection) {
                    $value = $value->all();
                }

                if (!is_array($value)) {
                    throw new Exception('Cannot cast [' . $key . '] to a collection of [' . $this->data_class . ']');
                }

                return [$key => collect($value)
                    ->map(fn ($item) => $this->serialiseItem($item))
                    ->values()
                    ->all()];
            }

            protected function castItem(mixed $item): mixed
            {
                $class = $this->data_class;

                if ($item === null || $item instanceof $class) {
                    return $item;
                }

                if (enum_exists($class)) {
                    return $class::tryFrom($item);
                }

                if (is_subclass_of($class, BaseData::class)) {
                    return $class::from($item);
                }

                return new $class($item);
            }

            protected function serialiseItem(mixed $item): mixed
            {
                return match (true) {
                    $item instanceof BackedEnum => $item->value,
                    $item instanceof TransformableData => $item->toArray(),
                    default => $item,
                };
            }
        };
    }
}
