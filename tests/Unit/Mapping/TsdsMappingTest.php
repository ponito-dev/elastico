<?php

use Elastico\Index\Config;
use Elastico\Mapping\Field;

test('field serializes time_series_dimension', function () {
    expect(Field::make('keyword', 'offer_id')->dimension()->toArray())->toBe([
        'type' => 'keyword',
        'time_series_dimension' => true,
    ]);
});

test('field serializes time_series_metric', function () {
    expect(Field::make('float', 'price')->metric('gauge')->toArray())->toBe([
        'type' => 'float',
        'time_series_metric' => 'gauge',
    ]);
});

test('config carries time_series settings through toArray', function () {
    $config = Config::make('prices')
        ->settings([
            'index' => [
                'mode' => 'time_series',
                'routing_path' => ['offer_id'],
            ],
        ]);

    expect($config->toArray()['body']['settings'])->toBe([
        'index' => [
            'mode' => 'time_series',
            'routing_path' => ['offer_id'],
        ],
    ]);
});

test('adding the ILM name preserves sibling index settings like CreateDataStream does', function () {
    $template['template'] = Config::make('prices')
        ->settings(['index' => ['mode' => 'time_series', 'routing_path' => ['offer_id']]])
        ->toArray()['body'];

    $template['template']['settings']['index']['lifecycle']['name'] = 'prices-policy';

    expect($template['template']['settings']['index'])->toBe([
        'mode' => 'time_series',
        'routing_path' => ['offer_id'],
        'lifecycle' => ['name' => 'prices-policy'],
    ]);
});
