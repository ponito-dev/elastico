<?php

namespace Elastico\Console\Cluster;

use Throwable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ClusterHealth extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'elastic:cluster:health
        {--connection=elastic : The DB Connection }
    ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Get cluster Health Information';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle(): int
    {
        $elastic = DB::connection($this->option('connection'))->getClient();

        try {
            $r = $elastic->cluster()->health();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return static::FAILURE;
        }

        foreach ($r->asArray() as $key => $value) {
            $this->line(sprintf('%s: %s', $key, is_scalar($value) ? $value : json_encode($value)));
        }

        return static::SUCCESS;
    }
}
