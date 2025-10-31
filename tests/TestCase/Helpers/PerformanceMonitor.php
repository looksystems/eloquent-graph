<?php

namespace Tests\TestCase\Helpers;

use Laudis\Neo4j\Contracts\ClientInterface;

class PerformanceMonitor
{
    protected ClientInterface $neo4jClient;

    protected array $queryLog = [];

    protected bool $isLogging = false;

    protected float $startTime;

    protected array $memorySnapshots = [];

    public function __construct(ClientInterface $neo4jClient)
    {
        $this->neo4jClient = $neo4jClient;
    }

    /**
     * Start performance monitoring
     */
    public function start(): void
    {
        $this->isLogging = true;
        $this->startTime = microtime(true);
        $this->queryLog = [];
        $this->memorySnapshots = [];
        $this->addMemorySnapshot('start');
    }

    /**
     * Stop performance monitoring and return stats
     */
    public function stop(): array
    {
        if (! $this->isLogging) {
            return [];
        }

        $this->addMemorySnapshot('end');
        $this->isLogging = false;
        $endTime = microtime(true);

        return [
            'total_time' => $endTime - $this->startTime,
            'query_count' => count($this->queryLog),
            'queries' => $this->queryLog,
            'memory_usage' => $this->calculateMemoryUsage(),
            'slowest_query' => $this->getSlowestQuery(),
            'average_query_time' => $this->getAverageQueryTime(),
        ];
    }

    /**
     * Monitor execution of a specific operation
     */
    public function monitor(callable $operation, string $operationName = 'operation'): array
    {
        $this->start();

        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);

        try {
            $result = $operation();
            $success = true;
            $error = null;
        } catch (\Exception $e) {
            $result = null;
            $success = false;
            $error = $e->getMessage();
        }

        $endTime = microtime(true);
        $endMemory = memory_get_usage(true);

        $stats = $this->stop();

        return [
            'operation_name' => $operationName,
            'success' => $success,
            'error' => $error,
            'result' => $result,
            'execution_time' => $endTime - $startTime,
            'memory_used' => $endMemory - $startMemory,
            'peak_memory' => memory_get_peak_usage(true),
            'query_stats' => $stats,
        ];
    }

    /**
     * Log a query execution
     */
    public function logQuery(string $cypher, array $bindings = [], ?float $executionTime = null): void
    {
        if (! $this->isLogging) {
            return;
        }

        if ($executionTime === null) {
            $startTime = microtime(true);
            $this->neo4jClient->run($cypher, $bindings);
            $executionTime = microtime(true) - $startTime;
        }

        $this->queryLog[] = [
            'cypher' => $cypher,
            'bindings' => $bindings,
            'execution_time' => $executionTime,
            'timestamp' => microtime(true),
            'memory_at_execution' => memory_get_usage(true),
        ];
    }

    /**
     * Execute and monitor a specific query
     */
    public function executeAndMonitor(string $cypher, array $bindings = []): array
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);

        try {
            $result = $this->neo4jClient->run($cypher, $bindings);
            $success = true;
            $error = null;
        } catch (\Exception $e) {
            $result = null;
            $success = false;
            $error = $e->getMessage();
        }

        $endTime = microtime(true);
        $endMemory = memory_get_usage(true);
        $executionTime = $endTime - $startTime;

        $queryStats = [
            'cypher' => $cypher,
            'bindings' => $bindings,
            'execution_time' => $executionTime,
            'memory_used' => $endMemory - $startMemory,
            'success' => $success,
            'error' => $error,
            'result_count' => $success && $result ? $result->count() : 0,
        ];

        if ($this->isLogging) {
            $this->queryLog[] = $queryStats;
        }

        return $queryStats;
    }

    /**
     * Monitor memory usage during operation
     */
    public function monitorMemory(callable $operation, string $operationName = 'memory_test'): array
    {
        $startMemory = memory_get_usage(true);
        $startPeak = memory_get_peak_usage(true);

        $startTime = microtime(true);
        $result = $operation();
        $endTime = microtime(true);

        $endMemory = memory_get_usage(true);
        $endPeak = memory_get_peak_usage(true);

        return [
            'operation' => $operationName,
            'result' => $result,
            'execution_time' => $endTime - $startTime,
            'memory_before' => $startMemory,
            'memory_after' => $endMemory,
            'memory_used' => $endMemory - $startMemory,
            'peak_before' => $startPeak,
            'peak_after' => $endPeak,
            'peak_used' => $endPeak - $startPeak,
            'memory_mb' => ($endMemory - $startMemory) / 1024 / 1024,
            'peak_mb' => ($endPeak - $startPeak) / 1024 / 1024,
        ];
    }

    /**
     * Benchmark query performance across multiple runs
     */
    public function benchmarkQuery(string $cypher, array $bindings = [], int $iterations = 10): array
    {
        $times = [];
        $memoryUsages = [];
        $errors = 0;

        for ($i = 0; $i < $iterations; $i++) {
            $stats = $this->executeAndMonitor($cypher, $bindings);

            if ($stats['success']) {
                $times[] = $stats['execution_time'];
                $memoryUsages[] = $stats['memory_used'];
            } else {
                $errors++;
            }

            // Small delay to avoid overwhelming the database
            if ($i < $iterations - 1) {
                usleep(1000); // 1ms delay
            }
        }

        if (empty($times)) {
            return [
                'cypher' => $cypher,
                'iterations' => $iterations,
                'errors' => $errors,
                'success_rate' => 0,
                'error_message' => 'All queries failed',
            ];
        }

        sort($times);
        sort($memoryUsages);

        return [
            'cypher' => $cypher,
            'iterations' => $iterations,
            'successful_runs' => count($times),
            'errors' => $errors,
            'success_rate' => count($times) / $iterations,
            'timing' => [
                'min' => min($times),
                'max' => max($times),
                'average' => array_sum($times) / count($times),
                'median' => $times[intval(count($times) / 2)],
                'p95' => $times[intval(count($times) * 0.95)],
            ],
            'memory' => [
                'min' => min($memoryUsages),
                'max' => max($memoryUsages),
                'average' => array_sum($memoryUsages) / count($memoryUsages),
                'median' => $memoryUsages[intval(count($memoryUsages) / 2)],
            ],
        ];
    }

    /**
     * Monitor N+1 query problems
     */
    public function detectNPlusOne(callable $operation, int $expectedMaxQueries = 10): array
    {
        $this->start();

        $startTime = microtime(true);
        $result = $operation();
        $endTime = microtime(true);

        $stats = $this->stop();

        $isNPlusOne = $stats['query_count'] > $expectedMaxQueries;

        return [
            'result' => $result,
            'execution_time' => $endTime - $startTime,
            'query_count' => $stats['query_count'],
            'expected_max_queries' => $expectedMaxQueries,
            'has_n_plus_one' => $isNPlusOne,
            'queries' => $stats['queries'],
            'recommendation' => $isNPlusOne ? 'Consider using eager loading or batch queries' : 'Query count is acceptable',
        ];
    }

    /**
     * Monitor chunk processing performance
     */
    public function monitorChunkProcessing(callable $chunkOperation, int $totalItems, int $chunkSize): array
    {
        $chunks = ceil($totalItems / $chunkSize);
        $chunkStats = [];
        $totalTime = 0;
        $totalMemory = 0;

        for ($chunk = 0; $chunk < $chunks; $chunk++) {
            $chunkStart = microtime(true);
            $memoryBefore = memory_get_usage(true);

            $result = $chunkOperation($chunk, $chunkSize);

            $chunkEnd = microtime(true);
            $memoryAfter = memory_get_usage(true);

            $chunkTime = $chunkEnd - $chunkStart;
            $chunkMemory = $memoryAfter - $memoryBefore;

            $chunkStats[] = [
                'chunk' => $chunk,
                'time' => $chunkTime,
                'memory' => $chunkMemory,
                'items_processed' => min($chunkSize, $totalItems - ($chunk * $chunkSize)),
            ];

            $totalTime += $chunkTime;
            $totalMemory += $chunkMemory;
        }

        return [
            'total_items' => $totalItems,
            'chunk_size' => $chunkSize,
            'total_chunks' => $chunks,
            'total_time' => $totalTime,
            'total_memory' => $totalMemory,
            'average_chunk_time' => $totalTime / $chunks,
            'average_chunk_memory' => $totalMemory / $chunks,
            'chunks' => $chunkStats,
            'efficiency' => [
                'items_per_second' => $totalItems / $totalTime,
                'memory_per_item' => $totalMemory / $totalItems,
            ],
        ];
    }

    /**
     * Add memory snapshot
     */
    protected function addMemorySnapshot(string $label): void
    {
        $this->memorySnapshots[$label] = [
            'timestamp' => microtime(true),
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
        ];
    }

    /**
     * Calculate memory usage statistics
     */
    protected function calculateMemoryUsage(): array
    {
        if (count($this->memorySnapshots) < 2) {
            return [];
        }

        $start = $this->memorySnapshots['start'] ?? null;
        $end = $this->memorySnapshots['end'] ?? null;

        if (! $start || ! $end) {
            return [];
        }

        return [
            'start_memory' => $start['memory_usage'],
            'end_memory' => $end['memory_usage'],
            'memory_used' => $end['memory_usage'] - $start['memory_usage'],
            'peak_memory' => $end['peak_memory'],
            'memory_mb' => ($end['memory_usage'] - $start['memory_usage']) / 1024 / 1024,
        ];
    }

    /**
     * Get the slowest query from the log
     */
    protected function getSlowestQuery(): ?array
    {
        if (empty($this->queryLog)) {
            return null;
        }

        $slowest = null;
        $maxTime = 0;

        foreach ($this->queryLog as $query) {
            if ($query['execution_time'] > $maxTime) {
                $maxTime = $query['execution_time'];
                $slowest = $query;
            }
        }

        return $slowest;
    }

    /**
     * Calculate average query execution time
     */
    protected function getAverageQueryTime(): float
    {
        if (empty($this->queryLog)) {
            return 0;
        }

        $totalTime = array_sum(array_column($this->queryLog, 'execution_time'));

        return $totalTime / count($this->queryLog);
    }

    /**
     * Generate performance report
     */
    public function generateReport(array $stats): string
    {
        $report = "=== Performance Report ===\n\n";

        if (isset($stats['operation_name'])) {
            $report .= "Operation: {$stats['operation_name']}\n";
        }

        if (isset($stats['execution_time'])) {
            $report .= 'Execution Time: '.round($stats['execution_time'] * 1000, 2)."ms\n";
        }

        if (isset($stats['memory_mb'])) {
            $report .= 'Memory Used: '.round($stats['memory_mb'], 2)."MB\n";
        }

        if (isset($stats['query_stats']['query_count'])) {
            $report .= "Query Count: {$stats['query_stats']['query_count']}\n";
        }

        if (isset($stats['query_stats']['average_query_time'])) {
            $avgTime = round($stats['query_stats']['average_query_time'] * 1000, 2);
            $report .= "Average Query Time: {$avgTime}ms\n";
        }

        if (isset($stats['has_n_plus_one']) && $stats['has_n_plus_one']) {
            $report .= "\n⚠️  WARNING: Potential N+1 query problem detected!\n";
            $report .= "Recommendation: {$stats['recommendation']}\n";
        }

        if (isset($stats['success']) && ! $stats['success']) {
            $report .= "\n❌ Operation failed: {$stats['error']}\n";
        } else {
            $report .= "\n✅ Operation completed successfully\n";
        }

        return $report;
    }

    /**
     * Assert performance meets criteria
     */
    public function assertPerformance(array $stats, array $criteria): array
    {
        $assertions = [];

        if (isset($criteria['max_execution_time']) && isset($stats['execution_time'])) {
            $pass = $stats['execution_time'] <= $criteria['max_execution_time'];
            $assertions['execution_time'] = [
                'pass' => $pass,
                'actual' => $stats['execution_time'],
                'expected' => "<= {$criteria['max_execution_time']}",
                'message' => $pass ? 'Execution time within limits' : 'Execution time exceeded limit',
            ];
        }

        if (isset($criteria['max_memory_mb']) && isset($stats['memory_mb'])) {
            $pass = $stats['memory_mb'] <= $criteria['max_memory_mb'];
            $assertions['memory_usage'] = [
                'pass' => $pass,
                'actual' => $stats['memory_mb'],
                'expected' => "<= {$criteria['max_memory_mb']}MB",
                'message' => $pass ? 'Memory usage within limits' : 'Memory usage exceeded limit',
            ];
        }

        if (isset($criteria['max_queries']) && isset($stats['query_stats']['query_count'])) {
            $pass = $stats['query_stats']['query_count'] <= $criteria['max_queries'];
            $assertions['query_count'] = [
                'pass' => $pass,
                'actual' => $stats['query_stats']['query_count'],
                'expected' => "<= {$criteria['max_queries']}",
                'message' => $pass ? 'Query count within limits' : 'Too many queries executed',
            ];
        }

        return $assertions;
    }

    /**
     * Clear query log
     */
    public function clearLog(): void
    {
        $this->queryLog = [];
        $this->memorySnapshots = [];
    }

    /**
     * Get current query log
     */
    public function getQueryLog(): array
    {
        return $this->queryLog;
    }
}
