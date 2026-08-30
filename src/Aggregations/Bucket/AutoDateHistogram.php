<?php

namespace Elastico\Aggregations\Bucket;

use Elastico\Aggregations\Aggregation;

/**
 * Auto Date Histogram Aggregation.
 */
class AutoDateHistogram extends BucketAggregation
{
    public const TYPE = 'auto_date_histogram';

    public function __construct(
        public string $field,
        public int $buckets,
        public null|string $time_zone = null,
    ) {
    }

    public function getPayload(): array
    {
        $agg = [
            'field' => $this->field,
            'buckets' => $this->buckets,
        ];
        if (!is_null($this->time_zone)) {
            $agg['time_zone'] = $this->time_zone;
        }

        return $agg;
    }

    public function field(string $field): self
    {
        $this->field = $field;

        return $this;
    }

    public function buckets(int $buckets): self
    {
        $this->buckets = $buckets;

        return $this;
    }

    public function timezone(string $time_zone): self
    {
        $this->time_zone = $time_zone;

        return $this;
    }
}
