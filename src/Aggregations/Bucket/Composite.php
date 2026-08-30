<?php

namespace Elastico\Aggregations\Bucket;

use Elastico\Query\Response\Aggregation\CompositeResponse;

/**
 * Composite aggregation: the only ES aggregation with exhaustive, resumable
 * pagination (via after_key). Use where partitioned terms aggregations
 * cannot guarantee completeness. Read the next page key from
 * CompositeResponse::afterKey(); null means the stream is exhausted.
 */
class Composite extends BucketAggregation
{
    public const TYPE = 'composite';

    const RESPONSE_CLASS = CompositeResponse::class;

    /**
     * @param list<array<string, array>>     $sources composite sources, e.g. [['category' => ['terms' => ['field' => 'product.category']]]]
     * @param null|array<string, mixed>      $after   the after_key of the previous page
     */
    public function __construct(
        public array $sources,
        public int $size = 1000,
        public ?array $after = null,
    ) {
    }

    public function getPayload(): array
    {
        $agg = [
            'sources' => $this->sources,
            'size' => $this->size,
        ];
        if (!is_null($this->after)) {
            $agg['after'] = $this->after;
        }

        return $agg;
    }

    public function size(int $size): self
    {
        $this->size = $size;

        return $this;
    }

    public function after(?array $after): self
    {
        $this->after = $after;

        return $this;
    }

    /**
     * Single terms source composite, keyed by $name in each bucket's key.
     */
    public static function terms(string $name, string $field, int $size = 1000, ?array $after = null): self
    {
        return new self(
            sources: [[$name => ['terms' => ['field' => $field]]]],
            size: $size,
            after: $after,
        );
    }
}
