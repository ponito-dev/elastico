<?php

namespace Elastico\Console\DataStreams;

use Elastico\Eloquent\DataStream;
use Illuminate\Console\Command;
use stdClass;

class UpdateDataStream extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'elastic:datastream:update {index}
                                {--connection= : Elasticsearch connection}
                                ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update an existing data stream: ILM policy, index template, write-index mapping and backing-index lifecycle settings';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $class = $this->argument('index');

        $model = new $class;

        if (!$model instanceof DataStream) {
            return $this->error("{$class} is not a DataStream");
        }

        if ($this->option('connection')) {
            $model->setConnection($this->option('connection'));
        }

        $client = $model->getConnection()->getClient();

        $policy = $model->getILMPolicy();

        $client->ilm()->putLifecycle([
            'policy' => $policy->getName(),
            'body' => $policy->toArray(),
        ]);

        $config = $model::getIndexConfig()->toArray();

        $template = [];
        $template['index_patterns'] = [$model->getTable() . '*'];
        $template['data_stream'] = new stdClass();
        $template['priority'] = '300'; // higher than 200 avoid collision with builtin templates
        $template['template'] = $config['body'];

        if (($template['template']['settings'] ?? null) == new stdClass) {
            unset($template['template']['settings']);
        }
        $template['template']['settings']['index']['lifecycle']['name'] = $policy->getName();

        $client->indices()->putIndexTemplate([
            'name' => $model->getTable(),
            'body' => $template,
        ]);

        // New backing indices pick the template up on rollover; the write
        // index gets the mapping and every backing index the lifecycle now.
        $client->indices()->putMapping([
            'index' => $model->getTable(),
            'body' => $config['body']['mappings'],
        ]);

        $client->indices()->putSettings([
            'index' => $model->getTable(),
            'body' => ['index' => ['lifecycle' => ['name' => $policy->getName()]]],
        ]);

        return $this->info("{$class} DataStream Updated");
    }
}
