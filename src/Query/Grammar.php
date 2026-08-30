<?php

namespace Elastico\Query;

use stdClass;
use Exception;
use BackedEnum;
use UnitEnum;
use Stringable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Model as BaseModel;
use Elastico\Eloquent\Builder;
use Elastico\Mapping\RuntimeField;
use Illuminate\Support\Arr;
use Elastico\Query\Term\Term;
use Elastico\Query\Term\Range;
use Elastico\Query\Term\Terms;
use Elastico\Scripting\Script;
use Elastico\Query\Term\Exists;
use Elastico\Query\Term\Prefix;
use Elastico\Query\Term\Wildcard;
use Elastico\Query\Compound\Boolean;
use Elastico\Query\Compound\FunctionScore;
use Elastico\Query\Specialized\RankFeature;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as BaseBuilder;
use Illuminate\Database\Query\Grammars\Grammar as BaseGrammar;
use Elastico\Query\FullText\MatchQuery;
/*
 *  Elasticsearch Query Builder
 *  Extension of Larvel Database Query Builder.
 */

class Grammar extends BaseGrammar
{
    public function compileSelect(BaseBuilder $query)
    {
        return $this->buildPayload($query);
    }

    public function compileCount(BaseBuilder $query)
    {
        $compiled = $this->compileSelect($query);
        unset(
            $compiled['body']['sort'],
            $compiled['body']['aggregations'],
            $compiled['body']['aggs'],
            $compiled['body']['select'],
            $compiled['body']['_source'],
            $compiled['body']['size'],
            $compiled['body']['knn'],
            $compiled['body']['from'],
            $compiled['body']['post_filter'],
            $compiled['body']['suggest'],
            // _count rejects collapse; totals are uncollapsed counts, matching
            // hits.total on a collapsed search.
            $compiled['body']['collapse'],
        );

        return $compiled;
    }

    public function compileDelete(BaseBuilder $query)
    {
        $compiled = $this->compileSelect($query);

        // _delete_by_query rejects the search-only parts of the payload.
        unset(
            $compiled['seq_no_primary_term'],
            $compiled['body']['sort'],
            $compiled['body']['aggregations'],
            $compiled['body']['aggs'],
            $compiled['body']['_source'],
            $compiled['body']['knn'],
            $compiled['body']['from'],
            $compiled['body']['post_filter'],
            $compiled['body']['suggest'],
            $compiled['body']['collapse'],
        );

        // A query limit caps deletion via max_docs; body.size is rejected.
        if (isset($compiled['body']['size'])) {
            $compiled['max_docs'] = $compiled['body']['size'];
            unset($compiled['body']['size']);
        }

        return $compiled;
    }

    public function compileDeleteMany(BaseBuilder $query, iterable $ids)
    {
        /** @var Builder $query */

        return [
            'options' => [
                'ignore_conflicts' => $query->ignore_conflicts,
            ],
            'body' => collect($ids)
                ->flatMap(static fn($val): array => [
                    [
                        'delete' => [
                            '_id' => $val,
                            '_index' => $query->from,
                        ],
                    ],
                ])
                ->all(),

        ];
    }

    public function buildPayload(BaseBuilder $query): array
    {
        /** @var Builder $query */

        $payload['index'] = $query->from;

        $payload['seq_no_primary_term'] = true;

        if ($query->ignore_unavailable) {
            $payload['ignore_unavailable'] = true;
        }

        $payload['options'] = [
            'ignore_conflicts' => $query->ignore_conflicts,
        ];

        $payload['body']['from'] = $query->offset ?? null;

        $payload['body']['size'] = $query->limit ?? null;

        foreach ($query->ranks as $rank) {
            $query->where($rank[0], 'rank', $rank[1]);
        }

        $baseBool = $this->compileWhereComponents($query);

        if (!$baseBool->isEmpty()) {
            $payload['body']['query'] = $baseBool->compile();
        }

        $payload['body']['sort'] = $this->compileOrderComponents($query);

        $payload['body']['post_filter'] = $query->post_filter?->compile();

        if ($query->knn) {
            $payload['body']['knn'] = $query->knn;
        }

        if ($query->collapse) {
            $payload['body']['collapse'] = $query->collapse;
        }

        if ($query->suggest) {
            $payload['body']['suggest'] = $this->compileSuggestComponents($query);
        }

        if ($query->getRuntimeFields()) {
            $payload['body']['runtime_mappings'] = $query->getRuntimeFields()->map(static fn(RuntimeField $field) => $field->toArray())->all();
        }

        if (!empty($query->columns)) {
            $payload['body']['_source']['includes'] = $query->columns;
        }

        if (!empty($query->exclude_columns)) {
            $payload['body']['_source']['excludes'] = $query->exclude_columns;
        }

        $payload['body']['aggs'] = $query->getAggregations()
            ->map(fn($agg) => $agg->compile())
            ->all();

        $payload['body'] = collect($payload['body'])
            ->reject(fn($part) => null === $part)
            ->reject(fn($part) => [] === $part)
            ->all();

        // if ($query->collapse) {
        //     $payload['body']['collapse']['field'] = $query->collapse;
        // }

        // if (!empty($query->post_filter)) {
        //     $payload['body']['post_filter'] = $query->post_filter->compile();
        // }

        // if (!empty($query->filterPath)) {
        //     $payload['filter_path'] = $query->filterPath;
        // }

        // if ($query->profile) {
        //     $payload['body']['profile'] = true;
        // }

        // $query->buildSuggests();

        return $payload;
    }

    /**
     * Compile the random statement into SQL.
     *
     * @param string $seed
     *
     * @return string
     */
    public function compileRandom($seed)
    {
        return (new FunctionScore())->randomScore();
    }

    public function compileGet($from, $id, $columns)
    {
        return array_filter([
            'index' => $from,
            'id' => $id,
            '_source_includes' => $columns,
        ]);
    }

    public function compileFindMany($from, $ids, $columns)
    {
        return [
            'body' => [
                'docs' => collect($ids)
                    ->map(fn(string|int $id) => array_filter([
                        '_index' => $from,
                        '_id' => $id,
                        '_source' => $columns,
                    ]))
                    ->values()
                    ->all(),
            ],
        ];
    }

    public function compileSelectMany($queries)
    {
        return [
            'body' => collect($queries)
                ->flatMap(fn(BaseBuilder|Relation|Builder $query) => [
                    ['index' => $query->from ?? $query->getQuery()->from],
                    match (true) {
                        $query instanceof Builder => $query->toBase()->toSql()['body'],
                        $query instanceof BaseBuilder => $query->toSql()['body'],
                        $query instanceof Relation => $query->getBaseQuery()->toSql()['body'],
                    },
                ])
                ->all(),
        ];
    }

    /**
     * Compile an update statement into SQL.
     *
     * @return string
     */
    public function compileUpdate(BaseBuilder $query, array|Script $values)
    {
        /** @var Builder $query */

        return [
            'index' => $query->index_id,
            'id' => $query->model_id,
            // 'refresh' => $refresh,
            'body' => match (true) {
                $values instanceof Script => array_filter([
                    'script' => $values->compile(),
                    '_source' => $query->columns,
                ]),
                is_array($values) => array_filter([
                    'doc_as_upsert' => true,
                    'doc' => Arr::except($values, ['_id', '_index', '_seq_no', '_primary_term']),
                    // '_source' => $query->columns
                ]),
            }
        ];
    }

    public function compileUpdateByQuery(BaseBuilder $query, Script $script)
    {
        /** @var Builder $query */

        return [
            'index' => $query->from,
            'options' => [
                'ignore_conflicts' => $query->ignore_conflicts,
            ],
            'body' => array_filter([
                'script' => $script->compile(),
                'query' => $this->compileWhereComponents($query)->compile(),
            ]),
        ];
    }

    /**
     * Compile an insert statement into SQL.
     *
     * @return string
     */
    public function compileInsert(BaseBuilder $query, array $values)
    {
        // Essentially we will force every insert to be treated as a batch insert which
        // simply makes creating the SQL easier for us since we can utilize the same
        // basic routine regardless of an amount of records given to us to insert.

        /**  @var Builder $query*/
        return [
            'options' => [
                'ignore_conflicts' => $query->ignore_conflicts,
            ],
            'body' => collect($values)
                ->flatMap(static function (array $doc): array {
                    $index = Arr::pull($doc, '_index');
                    $id = Arr::pull($doc, '_id');

                    $method = empty($id) ? 'create' : 'index';

                    return [
                        [
                            $method => array_filter([
                                '_id' => $id,
                                '_index' => $index,
                            ]),
                        ],
                        $doc,
                    ];
                })
                ->all(),
        ];
    }

    /**
     * Compile an exists statement into SQL.
     *
     * @return string
     */
    public function compileExists(BaseBuilder $query)
    {
        $payload = $this->compileSelect((clone $query)->take(0));
        $payload['terminate_after'] = 1;

        return $payload;
    }

    public function compileSuggestComponents(BaseBuilder $query): array
    {
        $suggest = [];
        if (!empty($query->suggest)) {
            foreach ($query->suggest as $suggestion) {
                $suggest[$suggestion['name']] = [
                    'text' => $suggestion['text'],
                    $suggestion['type'] => array_filter([
                        'field' => $suggestion['field'],
                        'size' => $suggestion['size'],
                        'sort' => $suggestion['sort'],
                        'suggest_mode' => $suggestion['mode'],
                        'min_doc_freq' => $suggestion['min_doc_freq'],
                    ]),
                ];
            }
        }

        return $suggest;
    }

    public function compileOrderComponents(BaseBuilder $query): array
    {
        $sorts = [];
        if (!empty($query->orders)) {
            foreach ($query->orders as $order) {
                if (!empty($order['type']) && 'Raw' == $order['type']) {
                    // throw new \Exception('TODO: allow raw arrays');
                    if (is_array($order['sql'])) {
                        $sorts[] = $order['sql'];
                    } elseif ($order['sql'] instanceof Query) {
                        $sorts[] = $order['sql']->compile();
                    }
                } else {
                    $sorts[] = [
                        (string) $order['column'] => array_filter([
                            'order' => $order['direction'],
                            'missing' => $order['missing'] ?? null,
                            'mode' => $order['mode'] ?? null,
                            'nested' => $order['nested'] ?? null,
                        ]),
                    ];
                }
            }
        }

        return $sorts;
    }

    public function compileWhereComponents(BaseBuilder $query): Query
    {
        /** @var Builder $query */
        $bool = Boolean::make();

        // Consecutive AND-wheres form a group; each or/orNot where starts a
        // new group. Groups combine as bool.should, clauses within a group
        // combine as must/filter/must_not.
        $orWheres = collect($query->wheres)
            ->chunkWhile(fn ($where) => !str_starts_with($where['boolean'], 'or'));

        foreach ($orWheres as $whereGroup) {
            $groupBool = Boolean::make();

            foreach ($whereGroup as $where) {
                $negate = str_ends_with($where['boolean'], 'not');

                if ('raw' == $where['type']) {
                    if ($where['sql'] instanceof Query) {
                        $negate ? $groupBool->mustNot($where['sql']) : $groupBool->must($where['sql']);

                        continue;
                    }

                    throw new Exception('whereRaw() on the Elasticsearch grammar requires an Elastico\\Query\\Query object, got ' . get_debug_type($where['sql']) . '.');
                }

                if ('Nested' == $where['type']) {
                    $nested = $where['query']->getGrammar()->compileWhereComponents($where['query']);

                    $negate ? $groupBool->mustNot($nested) : $groupBool->must($nested);

                    continue;
                }

                if (in_array($where['type'], ['Null', 'NotNull'])) {
                    $exists = new Exists(field: $where['column']);

                    if (('NotNull' == $where['type']) xor $negate) {
                        $groupBool->filter($exists);
                    } else {
                        $groupBool->filter((new Boolean())->mustNot($exists));
                    }

                    continue;
                }

                $field = $where['column'] ?? null;

                if ('between' == $where['type']) {
                    $between = Boolean::make()
                        ->filter((new Range(field: $field))->gt($this->formatWhereValue($where['values'][0])))
                        ->filter((new Range(field: $field))->lt($this->formatWhereValue($where['values'][1])));

                    (($where['not'] ?? false) xor $negate) ? $groupBool->mustNot($between) : $groupBool->filter($between);

                    continue;
                }

                if (in_array($where['type'], ['In', 'NotIn', 'InRaw', 'NotInRaw'])) {
                    $notIn = str_starts_with($where['type'], 'Not') xor $negate;
                    $values = $this->formatWhereValue(array_values($where['values']));

                    if ([] === $values) {
                        // An empty allow-list matches nothing; an empty
                        // deny-list excludes nothing.
                        if (!$notIn) {
                            $groupBool->filter(new MatchNone());
                        }
                    } elseif ($notIn) {
                        $groupBool->mustNot(new Terms(field: $where['column'], values: $values));
                    } else {
                        $groupBool->filter(new Terms(field: $where['column'], values: $values));
                    }

                    continue;
                }

                if ('Date' == $where['type']) {
                    // Date-math rounding: gte/lt round down, gt/lte round up,
                    // so gte day .. lte day spans the whole day.
                    $day = $this->formatWhereValue($where['value']) . '||/d';

                    $clause = match ($where['operator']) {
                        '=', '<>', '!=' => (new Range(field: $field))->gte($day)->lte($day),
                        '>' => (new Range(field: $field))->gt($day),
                        '>=' => (new Range(field: $field))->gte($day),
                        '<' => (new Range(field: $field))->lt($day),
                        '<=' => (new Range(field: $field))->lte($day),
                        default => throw new Exception("Unsupported whereDate operator [{$where['operator']}] for the Elasticsearch grammar."),
                    };

                    if (in_array($where['operator'], ['<>', '!=']) xor $negate) {
                        $groupBool->mustNot($clause);
                    } else {
                        $groupBool->filter($clause);
                    }

                    continue;
                }

                if ('Basic' != $where['type']) {
                    throw new Exception("Unsupported where type [{$where['type']}] for the Elasticsearch grammar.");
                }

                $operator = $where['operator'];
                $value = $this->formatWhereValue($where['value']);

                $clause = match ($operator) {
                    '>' => (new Range(field: $field))->gt($value),
                    '>=' => (new Range(field: $field))->gte($value),
                    '<' => (new Range(field: $field))->lt($value),
                    '<=' => (new Range(field: $field))->lte($value),
                    '=', '<>', '!=' => is_array($value)
                        ? new Terms(field: $field, values: $value)
                        : new Term(field: $field, value: $value),
                    'like' => new MatchQuery(field: $field, query: is_string($value) ? trim($value, '%') : $value),
                    'rank' => new RankFeature(field: $field, boost: $value),
                    default => throw new Exception("Unsupported operator [{$operator}] for the Elasticsearch grammar."),
                };

                match (true) {
                    'rank' === $operator => $groupBool->should($clause),
                    $negate xor in_array($operator, ['<>', '!=']) => $groupBool->mustNot($clause),
                    in_array($operator, ['>', '>=', '<', '<=']) => $groupBool->filter($clause),
                    default => $groupBool->must($clause),
                };
            }

            // A group whose clauses all compiled away (e.g. an empty
            // whereNotIn) must not leave an empty should behind.
            if (!$groupBool->isEmpty()) {
                $bool->should($groupBool);
            }
        }

        return $bool;
    }

    /**
     * Normalize a where value for the DSL: models become keys, dates become
     * ISO-8601 strings, enums their value/name, collections plain arrays.
     */
    protected function formatWhereValue(mixed $value): mixed
    {
        if ($value instanceof Collection) {
            $value = $value->all();
        }

        if (is_array($value)) {
            return array_values(array_map($this->formatWhereValue(...), $value));
        }

        if (!is_object($value)) {
            return $value;
        }

        return match (true) {
            $value instanceof BaseModel => $value->getKey(),
            $value instanceof DateTimeInterface => $value->format(DateTimeInterface::ATOM),
            $value instanceof BackedEnum => $value->value,
            $value instanceof UnitEnum => $value->name,
            $value instanceof Stringable => (string) $value,
            default => $value,
        };
    }

    public function compileUpsert(BaseBuilder $query, array $values, array $uniqueBy, array|null $update)
    {

        /** @var Builder $query */

        return [
            'options' => [
                'ignore_conflicts' => $query->ignore_conflicts,
            ],
            'body' => collect($values)
                ->flatMap(static function (array $val, int $i) use ($update, $query): array {
                    $id = Arr::pull($val, '_id');
                    if (empty($id)) {
                        throw new Exception('All upserts must have an _id');
                    }

                    $header = [
                        'update' => collect([
                            '_id' => $id,
                            '_index' => Arr::pull($val, '_index'),
                            'if_seq_no' => Arr::pull($val, '_seq_no'),
                            'if_primary_term' => Arr::pull($val, '_primary_term'),
                        ])
                            ->filter(fn($v) => !is_null($v))
                            ->all(),
                    ];


                    $body = match (true) {
                        empty($update) => [
                            'doc' => empty($val) ? new stdClass() : $val,
                            'doc_as_upsert' => is_null($header['update']['if_seq_no'] ?? null),
                        ],
                        $update[$i] instanceof Script => collect([
                            'scripted_upsert' => is_null($header['update']['if_seq_no'] ?? null),
                            'script' => $update[$i]->compile(),
                            'upsert' =>  is_null($header['update']['if_seq_no'] ?? null) ? $val : null,
                        ])
                            ->filter(fn($v) => !is_null($v))
                            ->all(),
                        default => throw new Exception('TODO'),
                    };

                    if (!empty($query->columns)) {
                        $body['_source'] = ['*'] === $query->columns ? true : $query->columns;
                    }

                    return [$header, $body];
                })
                ->all(),
        ];
    }


    public function compileBulkOperation(
        BaseBuilder $query,
        iterable $models,
        string $operation,
        null|array $scripts = null,
        bool $doc_as_upsert = false,
        bool $scripted_upsert = false
    ): array {
        /** @var Builder $query */

        if (!in_array($operation, ['create', 'index', 'update', 'delete'])) {
            throw new Exception("Invalid Elastic operation [$operation]");
        }
        if ($scripts) {
            if (($operation !== 'update')) {
                throw new Exception('Script can only be used with update operation');
            }
            if (count($scripts) !== count($models)) {
                throw new Exception('There must be a script for each model');
            }
        }


        return [
            'options' => [
                'ignore_conflicts' => $query->ignore_conflicts,
            ],
            'body' => collect($models)
                ->flatMap(static function (string|array|object $model, $i) use ($query, $operation, $scripts, $doc_as_upsert, $scripted_upsert): array {
                    $id = is_string($model) ? $model : $model['_id'];

                    $document = Arr::except($model, ['_id', '_index', '_seq_no', '_primary_term']) ?: new stdClass();

                    $header = [
                        $operation => collect([
                            '_id' => $id,
                            '_index' => $model['_index'] ?? $query->from,
                            '_seq_no' => $model['_seq_no'] ?? null,
                            '_primary_term' => $model['_primary_term'] ?? null,
                        ])
                            ->filter(fn($v) => !is_null($v))
                            ->all(),
                    ];
                    if ($operation === 'delete') {
                        return [$header];
                    }

                    if (!empty($scripts)) {
                        $body = [
                            'scripted_upsert' => $scripted_upsert && is_null($model['_seq_no'] ?? null),
                            'script' => $scripts[$i]->compile(),
                            'upsert' => $document,
                        ];
                    } else {
                        $body = [
                            'doc_as_upsert' => $doc_as_upsert && is_null($model['_seq_no'] ?? null),
                            'doc' => $document,
                        ];
                    }

                    if ('update' === $operation && !empty($query->columns)) {
                        $body['_source'] = ['*'] === $query->columns ? true : $query->columns;
                    }

                    return [$header, $body];
                })
                ->all()
        ];
    }
}
