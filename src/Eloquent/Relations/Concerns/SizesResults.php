<?php

namespace Elastico\Eloquent\Relations\Concerns;

use RuntimeException;

/**
 * Gives has-many relations SQL result semantics. Without this, two
 * things go silently wrong on Elasticsearch: an unsized relation query
 * runs at the default size (10 hits), so eager and lazy loads truncate
 * to 10 related documents; and limit() inside an eager-load closure is
 * rerouted by Laravel's HasOneOrMany into groupLimit(), a per-parent
 * SQL window-function feature the grammar drops entirely.
 *
 * With it, an unsized load returns every related document up to one
 * search window — refusing loudly beyond it instead of truncating —
 * and limit()/take() always caps the request.
 */
trait SizesResults
{
    /**
     * One search window. Requesting it costs nothing when few hits
     * match, so the common case stays a single search.
     */
    protected static int $defaultSize = 10_000;

    /**
     * Elasticsearch has no per-parent group limit, so a limit is always
     * the request size — never Laravel's groupLimit().
     *
     * @param  int  $value
     * @return $this
     */
    public function limit($value)
    {
        $this->query->take($value);

        return $this;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getEager()
    {
        return $this->eagerKeysWereEmpty
            ? $this->related->newCollection()
            : $this->getSizedResults();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getResults()
    {
        return is_null($this->getParentKey())
            ? $this->related->newCollection()
            : $this->getSizedResults();
    }

    /**
     * Run the relation query: an explicit limit is respected as given;
     * an unsized query gets the full search window, and filling that
     * window entirely means documents were left behind.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    protected function getSizedResults()
    {
        if (! is_null($this->query->getQuery()->limit)) {
            return $this->query->get();
        }

        $results = $this->query->take(static::$defaultSize)->get();

        if ($results->count() >= static::$defaultSize) {
            throw new RuntimeException(sprintf(
                'Relation query on [%s] filled the whole %d-document search window; constrain it or set an explicit take().',
                get_class($this->related),
                static::$defaultSize,
            ));
        }

        return $results;
    }
}
