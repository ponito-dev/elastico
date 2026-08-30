<?php

test('insert and find by id round-trips correctly', function () {
    $this->insert([
        ['_id' => 'doc1', 'name' => 'Alice', 'status' => 'active'],
    ]);

    $hit = $this->builder()->find('doc1');

    expect($hit['_id'])->toBe('doc1');
    expect($hit['_source']['name'])->toBe('Alice');
    expect($hit['_source']['status'])->toBe('active');
});

test('insert without explicit id stores the document', function () {
    $this->insert([
        ['name' => 'Bob', 'status' => 'active'],
    ]);

    $results = $this->builder()->where('name', 'Bob')->get();

    expect($results)->toHaveCount(1);
});

test('upsert creates a new document when it does not exist', function () {
    $this->upsert([
        ['_id' => 'new1', 'name' => 'Charlie', 'status' => 'active'],
    ]);

    $hit = $this->builder()->find('new1');

    expect($hit['_id'])->toBe('new1');
    expect($hit['_source']['name'])->toBe('Charlie');
});

test('upsert updates an existing document', function () {
    $this->insert([
        ['_id' => 'upd1', 'name' => 'Dave', 'status' => 'active'],
    ]);

    $this->upsert([
        ['_id' => 'upd1', 'name' => 'Dave', 'status' => 'inactive'],
    ]);

    $hit = $this->builder()->find('upd1');

    expect($hit['_source']['status'])->toBe('inactive');
});

test('delete by id removes the document', function () {
    $this->insert([
        ['_id' => 'del1', 'name' => 'Eve', 'status' => 'active'],
        ['_id' => 'del2', 'name' => 'Frank', 'status' => 'active'],
    ]);

    $this->builder()->delete('del1');
    $this->connection->getClient()->indices()->refresh(['index' => static::INDEX]);

    $results = $this->builder()->get();

    expect($results)->toHaveCount(1);
    expect($results->first()['_id'])->toBe('del2');
});

test('count returns total matching documents', function () {
    $this->insert([
        ['_id' => '1', 'status' => 'active'],
        ['_id' => '2', 'status' => 'active'],
        ['_id' => '3', 'status' => 'inactive'],
    ]);

    expect($this->builder()->count())->toBe(3);
    expect($this->builder()->where('status', 'active')->count())->toBe(2);
});

test('total on collection reflects the full match count', function () {
    $this->insert([
        ['_id' => '1', 'status' => 'active'],
        ['_id' => '2', 'status' => 'active'],
        ['_id' => '3', 'status' => 'active'],
    ]);

    $results = $this->builder()->limit(1)->get();

    expect($results)->toHaveCount(1);
    expect($results->total())->toBe(3);
});
