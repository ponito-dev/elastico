<?php

namespace Elastico\Console\ILM;

use Elastico\ILM\Policy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CreatePolicy extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'elastic:policy:create {policy}
        {--connection=elastic : Elasticsearch connection to use}
    ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new ILM policy';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $policy = $this->argument('policy');

        if (!is_a($policy, Policy::class, allow_string: true)) {
            $this->error('Policy must be a class of type ' . Policy::class);
            return;
        }

        $policy = new $policy();

        DB::connection($this->option('connection'))
            ->getClient()
            ->ilm()
            ->putLifecycle([
                'policy' => $policy->getName(),
                'body' => $policy->toArray(),
            ]);

        $this->info("ILM policy {$policy->getName()} created.");
    }
}
