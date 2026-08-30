<?php

namespace Elastico\Console\Indices;

use Illuminate\Console\Command;
use Elastico\Eloquent\DataStream;
use Elastico\Exceptions\IndexNotFoundException;
use Illuminate\Support\Facades\DB;
use Elastic\Elasticsearch\Exception\ClientResponseException;

class DeleteIndex extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'elastic:index:delete {index : The index class or name}
                                {--connection= : Elasticsearch connection (required with a raw index name)}
                                {--force : Skip confirmation}
                                ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete an elasticsearch index';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $class = $this->argument('index');

        if (class_exists($class)) {
            $model = new $class();

            if ($model instanceof DataStream) {
                return $this->call('elastic:datastream:delete',  [
                    'index' => $class,
                    '--connection' => $this->option('connection'),
                    '--force' => $this->option('force'),
                ]);
            }

            $indexName = $model->getTable();
            $connection = $model->getConnection();
        } else {
            if (!$this->option('connection')) {
                $this->error('Pass --connection when deleting by raw index name.');

                return static::FAILURE;
            }

            $indexName = $class;
            $connection = DB::connection($this->option('connection'));
        }

        if ($this->option('force') || $this->confirm('Are you sure you want to delete this index?')) {

            try {
                $connection->getClient()->indices()->delete(['index' => $indexName]);
            } catch (IndexNotFoundException) {
                $this->error('Index not found');
            } catch (ClientResponseException $e) {
                if (str_contains($e->getMessage(), 'index_not_found_exception')) {
                    $this->error('Index not found');
                } else {

                    throw $e;
                }
            }
        }
    }
}
