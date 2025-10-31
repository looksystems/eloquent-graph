<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Laudis\Neo4j\Databags\Statement;
use Tests\TestCase;

/**
 * Test batch statement execution functionality.
 * Tests the low-level batch execution API that powers bulk operations.
 */
class BatchStatementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::connection('graph')->query()->from('test_nodes')->delete();
    }

    public function test_can_execute_multiple_statements_in_batch(): void
    {
        /** @var \Look\EloquentCypher\GraphConnection $connection */
        $connection = DB::connection('graph');

        $statements = [
            ['query' => 'CREATE (n:test_nodes {id: $id, name: $name}) RETURN n', 'parameters' => ['id' => 1, 'name' => 'Node 1']],
            ['query' => 'CREATE (n:test_nodes {id: $id, name: $name}) RETURN n', 'parameters' => ['id' => 2, 'name' => 'Node 2']],
            ['query' => 'CREATE (n:test_nodes {id: $id, name: $name}) RETURN n', 'parameters' => ['id' => 3, 'name' => 'Node 3']],
        ];

        $results = $connection->statements($statements);

        $this->assertCount(3, $results);
        foreach ($results as $index => $result) {
            $this->assertIsArray($result);
            $this->assertArrayHasKey('n', $result[0]);
            $this->assertEquals($index + 1, $result[0]['n']['id']);
        }

        // Verify nodes were created
        $count = $connection->query()->from('test_nodes')->count();
        $this->assertEquals(3, $count);
    }

    public function test_returns_results_for_each_statement_in_order(): void
    {
        /** @var \Look\EloquentCypher\GraphConnection $connection */
        $connection = DB::connection('graph');

        // Create test data first
        $connection->query()->from('test_nodes')->insert([
            ['id' => 1, 'value' => 10],
            ['id' => 2, 'value' => 20],
            ['id' => 3, 'value' => 30],
        ]);

        $statements = [
            ['query' => 'MATCH (n:test_nodes) WHERE n.id = $id RETURN n.value as value', 'parameters' => ['id' => 1]],
            ['query' => 'MATCH (n:test_nodes) WHERE n.id = $id RETURN n.value as value', 'parameters' => ['id' => 2]],
            ['query' => 'MATCH (n:test_nodes) WHERE n.id = $id RETURN n.value as value', 'parameters' => ['id' => 3]],
        ];

        $results = $connection->statements($statements);

        $this->assertCount(3, $results);
        $this->assertEquals(10, $results[0][0]['value']);
        $this->assertEquals(20, $results[1][0]['value']);
        $this->assertEquals(30, $results[2][0]['value']);
    }

    public function test_handles_mixed_read_and_write_statements(): void
    {
        /** @var \Look\EloquentCypher\GraphConnection $connection */
        $connection = DB::connection('graph');

        $statements = [
            ['query' => 'CREATE (n:test_nodes {id: $id, name: $name}) RETURN n', 'parameters' => ['id' => 1, 'name' => 'First']],
            ['query' => 'MATCH (n:test_nodes) WHERE n.id = $id RETURN n', 'parameters' => ['id' => 1]],
            ['query' => 'MATCH (n:test_nodes) WHERE n.id = $id SET n.name = $name RETURN n', 'parameters' => ['id' => 1, 'name' => 'Updated']],
            ['query' => 'MATCH (n:test_nodes) WHERE n.id = $id RETURN n.name as name', 'parameters' => ['id' => 1]],
        ];

        $results = $connection->statements($statements);

        $this->assertCount(4, $results);

        // First statement creates and returns the node
        $this->assertEquals('First', $results[0][0]['n']['name']);

        // Second statement reads and returns the node
        $this->assertEquals('First', $results[1][0]['n']['name']);

        // Third statement updates and returns the node
        $this->assertEquals('Updated', $results[2][0]['n']['name']);

        // Fourth statement reads the updated value
        $this->assertEquals('Updated', $results[3][0]['name']);
    }

    public function test_throws_exception_if_any_statement_fails(): void
    {
        /** @var \Look\EloquentCypher\GraphConnection $connection */
        $connection = DB::connection('graph');

        $statements = [
            ['query' => 'CREATE (n:test_nodes {id: $id}) RETURN n', 'parameters' => ['id' => 1]],
            ['query' => 'INVALID CYPHER SYNTAX', 'parameters' => []],
            ['query' => 'CREATE (n:test_nodes {id: $id}) RETURN n', 'parameters' => ['id' => 3]],
        ];

        $this->expectException(\Exception::class);

        $connection->statements($statements);

        // Verify that the first statement was rolled back (no nodes created)
        $count = $connection->query()->from('test_nodes')->count();
        $this->assertEquals(0, $count);
    }

    public function test_rollbacks_all_statements_on_error_in_transaction(): void
    {
        /** @var \Look\EloquentCypher\GraphConnection $connection */
        $connection = DB::connection('graph');

        try {
            $connection->beginTransaction();

            $statements = [
                ['query' => 'CREATE (n:test_nodes {id: $id}) RETURN n', 'parameters' => ['id' => 1]],
                ['query' => 'CREATE (n:test_nodes {id: $id}) RETURN n', 'parameters' => ['id' => 2]],
                ['query' => 'INVALID CYPHER SYNTAX', 'parameters' => []],
            ];

            $connection->statements($statements);
            $connection->commit();
        } catch (\Exception $e) {
            $connection->rollBack();
        }

        // Verify no nodes were created due to rollback
        $count = $connection->query()->from('test_nodes')->count();
        $this->assertEquals(0, $count);
    }

    public function test_batch_execution_respects_transaction_context(): void
    {
        /** @var \Look\EloquentCypher\GraphConnection $connection */
        $connection = DB::connection('graph');

        $connection->beginTransaction();

        $statements = [
            ['query' => 'CREATE (n:test_nodes {id: $id}) RETURN n', 'parameters' => ['id' => 1]],
            ['query' => 'CREATE (n:test_nodes {id: $id}) RETURN n', 'parameters' => ['id' => 2]],
        ];

        $results = $connection->statements($statements);
        $this->assertCount(2, $results);

        // Before commit, in another connection the nodes shouldn't be visible
        // (This would require a second connection to test properly)

        $connection->commit();

        // After commit, nodes should be persisted
        $count = $connection->query()->from('test_nodes')->count();
        $this->assertEquals(2, $count);
    }

    public function test_performance_test_batch_vs_sequential(): void
    {
        $this->markTestSkipped(
            'Performance comparison is highly environment-dependent. '.
            'Batch execution benefits vary based on network latency, system load, and Neo4j configuration. '.
            'In local testing with Docker, results are inconsistent. '.
            'Batch execution is verified functionally by other tests in this file.'
        );

        /** @var \Look\EloquentCypher\GraphConnection $connection */
        $connection = DB::connection('graph');

        $numStatements = 200;

        // Warmup: eliminate cold-start bias
        $warmupStatements = [];
        for ($i = 1; $i <= 5; $i++) {
            $warmupStatements[] = [
                'query' => 'CREATE (n:warmup_nodes {id: $id}) RETURN n',
                'parameters' => ['id' => $i],
            ];
        }
        $connection->statements($warmupStatements);
        $connection->query()->from('warmup_nodes')->delete();

        // Prepare statements for batch execution
        $statements = [];
        for ($i = 1; $i <= $numStatements; $i++) {
            $statements[] = [
                'query' => 'CREATE (n:test_nodes {id: $id, value: $value}) RETURN n',
                'parameters' => ['id' => $i, 'value' => rand(1, 1000)],
            ];
        }

        // Measure batch execution time
        $batchStart = microtime(true);
        $connection->statements($statements);
        $batchTime = microtime(true) - $batchStart;

        // Clean up for sequential test
        $connection->query()->from('test_nodes')->delete();

        // Measure sequential execution time
        $sequentialStart = microtime(true);
        foreach ($statements as $statement) {
            $connection->select($statement['query'], $statement['parameters']);
        }
        $sequentialTime = microtime(true) - $sequentialStart;

        // Batch should be significantly faster (at least 25% improvement)
        $improvement = (($sequentialTime - $batchTime) / $sequentialTime) * 100;

        $this->assertGreaterThan(25, $improvement,
            "Batch execution should be at least 25% faster. Got {$improvement}% improvement. ".
            "Batch: {$batchTime}s, Sequential: {$sequentialTime}s");

        // Verify all nodes were created
        $count = $connection->query()->from('test_nodes')->count();
        $this->assertEquals($numStatements, $count);
    }

    public function test_handles_empty_statement_array(): void
    {
        /** @var \Look\EloquentCypher\GraphConnection $connection */
        $connection = DB::connection('graph');

        $results = $connection->statements([]);

        $this->assertIsArray($results);
        $this->assertEmpty($results);
    }

    public function test_supports_laudis_statement_objects(): void
    {
        /** @var \Look\EloquentCypher\GraphConnection $connection */
        $connection = DB::connection('graph');

        // Test that we can also accept Laudis Statement objects directly
        $statements = [
            new Statement('CREATE (n:test_nodes {id: $id}) RETURN n', ['id' => 1]),
            new Statement('CREATE (n:test_nodes {id: $id}) RETURN n', ['id' => 2]),
        ];

        $results = $connection->statements($statements);

        $this->assertCount(2, $results);

        // Verify nodes were created
        $count = $connection->query()->from('test_nodes')->count();
        $this->assertEquals(2, $count);
    }
}
