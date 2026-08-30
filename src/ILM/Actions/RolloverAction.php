<?php

namespace Elastico\ILM\Actions;


/**
 * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/ilm-rollover.html
 */
class RolloverAction extends Action
{
    public function __construct(
        public ?string $max_age = null,
        public ?int $max_docs = null,
        public ?string $max_size = null,
        public ?string $max_primary_shard_size = null,
        public ?int $max_primary_shard_docs = null,
        public ?string $min_age = null,
        public ?int $min_docs = null,
        public ?string $min_size = null,
        public ?string $min_primary_shard_size = null,
        public ?int $min_primary_shard_docs = null,

    ) {
    }
}
