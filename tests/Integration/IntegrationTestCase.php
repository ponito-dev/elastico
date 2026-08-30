<?php

namespace Tests\Integration;

use Elastico\Connection;
use Elastico\Query\Builder;
use Elastico\Query\Grammar;
use Elastico\Query\Processor;
use PHPUnit\Framework\TestCase;

class IntegrationTestCase extends TestCase
{
    protected const INDEX = 'elastico_test';

    protected ?Connection $connection = null;

    private static bool $esAvailable = true;
    private static string $esSkipReason = '';

    // ------------------------------------------------------------------
    // Lifecycle
    // ------------------------------------------------------------------

    public static function setUpBeforeClass(): void
    {
        try {
            $conn = static::makeConnection();
            $conn->getClient()->ping();

            try {
                $conn->getClient()->indices()->delete(['index' => static::INDEX]);
            } catch (\Exception) {
                // index did not exist – that's fine
            }

            $conn->getClient()->indices()->create([
                'index' => static::INDEX,
                'body' => [
                    'settings' => [
                        'number_of_shards' => 1,
                        'number_of_replicas' => 0,
                    ],
                    'mappings' => [
                        'properties' => [
                            'name'   => ['type' => 'keyword'],
                            'status' => ['type' => 'keyword'],
                            'email'  => ['type' => 'keyword'],
                            'age'    => ['type' => 'integer'],
                            'score'  => ['type' => 'float'],
                            'price'  => ['type' => 'float'],
                        ],
                    ],
                ],
            ]);

            static::$esAvailable = true;
        } catch (\Exception $e) {
            static::$esAvailable = false;
            static::$esSkipReason = 'Elasticsearch not available: ' . $e->getMessage();
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (!static::$esAvailable) {
            return;
        }
        try {
            static::makeConnection()->getClient()->indices()->delete(['index' => static::INDEX]);
        } catch (\Exception) {}
    }

    protected function setUp(): void
    {
        if (!static::$esAvailable) {
            $this->markTestSkipped(static::$esSkipReason);
        }

        $this->connection = static::makeConnection();
    }

    protected function tearDown(): void
    {
        if ($this->connection === null) {
            return;
        }
        // Wipe all documents so each test starts clean
        try {
            $this->connection->getClient()->deleteByQuery([
                'index'   => static::INDEX,
                'refresh' => true,
                'body'    => ['query' => ['match_all' => new \stdClass()]],
            ]);
        } catch (\Exception) {}
    }

    // ------------------------------------------------------------------
    // Helpers available in test closures via $this->
    // ------------------------------------------------------------------

    protected static function makeConnection(): Connection
    {
        return new Connection([
            'hosts'    => [getenv('ELASTICSEARCH_HOST') ?: 'http://localhost:9200'],
            'database' => 'test',
        ]);
    }

    protected function builder(): Builder
    {
        $grammar   = new Grammar($this->connection);
        $processor = new Processor();

        return (new Builder($this->connection, $grammar, $processor))->from(static::INDEX);
    }

    /**
     * Insert documents and refresh so they are immediately searchable.
     * Auto-injects _index so the bulk header is complete.
     */
    protected function insert(array $docs): void
    {
        $docs = array_map(fn($doc) => ['_index' => static::INDEX] + $doc, $docs);

        $this->builder()->insert($docs);
        $this->connection->getClient()->indices()->refresh(['index' => static::INDEX]);
    }

    /**
     * Upsert documents and refresh.
     * Auto-injects _index so the bulk header is complete.
     */
    protected function upsert(array $docs, string $uniqueBy = '_id'): void
    {
        $docs = array_map(fn($doc) => ['_index' => static::INDEX] + $doc, $docs);

        $this->builder()->upsert($docs, $uniqueBy);
        $this->connection->getClient()->indices()->refresh(['index' => static::INDEX]);
    }
}
