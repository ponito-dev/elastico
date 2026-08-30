# Elastico

[![Latest Version on Packagist](https://img.shields.io/packagist/v/ponito/elastico.svg)](https://packagist.org/packages/ponito/elastico)
[![Total Downloads](https://img.shields.io/packagist/dt/ponito/elastico.svg)](https://packagist.org/packages/ponito/elastico)
[![License](https://img.shields.io/packagist/l/ponito/elastico.svg)](LICENSE)

Elasticsearch Eloquent ORM for Laravel. Extends Laravel's database layer so you can use Eloquent models, the query builder, aggregations, ILM policies, and scripted updates against Elasticsearch indices — with the same patterns you already know.

Elastico is extracted from and runs in production at [Ponito](https://www.ponito.com), a Swiss price comparison service, where it backs the product catalogue, faceted search, crawler queues and affiliate feed imports on an Elasticsearch 8 cluster.

## Requirements

- PHP 8.2+
- Laravel 11 or 12
- Elasticsearch 8.x

## Installation

```bash
composer require ponito/elastico
```

## Configuration

Add a connection to `config/database.php`:

```php
'connections' => [
    'elasticsearch' => [
        'driver' => 'elastic',
        'hosts'  => [env('ELASTICSEARCH_HOST', 'http://localhost:9200')],
        // Optional auth
        'username' => env('ELASTICSEARCH_USERNAME'),
        'password'  => env('ELASTICSEARCH_PASSWORD'),
        // Optional TLS
        'certificate' => env('ELASTICSEARCH_CERT'),
        // Optional Elastic Cloud
        'cloud' => env('ELASTIC_CLOUD_ID'),
    ],
],
```

## Eloquent Models

### Defining a model

```php
use Elastico\Eloquent\Model;
use Elastico\Index\Config;
use Elastico\Index\Mappings;
use Elastico\Mapping\Field;
use Elastico\Mapping\FieldType;

class Article extends Model
{
    protected $connection = 'elasticsearch';

    // The Elasticsearch index name
    protected $table = 'articles';

    protected $fillable = ['title', 'status', 'published_at', 'author_id', 'score'];

    public static function getIndexConfig(): Config
    {
        return Config::make('articles')
            ->mapping(fn(Mappings $m) => $m->properties(
                Field::make(FieldType::keyword, 'status'),
                Field::make(FieldType::keyword, 'author_id'),
                Field::make(FieldType::text,    'title'),
                Field::make(FieldType::date,    'published_at'),
                Field::make(FieldType::float,   'score'),
            ));
    }
}
```

### Creating and managing the index

```bash
php artisan elastic:index:create "App\Models\Article"
php artisan elastic:index:create "App\Models\Article" --fresh   # drop & recreate
php artisan elastic:index:delete "App\Models\Article"
```

### Basic CRUD

```php
// Create
$article = Article::create(['title' => 'Hello', 'status' => 'draft']);

// Find
$article = Article::find('doc-id');

// Update
$article->update(['status' => 'published']);

// Delete
$article->delete();

// Upsert (creates if missing, updates if present)
Article::upsert(
    [['_id' => 'doc-1', 'title' => 'Hello', 'status' => 'draft']],
    '_id'
);
```

## Query Builder

All standard Laravel builder methods work. Elastico adds ES-specific operators:

### Filtering

```php
// Equality → term query
Article::where('status', 'published')->get();

// Range → range query
Article::where('score', '>', 80)->get();
Article::where('score', '>=', 80)->where('score', '<', 100)->get();
Article::whereBetween('score', [80, 100])->get();   // exclusive bounds (gt/lt)

// Terms → terms query
Article::whereIn('status', ['published', 'featured'])->get();
Article::whereNotIn('status', ['deleted'])->get();

// Null / exists
Article::whereNull('published_at')->get();
Article::whereNotNull('published_at')->get();

// NOT equal
Article::where('status', '<>', 'deleted')->get();

// OR
Article::where('status', 'published')
       ->orWhere('status', 'featured')
       ->get();
```

### Full-text / like

```php
// like → match query (strips % wildcards)
Article::where('title', 'like', '%elasticsearch%')->get();
```

### Sorting

```php
Article::orderBy('published_at', 'desc')->get();

// With missing value handling
Article::orderBy('score', 'desc', missing: '_last')->get();

// With nested sort
Article::orderBy('comments.likes', 'desc', nested: ['path' => 'comments'])->get();
```

### Pagination and limits

```php
Article::limit(20)->offset(40)->get();
Article::paginate(20);

// Total hits are preserved even with limit
$results = Article::limit(10)->get();
$results->total(); // full match count
```

### Field selection

```php
Article::select(['title', 'status'])->get();

// Exclude fields
Article::exclude(['body', 'raw_html'])->get();
```

### Collapse

```php
// Return one result per unique author
Article::collapse('author_id')->get();
```

### Suggest

```php
Article::suggest(
    name:  'title_suggest',
    text:  'elastcsearch',
    field: 'title',
    type:  'term',
    size:  5,
)->get();
```

### KNN (vector search)

```php
Article::knn(
    field:      'embedding',
    vector:     [0.1, 0.2, ...],
    k:          10,
    candidates: 100,
    filter:     ['term' => ['status' => ['value' => 'published']]],
)->get();
```

### Runtime fields

```php
use Elastico\Mapping\RuntimeField;
use Elastico\Mapping\FieldType;

Article::runtimeField('day_of_week', new RuntimeField(
    type:   FieldType::keyword,
    script: "emit(doc['published_at'].value.dayOfWeekEnum.getDisplayName(TextStyle.FULL, Locale.ROOT))",
))->get();
```

### Cursor (point-in-time scrolling)

```php
// Lazy collection — streams all matching docs without memory overhead
Article::where('status', 'published')->cursor()->each(function ($article) {
    // process
});
```

### Multi-search

```php
// Execute multiple queries in a single HTTP round-trip
[$published, $drafts] = $builder->getMany([
    Article::where('status', 'published'),
    Article::where('status', 'draft'),
]);
```

### Counts and scalars

```php
Article::where('status', 'published')->count();
Article::where('status', 'published')->sum('score');
Article::where('status', 'published')->avg('score');
Article::where('status', 'published')->min('score');
Article::where('status', 'published')->max('score');
```

### Compiled query

```php
// Inspect the Elasticsearch DSL payload
$payload = Article::where('status', 'published')->toSql();
```

## Aggregations

Aggregations are added to the builder and accessed via the response collection.

### Metric aggregations

```php
use Elastico\Aggregations\Metric\Avg;
use Elastico\Aggregations\Metric\Sum;
use Elastico\Aggregations\Metric\Min;
use Elastico\Aggregations\Metric\Max;
use Elastico\Aggregations\Metric\Cardinality;
use Elastico\Aggregations\Metric\ValueCount;
use Elastico\Aggregations\Metric\Stats;
use Elastico\Aggregations\Metric\Percentiles;
use Elastico\Aggregations\Metric\TopHits;

$results = Article::where('status', 'published')
    ->addAggregation('avg_score',   new Avg('score'))
    ->addAggregation('total_views', new Sum('views'))
    ->addAggregation('authors',     new Cardinality('author_id'))
    ->limit(0)  // set to 0 if you only need aggregation results
    ->get();

$avgScore   = $results->aggregation('avg_score')->value();
$totalViews = $results->aggregation('total_views')->value();
$authCount  = $results->aggregation('authors')->value();
```

### Bucket aggregations

```php
use Elastico\Aggregations\Bucket\Terms;
use Elastico\Aggregations\Bucket\DateHistogram;
use Elastico\Aggregations\Bucket\Histogram;
use Elastico\Aggregations\Bucket\Range;
use Elastico\Aggregations\Bucket\Filter;
use Elastico\Aggregations\Bucket\Nested;

$results = Article::addAggregation(
    'by_status',
    new Terms('status', size: 10)
)->limit(0)->get();

foreach ($results->aggregation('by_status')->buckets() as $bucket) {
    echo "{$bucket['key']}: {$bucket['doc_count']}\n";
}
```

### Nested aggregations

```php
$byStatus = (new Terms('status', size: 10))
    ->addAggregation('avg_score', new Avg('score'));

$results = Article::addAggregation('by_status', $byStatus)->limit(0)->get();

foreach ($results->aggregation('by_status')->buckets() as $bucket) {
    $avg = $bucket->aggregation('avg_score')->value();
}
```

## Scripted updates

Update documents with Painless scripts instead of setting values directly.

### Increment a field

```php
use Elastico\Scripting\Increment;

$article->update(new Increment(field: 'views', amount: 1));

// With additional fields to set alongside
$article->update(new Increment(field: 'views', amount: 1, extra: ['last_viewed_at' => now()]));

// Decrement
Article::where('status', 'active')->decrement('priority', 5);
```

### Custom Painless script

```php
use Elastico\Scripting\UpdateParams;

$article->update(
    (new UpdateParams(['multiplier' => 2]))
        ->withModel(['score' => 100])  // merges with model fields
);
```

## Post-filter

Post-filter applies after aggregations are computed — useful for filtering results without affecting bucket counts:

```php
$results = Article::addAggregation('by_status', new Terms('status'))
    ->postWhere('status', 'published')  // filters hits but not aggs
    ->get();
```

## Index Lifecycle Management (ILM)

```php
use Elastico\ILM\Policy;
use Elastico\ILM\Phases\HotPhase;
use Elastico\ILM\Phases\WarmPhase;
use Elastico\ILM\Phases\DeletePhase;
use Elastico\ILM\Actions\RolloverAction;
use Elastico\ILM\Actions\ReadOnlyAction;
use Elastico\ILM\Actions\DeleteAction;
use Elastico\ILM\Actions\ForceMergeAction;

$policy = new Policy(
    id: 'articles-policy',
    hot_phase: new HotPhase(
        min_age: '0ms',
        rollover: new RolloverAction(max_age: '7d', max_size: '50gb'),
    ),
    warm_phase: new WarmPhase(
        min_age: '7d',
        read_only: new ReadOnlyAction(),
        force_merge: new ForceMergeAction(max_num_segments: 1),
    ),
    delete_phase: new DeletePhase(
        min_age: '90d',
        delete: new DeleteAction(),
    ),
);
```

## Relations

Elastico supports both Elasticsearch-native relations and hybrid relations to standard SQL models.

```php
class Article extends Model
{
    // Elasticsearch-to-Elasticsearch
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'article_id');
    }

    // Embedded documents
    public function metadata(): EmbedsOne
    {
        return $this->embedsOne(ArticleMetadata::class);
    }

    // Hybrid: Elasticsearch model → SQL model
    public function author(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'author_id');
    }
}
```

## Testing

```bash
# Unit and feature tests (no Elasticsearch required)
composer test

# Start Elasticsearch
docker compose up -d

# Full suite including integration tests
vendor/bin/pest
```

The test suite is split into three layers:

| Suite | What it tests | Needs ES? |
|---|---|---|
| Unit | DSL query and aggregation compilation | No |
| Feature | Grammar compilation (builder → ES payload) | No |
| Integration | Actual queries against a live index | Yes |

Integration tests skip automatically with a clear message when Elasticsearch is not reachable. Configure the host via `ELASTICSEARCH_HOST` env var (defaults to `http://localhost:9200`).
