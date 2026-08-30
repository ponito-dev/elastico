<?php

namespace Elastico\Checks;

use Exception;
use Illuminate\Support\Facades\DB;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * Monitors Elasticsearch cluster health, node stats, and indexing performance.
 */
class ClusterCheck extends Check
{
    protected string $connection;

    public function connection(string $connection): static
    {
        $this->connection = $connection;
        $this->name($this->connection . ' Elasticsearch Cluster Health');

        return $this;
    }

    public function run(): Result
    {
        $result = Result::make();
        $client = DB::connection($this->connection)->getClient();

        try {
            // Fetch cluster-wide stats
            $clusterHealth = $client->cluster()->health()->asArray();
            $nodeStats = $client->nodes()->stats()->asArray();
            $indexStats = $client->indices()->stats()->asArray();
            $pendingTasks = $client->cluster()->pendingTasks()->asArray();
            $threadPool = $client->cat()->threadPool(['format' => 'json'])->asArray();

            $status = $clusterHealth['status']; // green, yellow, red
            $unassignedShards = $clusterHealth['unassigned_shards'] ?? 0;

            // Store the worst-case values across nodes
            $worstHeapUsed = 0;
            $worstCpuUsage = 0;
            $worstDiskUsage = 0;

            // Iterate over all nodes
            foreach ($nodeStats['nodes'] as $nodeId => $node) {
                $heapUsed = $node['jvm']['mem']['heap_used_percent'] ?? 0;
                $cpuUsage = $node['os']['cpu']['percent'] ?? 0;
                $diskAvailable = $node['fs']['total']['available_in_bytes'] ?? 0;
                $diskTotal = $node['fs']['total']['total_in_bytes'] ?? 1;
                $diskUsagePercent = 100 - (($diskAvailable / $diskTotal) * 100);

                // Keep track of the worst values among nodes
                $worstHeapUsed = max($worstHeapUsed, $heapUsed);
                $worstCpuUsage = max($worstCpuUsage, $cpuUsage);
                $worstDiskUsage = max($worstDiskUsage, $diskUsagePercent);
            }

            // Query & Indexing Performance
            $queryTime = $indexStats['indices']['_all']['search']['query_time_in_millis'] ?? 0;
            $queryCount = $indexStats['indices']['_all']['search']['query_total'] ?? 1;
            $avgQueryLatency = $queryCount ? ($queryTime / $queryCount) : 0;

            // Pending Tasks
            $pendingTasksCount = count($pendingTasks) ?? 0;

            // Thread Pool Rejections
            $searchQueue = collect($threadPool)->where('name', 'search')->first()['queue'] ?? 0;
            $bulkQueue = collect($threadPool)->where('name', 'bulk')->first()['queue'] ?? 0;

            // Build meta info
            $meta = [
                'cluster_status' => $status,
                'unassigned_shards' => $unassignedShards,
                'worst_heap_usage_percent' => $worstHeapUsed,
                'worst_cpu_usage_percent' => $worstCpuUsage,
                'worst_disk_usage_percent' => $worstDiskUsage,
                'avg_query_latency_ms' => $avgQueryLatency,
                'pending_tasks' => $pendingTasksCount,
                'search_queue' => $searchQueue,
                'bulk_queue' => $bulkQueue,
            ];

            $result->meta($meta);

            // Critical Issues (Fails the check)
            $criticalIssues = array_filter([
                $status === 'red'           ? "cluster status is red" : null,
                $worstHeapUsed > 90         ? "heap usage at {$worstHeapUsed}% (>90%)" : null,
                $worstCpuUsage > 80         ? "CPU usage at {$worstCpuUsage}% (>80%)" : null,
                $worstDiskUsage > 90        ? "disk usage at " . round($worstDiskUsage, 1) . "% (>90%)" : null,
                $avgQueryLatency > 500      ? "avg query latency at " . round($avgQueryLatency) . "ms (>500ms)" : null,
                $pendingTasksCount > 10     ? "{$pendingTasksCount} pending tasks (>10)" : null,
                $searchQueue > 50           ? "search queue depth at {$searchQueue} (>50)" : null,
                $bulkQueue > 50             ? "bulk queue depth at {$bulkQueue} (>50)" : null,
            ]);

            if (!empty($criticalIssues)) {
                return $result->failed("Critical: " . implode(', ', $criticalIssues) . ".");
            }

            // Warnings
            $warnings = array_filter([
                $status === 'yellow'        ? "cluster status is yellow" : null,
                $worstHeapUsed > 80         ? "heap usage at {$worstHeapUsed}% (>80%)" : null,
                $worstCpuUsage > 60         ? "CPU usage at {$worstCpuUsage}% (>60%)" : null,
                $worstDiskUsage > 80        ? "disk usage at " . round($worstDiskUsage, 1) . "% (>80%)" : null,
                $avgQueryLatency > 200      ? "avg query latency at " . round($avgQueryLatency) . "ms (>200ms)" : null,
                $pendingTasksCount > 5      ? "{$pendingTasksCount} pending tasks (>5)" : null,
                $searchQueue > 20           ? "search queue depth at {$searchQueue} (>20)" : null,
                $bulkQueue > 20             ? "bulk queue depth at {$bulkQueue} (>20)" : null,
            ]);

            if (!empty($warnings)) {
                return $result->warning("Warning: " . implode(', ', $warnings) . ".");
            }

            return $result->ok("Elasticsearch cluster is healthy.");
        } catch (Exception $e) {
            return $result->failed("Elasticsearch check failed: " . $e->getMessage());
        }
    }
}
