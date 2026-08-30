<?php

namespace Elastico\Console\Indices;

use Illuminate\Console\Command;

class UpdateIndexSettings extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'elastic:index:updatesettings {index}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create an elasticsearch index';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $class = $this->argument('index');

        $config = $class::getIndexConfig()->toArray();

        $model = new $class();

        unset($config['body']['settings']['analysis']);
        unset($config['body']['settings']['index']['number_of_shards']);

        $model->getConnection()->getClient()->indices()->putSettings([
            'index' => $config['index'],
            'body' => $config['body']['settings'],
        ]);

        $this->info("{$class} Index Settings Updated");
    }
}
