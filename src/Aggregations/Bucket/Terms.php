<?php

namespace Elastico\Aggregations\Bucket;

use Elastico\Aggregations\Aggregation;
use Elastico\Query\Response\Aggregation\AggregationResponse;

/**
 * Terms Aggregation.
 */
class Terms extends BucketAggregation
{
    public const TYPE = 'terms';

    public function __construct(
        public string $field,
        public null|int $size = null,
        public null|int $min_doc_count = null,
        public null|string $missing = null,
        public null|string $execution_hint = null,
        public $include = null,
        public  $exclude = null,
        public null|array $order = null,
    ) {
        # code...
    }

    public function getPayload(): array
    {
        $agg = [
            'field' => $this->field,
            'size' => $this->size ?? 10,
        ];
        if (!is_null($this->include)) {
            $agg['include'] = $this->include;
        }
        if (!is_null($this->exclude)) {
            $agg['exclude'] = $this->exclude;
        }
        if (!is_null($this->min_doc_count)) {
            $agg['min_doc_count'] = $this->min_doc_count;
        }
        if (!is_null($this->missing)) {
            $agg['missing'] = $this->missing;
        }
        if (!is_null($this->execution_hint)) {
            $agg['execution_hint'] = $this->execution_hint;
        }
        if (!is_null($this->order)) {
            $agg['order'] = $this->order;
        }

        return $agg;
    }

    /**
     * Warn when the implicit size default (10) silently truncated buckets.
     */
    public function toResponse(array $response): AggregationResponse
    {
        if (is_null($this->size) && ($response['sum_other_doc_count'] ?? 0) > 0) {
            logger()->warning(sprintf(
                'Elastico: terms aggregation on [%s] was truncated at the default size of 10 (%d documents fell outside the returned buckets). Set an explicit ->size() to silence this warning.',
                $this->field,
                $response['sum_other_doc_count'],
            ));
        }

        return parent::toResponse($response);
    }

    public function field(string $field): self
    {
        $this->field = $field;

        return $this;
    }

    public function size(int $size): self
    {
        $this->size = $size;

        return $this;
    }

    public function min(int $min): self
    {
        $this->min_doc_count = $min;

        return $this;
    }

    public function missing(string $value): self
    {
        $this->missing = $value;

        return $this;
    }

    public function exclude($exclude): self
    {
        $this->exclude = $exclude;

        return $this;
    }

    public function include($include): self
    {
        $this->include = $include;

        return $this;
    }

    public function execution_hint(string $execution_hint): self
    {
        $this->execution_hint = $execution_hint;

        return $this;
    }

    /**
     * Bucket order, e.g. ['_count' => 'desc'], ['_key' => 'asc'] or
     * ['sub_aggregation_name' => 'desc'].
     */
    public function order(array $order): self
    {
        $this->order = $order;

        return $this;
    }
}
