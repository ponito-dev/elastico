<?php

namespace Elastico\Query\Response\Aggregation;

/**
 *  Composite Aggregation Response.
 */
class CompositeResponse extends BucketResponse
{
    /**
     * The after_key to request the next page, or null when the stream is
     * exhausted. ES omits after_key entirely on a zero-bucket page, so a
     * missing key must be treated as end-of-stream.
     */
    public function afterKey(): ?array
    {
        return $this->response()['after_key'] ?? null;
    }
}
