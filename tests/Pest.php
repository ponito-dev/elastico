<?php

use Elastico\Connection;
use Elastico\Query\Builder;
use Elastico\Query\Grammar;
use Elastico\Query\Processor;

// Unit test helper — fake connection, no ES needed
function makeBuilder(string $index = 'test_index'): Builder
{
    $connection = new Connection(['database' => 'test_db']);
    $grammar = new Grammar($connection);
    $processor = new Processor();

    return (new Builder($connection, $grammar, $processor))->from($index);
}

// Bind the integration test case to all tests under Integration/
uses(Tests\Integration\IntegrationTestCase::class)->in('Integration');
