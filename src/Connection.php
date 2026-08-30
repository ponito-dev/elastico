<?php

namespace Elastico;

use Generator;
use Closure;
use Elastico\Query\Processor;
use Elastico\Query\Grammar;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;
use Elastic\Elasticsearch\Response\Elasticsearch;
use Elastico\Events\QueryStarted;
use Elastico\Events\QueryExecuted as ElasticQueryExecuted;
use Elastico\Events\SlowQuery;
use Elastico\Exceptions\BulkException;
use Elastico\Exceptions\IndexNotFoundException;
use Elastico\Query\Builder;
use Exception;
use Throwable;
use GuzzleHttp\Promise\Promise;
use Http\Adapter\Guzzle7\Client as GuzzleAdapter;
use Illuminate\Database\Connection as BaseConnection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\LazyCollection;

/**
 * Keeps the Client and performs the queries
 * Takes care of monitoring all queries.
 */
class Connection extends BaseConnection implements ConnectionInterface
{
    const DEFAULT_CURSOR_SIZE = 1000;

    const MAX_CURSOR_SIZE = 10000;

    protected $client;

    public function __construct($config)
    {
        $this->config = $config;

        $this->database = $config['database'] ?? null;

        $this->useDefaultPostProcessor();

        $this->useDefaultQueryGrammar();
    }

    public function setAsync(bool $async): static
    {
        $this->getClient()->setAsync($async);

        return $this;
    }

    /**
     * Get a new query builder instance.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    public function query()
    {
        return new Builder(
            $this,
            $this->getQueryGrammar(),
            $this->getPostProcessor()
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getDriverName()
    {
        return 'elastic';
    }

    /**
     * Custom Function.
     *
     * @param mixed $method
     * @param mixed $payload
     */
    public function performQuery($method, $payload)
    {
        $events = $this->events;

        if (
            !$events
            || (!$events->hasListeners(QueryStarted::class) && !$events->hasListeners(ElasticQueryExecuted::class))
        ) {
            return $this->getClient()->{$method}($payload);
        }

        $identifier = uniqid($method . '.', true);
        $index = is_array($payload) ? ($payload['index'] ?? null) : null;
        $name = $method . (is_string($index) ? ':' . $index : '');

        $events->dispatch(new QueryStarted($identifier, $name, $method, $index));

        $start = microtime(true);

        try {
            $response = $this->getClient()->{$method}($payload);
        } catch (Throwable $e) {
            $events->dispatch(new ElasticQueryExecuted(
                query_identifier: $identifier,
                query_name: $name,
                method: $method,
                index: $index,
                wall_ms: (microtime(true) - $start) * 1000,
                error: mb_substr($e->getMessage(), 0, 500),
            ));

            throw $e;
        }

        $events->dispatch(new ElasticQueryExecuted(...[
            'query_identifier' => $identifier,
            'query_name' => $name,
            'method' => $method,
            'index' => $index,
            'wall_ms' => (microtime(true) - $start) * 1000,
        ] + $this->extractResponseMeta($response)));

        return $response;
    }

    /**
     * Server-side metadata for instrumentation. asArray() memoizes, so
     * decoding here is the same decode later consumers would trigger.
     */
    private function extractResponseMeta(mixed $response): array
    {
        if (!$response instanceof Elasticsearch) {
            return [];
        }

        $body = $response->asArray();

        return [
            'status_code' => $response->getStatusCode(),
            'took_ms' => $body['took'] ?? null,
            'timed_out' => $body['timed_out'] ?? null,
            'shards' => $body['_shards'] ?? null,
            'affected_docs' => $body['hits']['total']['value']
                ?? $body['updated']
                ?? $body['deleted']
                ?? $body['count']
                ?? (isset($body['items']) ? count($body['items']) : null),
        ];
    }

    public function find($query)
    {
        $query = [
            'method' => 'get',
            'payload' => $query,
        ];

        try {
            return $this->run($query, [], function ($query, $bindings) {
                return $this->performQuery($query['method'], $query['payload']);
            });
        } catch (ModelNotFoundException) {
            return null;
        }
    }

    public function findMany($query)
    {
        if (collect($query['body']['docs'])->isEmpty()) {
            return new LazyCollection();
        }

        $query = [
            'method' => 'mget',
            'query' => $query,
        ];

        return $this->run($query, [], function ($query, $bindings) {
            return $this->performQuery($query['method'], $query['query']);
        });
    }

    public function count($query)
    {
        $query = [
            'method' => 'count',
            'query' => $query,
        ];

        return $this->run($query, [], function ($query, $bindings) {
            return $this->performQuery($query['method'], $query['query']);
        });
    }

    public function bulk($query)
    {
        $query = [
            'method' => 'bulk',
            'payload' => $query,
            'options' => $query['options']
        ];

        unset($query['payload']['options']);

        return $this->run($query, [], function ($query, $bindings) {
            $response = $this->performQuery($query['method'], $query['payload']);

            if ($response['errors'] ?? false) {
                $exception = BulkException::fromResponse($this->resolveResponse($response));

                if (($query['options']['ignore_conflicts'] ?? false) && $exception->onlyConflicts()) {
                    return $response;
                }

                throw $exception;
            }

            return $response;
        });
    }

    public function termsEnum(string|array $index, string $field, ?int $size = null, ?string $string = null, ?string $after = null, ?bool $insensitive = null)
    {
        $query = [
            'method' => 'termsEnum',
            'payload' => [
                'index' => $index,
                'body' => array_filter([
                    'field' => $field,
                    'size' => $size,
                    'string' => $string,
                    'search_after' => $after,
                    'case_insensitive' => $insensitive,
                ]),
            ],
        ];

        return $this->run($query, [], function ($query, $bindings) {
            return $this->performQuery($query['method'], $query['payload']);
        });
    }

    /**
     * Run a select statement against the database.
     *
     * @param string $query
     * @param array  $bindings
     * @param bool   $useReadPdo
     *
     * @return array
     */
    public function select($query, $bindings = [], $useReadPdo = true)
    {
        $query = [
            'method' => 'search',
            'payload' => $query,
        ];

        return $this->run($query, $bindings, function ($query, $bindings) {
            if ($this->pretending()) {
                return [];
            }

            return $this->performQuery($query['method'], $query['payload']);
        });
    }

    public function selectMany($queries)
    {
        $query = [
            'method' => 'msearch',
            'payload' => $queries,
        ];

        return $this->run($query, [], function ($query, $bindings) {
            if ($this->pretending()) {
                return [];
            }

            $responses = $this->performQuery($query['method'], $query['payload']);
            $responses = $this->getPostProcessor()->resolvePromise($responses);

            foreach ($responses['responses'] as $response) {

                if ($response['error'] ?? false) {
                    throw new QueryException(
                        $this->getDriverName(),
                        mb_substr(json_encode($query), 0, 500),
                        $this->prepareBindings($bindings),
                        new Exception(mb_substr(json_encode($response['error']), 0, 500)) // new Exception(($response['error']['reason'] ?? substr(json_encode($response['error']), 0, 100)) . ': ' . ($response['error']['root_cause'][0]['reason'] ?? ''))
                    );
                }
            }
            return $responses;
        });
    }

    /**
     * Run a select statement against the database and returns a generator.
     *
     * @param string   $query
     * @param array    $bindings
     * @param bool     $useReadPdo
     * @param mixed    $keepAlive
     * @param null|int $pageSize
     */
    public function cursor($query, $bindings = [], $useReadPdo = true, $keepAlive = '1m', ?int $pageSize = null): Generator
    {
        if ($this->pretending()) {
            return;
        }

        $payload = $query;

        // The compiled body.size carries the builder's limit: it caps the
        // total documents yielded. Pages are fetched $pageSize docs at a
        // time (capped by Elasticsearch's 10k search window). Without the
        // limit the PIT loop drains the whole index.
        $limit = $payload['body']['size'] ?? null;
        $pageSize = (int) max(1, min($pageSize ?? static::DEFAULT_CURSOR_SIZE, static::MAX_CURSOR_SIZE));

        if (!is_null($limit)) {
            $pageSize = (int) min($pageSize, $limit);
        }

        $payload['body']['size'] = $pageSize;

        // search_after needs a total order; _shard_doc is the PIT tiebreaker.
        if (empty($payload['body']['sort'])) {
            $payload['body']['sort'] = ['_shard_doc'];
        } elseif (!in_array('_shard_doc', $payload['body']['sort'], true)) {
            $payload['body']['sort'][] = '_shard_doc';
        }

        $pitPayload = [
            'index' => $payload['index'],
            'keep_alive' => $keepAlive,
        ];

        $pit = $this->resolveResponse($this->run(
            ['method' => 'openPointInTime'] + $pitPayload,
            $bindings,
            fn () => $this->performQuery('openPointInTime', $pitPayload)
        ));

        $pit['keep_alive'] = $keepAlive;
        unset($pit['_shards']);

        $payload['body']['pit'] = $pit;
        unset($payload['index']);

        try {
            $response = $this->resolveResponse($this->run($query, $bindings, function () use ($payload) {
                return $this->performQuery('search', $payload);
            }));

            $yielded = 0;

            while (true) {
                $hits = $response['hits']['hits'];

                if ([] === $hits) {
                    return;
                }

                // Each page refreshes the PIT id for the next request.
                $payload['body']['pit']['id'] = $response['pit_id'] ?? $payload['body']['pit']['id'];

                foreach ($hits as $hit) {
                    yield $hit['_id'] => $hit;

                    if (!is_null($limit) && ++$yielded >= $limit) {
                        return;
                    }
                }

                $payload['body']['search_after'] = $hits[count($hits) - 1]['sort'];

                if (!is_null($limit)) {
                    $payload['body']['size'] = (int) min($pageSize, $limit - $yielded);
                }

                // Through run() so every page is timed, logged, and visible
                // to QueryExecuted listeners — not just page one.
                $page = $payload;
                $response = $this->resolveResponse($this->run(
                    $page,
                    $bindings,
                    fn () => $this->performQuery('search', $page)
                ));
            }
        } finally {
            // Runs on exhaustion, on an early return (limit reached), on an
            // exception, and when an abandoned generator is destroyed —
            // search contexts must not outlive the scan.
            try {
                $closePayload = ['body' => ['id' => $payload['body']['pit']['id']]];

                $this->run(
                    ['method' => 'closePointInTime'] + $closePayload,
                    [],
                    fn () => $this->performQuery('closePointInTime', $closePayload)
                );
            } catch (Throwable) {
                // Best-effort: the PIT may already have expired server-side.
            }
        }
    }

    private function resolveResponse(mixed $response): mixed
    {
        if ($response instanceof Promise) {
            $response = $response->wait();
        }

        if ($response instanceof Elasticsearch) {
            $response = $response->asArray();
        }

        return $response;
    }

    /**
     * Run an insert statement against the database.
     *
     * @param string $query
     * @param array  $bindings
     *
     * @return bool
     */
    public function insert($query, $bindings = [])
    {

        $r =  $this->statement($query, $bindings);

        return $r;
    }

    /**
     * Run an update statement against the database.
     *
     * @param string $query
     * @param array  $bindings
     *
     * @return int
     */
    public function update($query, $bindings = [])
    {
        $query = [
            'method' => 'update',
            'payload' => $query,
        ];

        return $this->run($query, $bindings, function ($query, $bindings) {
            if ($this->pretending()) {
                return 0;
            }

            $response = $this->performQuery($query['method'], $query['payload']);

            if ($response instanceof Promise) {
                $response = $response->wait()->asArray();
            }
            $this->recordsHaveBeenModified(
                'updated' == $response['result']
            );

            return 1;
        });
    }

    public function updateByQuery($query)
    {
        // Match delete(): slice server-side and honor ignoreConflicts() as
        // conflicts=proceed so live-written indices don't abort on 409s.
        $query['slices'] = 'auto';

        if ($query['options']['ignore_conflicts'] ?? false) {
            $query['conflicts'] = 'proceed';
        }

        unset($query['options']);

        $query = [
            'method' => 'updateByQuery',
            'payload' => $query,
        ];

        return $this->run($query, [], function ($query, $bindings) {
            if ($this->pretending()) {
                return 0;
            }

            $response = $this->performQuery($query['method'], $query['payload']);

            if ($response instanceof Promise) {
                $response = $response->wait()->asArray();
            }

            $this->recordsHaveBeenModified(
                $response['updated'] > 0
            );

            return $response['updated'];
        });
    }

    /**
     * Run a delete statement against the database.
     *
     * @param string $query
     * @param array  $bindings
     *
     * @return int
     */
    public function delete($query, $bindings = [])
    {
        $query['slices'] = 'auto';

        if ($query['options']['ignore_conflicts'] ?? false) {
            $query['conflicts'] = 'proceed';
        }

        unset($query['options']);

        $query = [
            'method' => 'deleteByQuery',
            'payload' => $query,
        ];

        return $this->run($query, [], function ($query, $bindings) {
            return $this->performQuery($query['method'], $query['payload']);
        });
    }

    public function deleteDocument(string $id, string $index)
    {
        $query = [
            'method' => 'delete',
            'payload' => [
                'index' => $index,
                'id' => $id,
            ],
        ];

        return $this->run($query, [], function ($query, $bindings) {
            return $this->performQuery($query['method'], $query['payload']);
        });
    }

    /**
     * Execute an SQL statement and return the boolean result.
     *
     * @param string $query
     * @param array  $bindings
     *
     * @return bool
     */
    public function statement($query, $bindings = [])
    {
        return $this->run($query, $bindings, function ($query, $bindings) {
            if ($this->pretending()) {
                return true;
            }

            $statement = $this->getPdo()->prepare($query);

            $this->bindValues($statement, $this->prepareBindings($bindings));

            $this->recordsHaveBeenModified();

            return $statement->execute();
        });
    }

    /**
     * Run an SQL statement and get the number of rows affected.
     *
     * @param string $query
     * @param array  $bindings
     *
     * @return int
     */
    public function affectingStatement($query, $bindings = [])
    {
        return $this->run($query, $bindings, function ($query, $bindings) {
            if ($this->pretending()) {
                return 0;
            }

            // For update or delete statements, we want to get the number of rows affected
            // by the statement and return that back to the developer. We'll first need
            // to execute the statement and then we'll use PDO to fetch the affected.
            $statement = $this->getPdo()->prepare($query);

            $this->bindValues($statement, $this->prepareBindings($bindings));

            $statement->execute();

            $this->recordsHaveBeenModified(
                ($count = $statement->rowCount()) > 0
            );

            return $count;
        });
    }

    /**
     * Run a raw, unprepared query against the PDO connection.
     *
     * @param string $query
     *
     * @return bool
     */
    public function unprepared($query)
    {
        return $this->run($query, [], function ($query) {
            if ($this->pretending()) {
                return true;
            }

            $this->recordsHaveBeenModified(
                $change = false !== $this->getPdo()->exec($query)
            );

            return $change;
        });
    }

    /**
     * Log a query in the connection's query log.
     *
     * @param string|array     $query
     * @param array      $bindings
     * @param null|float $time
     */
    public function logQuery($query, $bindings, $time = null)
    {
        $this->totalQueryDuration += $time ?? 0.0;

        $listening = $this->events?->hasListeners(QueryExecuted::class) ?? false;
        $threshold = (float) ($this->getConfig('slow_query_ms') ?? 0);
        $isSlow = $threshold > 0 && !is_null($time) && $time >= $threshold;

        // Truncating and json-encoding bulk payloads is pure overhead when
        // nobody consumes the result; skip it, and never retain raw
        // payloads in the query log — bulk bodies kept for the process
        // lifetime are a leak.
        if (!$listening && !$this->loggingQueries && !$isSlow) {
            return;
        }

        // With log_bodies the full DSL survives — "the slow query is
        // always the one that got truncated" is not a debugging story.
        $queryString = $this->getConfig('log_bodies')
            ? json_encode($query)
            : mb_substr(json_encode(static::cleanQuery($query)), 0, 1000);

        if ($listening) {
            $this->event(new QueryExecuted($queryString, [], $time, $this));
        }

        if ($isSlow) {
            $this->events?->dispatch(new SlowQuery(
                connection: $this->getName() ?? $this->getDriverName(),
                query: $queryString,
                time_ms: $time,
                threshold_ms: $threshold,
            ));
        }

        if ($this->loggingQueries) {
            $this->queryLog[] = [
                'query' => $queryString,
                'bindings' => [],
                'time' => $time,
            ];
        }
    }

    private static function cleanQuery(array $query): array
    {
        # cleanup query by recursively reducing all arrays to max 20 items and add ... 
        # and all strings to max 1000 chars
        $cleanedQuery = [];
        foreach ($query as $key => $value) {
            if (is_array($value)) {
                $cleanedQuery[$key] = static::cleanQuery($value);
                if (count($cleanedQuery[$key]) > 20) {
                    $cleanedQuery[$key] = array_slice($cleanedQuery[$key], 0, 20);
                    $cleanedQuery[$key][] = '...';
                }
            } elseif (is_string($value)) {
                $cleanedQuery[$key] = mb_substr($value, 0, 1000);
            } else {
                $cleanedQuery[$key] = $value;
            }
        }

        return $cleanedQuery;
    }

    public function getClient(): Client
    {
        return $this->client ??= ClientBuilder::fromConfig(
            $this->createClientConfigFromConnection($this->config)
        )
            // ->setAsync($this->config['async'] ?? false)
        ;
    }

    /**
     * Reconnect to the database if a PDO connection is missing.
     */
    public function reconnectIfMissingConnection() {}

    /**
     * Run a SQL statement.
     *
     * @param string $query
     * @param array  $bindings
     *
     * @return mixed
     *
     * @throws QueryException
     */
    protected function runQueryCallback($query, $bindings, Closure $callback)
    {
        // To execute the statement, we'll simply call the callback, which will actually
        // run the SQL against the PDO connection. Then we can calculate the time it
        // took to execute and log the query SQL, bindings and time in our memory.
        try {
            return $callback($query, $bindings);
        }

        // If an exception occurs when attempting to run a query, we'll format the error
        // message to include the bindings with SQL, which will make this exception a
        // lot more helpful to the developer instead of just the database's errors.
        catch (Exception $e) {
            // Bulk failures are already structured and bounded; wrapping
            // them in a QueryException would bury the item-level detail.
            if ($e instanceof BulkException) {
                throw $e;
            }

            if (str_contains($e->getMessage(), 'index_not_found_exception')) {
                if (preg_match('/no such index \[(.*?)\]/', $e->getMessage(), $matches)) {
                    $index_name = $matches[1];
                } else {
                    $index_name = 'unknown';
                }

                throw new IndexNotFoundException(index: $index_name);
            }
            if (str_starts_with($e->getMessage(), '404 Not Found')) {

                throw new ModelNotFoundException();
            }

            throw new QueryException(
                $this->getConfig('name'),
                mb_substr(json_encode($query), 0, 500),
                $this->prepareBindings($bindings),
                $e
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function getDefaultPostProcessor()
    {
        return new Processor();
    }

    /**
     * {@inheritdoc}
     */
    protected function getDefaultQueryGrammar()
    {
        return new Grammar($this);
    }

    private function createClientConfigFromConnection(array $connection): array
    {
        // Do NOT pass a custom httpClient here: ClientBuilder::build()
        // applies CABundle/SSL options via setOptions(), which rejects
        // the Guzzle7 PSR-18 adapter ("not supported for custom options")
        // and takes every query down with it. The sync client stays
        // discovery-built; gzip needs a different route.
        return ($connection['client'] ?? []) + array_filter([
            'basicAuthentication' => array_filter([
                'username' => $connection['username'] ?? null,
                'password' => $connection['password'] ?? null,
            ]),
            'hosts' => $connection['hosts'] ?? null,
            'CABundle' => $connection['certificate'] ?? null,
            'retries' => $connection['retries'] ?? null,
            'AsyncHttpClient' => $connection['client']['AsyncHttpClient'] ?? GuzzleAdapter::createWithConfig(array_filter(['verify' => $connection['certificate'] ?? null])),
            // Transport-level retry/node-down logging, otherwise swallowed
            // by a NullLogger. Point it at a channel that does NOT write
            // back into Elasticsearch.
            'logger' => isset($connection['logger'])
                ? \Illuminate\Support\Facades\Log::channel($connection['logger'])
                : null,
            'ElasticCloudId' => $connection['cloud'] ?? null,
        ]);
    }
}
