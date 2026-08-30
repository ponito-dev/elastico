<?php

use Elastico\Connection;
use Elastico\Query\Builder;
use Elastico\Query\Grammar;
use Elastico\Query\Processor;

/**
 * find() and findMany() used to compile their payload from the $columns
 * parameter rather than the query's resolved columns, so an earlier
 * select() was silently discarded and every get fetched the whole _source.
 */
function recordingBuilder(): Builder
{
    $connection = new class(['database' => 'test_db']) extends Connection
    {
        public array $payloads = [];

        public function find($query)
        {
            $this->payloads[] = $query;

            return null;
        }

        public function findMany($query)
        {
            $this->payloads[] = $query;

            return ['docs' => []];
        }
    };

    return (new Builder($connection, new Grammar($connection), new Processor))->from('test_index');
}

test('find carries an earlier select() into _source_includes', function () {
    $builder = recordingBuilder()->select(['name', 'brand_id']);

    $builder->find('abc');

    expect($builder->connection->payloads[0]['_source_includes'])->toBe(['name', 'brand_id']);
});

test('find without a select() still asks for the whole source', function () {
    $builder = recordingBuilder();

    $builder->find('abc');

    expect($builder->connection->payloads[0]['_source_includes'])->toBe(['*']);
});

test('find prefers the select() over the columns argument', function () {
    $builder = recordingBuilder()->select(['name']);

    $builder->find('abc', ['*']);

    expect($builder->connection->payloads[0]['_source_includes'])->toBe(['name']);
});

test('find uses the columns argument when no select() was made', function () {
    $builder = recordingBuilder();

    $builder->find('abc', ['name', 'gtin']);

    expect($builder->connection->payloads[0]['_source_includes'])->toBe(['name', 'gtin']);
});

test('find accepts a single column as a string argument', function () {
    $builder = recordingBuilder();

    $builder->find('abc', 'name');

    expect($builder->connection->payloads[0]['_source_includes'])->toBe(['name']);
});

test('findMany carries an earlier select() into each doc', function () {
    $builder = recordingBuilder()->select(['name']);

    $builder->findMany(['a', 'b']);

    expect($builder->connection->payloads[0]['body']['docs'])->toBe([
        ['_index' => 'test_index', '_id' => 'a', '_source' => ['name']],
        ['_index' => 'test_index', '_id' => 'b', '_source' => ['name']],
    ]);
});
