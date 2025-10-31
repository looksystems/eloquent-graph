<?php

namespace Tests\Unit\Exceptions;

use PHPUnit\Framework\TestCase;

class Neo4jExceptionTest extends TestCase
{
    /**
     * Test basic exception creation
     */
    public function test_create_exception(): void
    {
        $exception = new \Look\EloquentCypher\Exceptions\GraphException('Test error message');

        $this->assertInstanceOf(\Exception::class, $exception);
        $this->assertEquals('Test error message', $exception->getMessage());
    }

    public function test_create_exception_with_code(): void
    {
        $exception = new \Look\EloquentCypher\Exceptions\GraphException('Error', 500);

        $this->assertEquals('Error', $exception->getMessage());
        $this->assertEquals(500, $exception->getCode());
    }

    public function test_create_exception_with_previous(): void
    {
        $previous = new \Exception('Previous error');
        $exception = new \Look\EloquentCypher\Exceptions\GraphException('Current error', 0, $previous);

        $this->assertSame($previous, $exception->getPrevious());
    }

    /**
     * Test Cypher query handling
     */
    public function test_set_and_get_cypher(): void
    {
        $exception = new \Look\EloquentCypher\Exceptions\GraphException('Error');
        $cypher = 'MATCH (n:User) WHERE n.id = $id RETURN n';

        $exception->setCypher($cypher);

        $this->assertEquals($cypher, $exception->getCypher());
    }

    public function test_cypher_is_null_by_default(): void
    {
        $exception = new \Look\EloquentCypher\Exceptions\GraphException('Error');

        $this->assertNull($exception->getCypher());
    }

    public function test_fluent_cypher_setter(): void
    {
        $exception = new \Look\EloquentCypher\Exceptions\GraphException('Error');

        $result = $exception->setCypher('MATCH (n) RETURN n');

        $this->assertSame($exception, $result);
    }

    /**
     * Test parameters handling
     */
    public function test_set_and_get_parameters(): void
    {
        $exception = new \Look\EloquentCypher\Exceptions\GraphException('Error');
        $parameters = ['id' => 123, 'name' => 'John'];

        $exception->setParameters($parameters);

        $this->assertEquals($parameters, $exception->getParameters());
    }

    public function test_parameters_empty_by_default(): void
    {
        $exception = new \Look\EloquentCypher\Exceptions\GraphException('Error');

        $this->assertEquals([], $exception->getParameters());
    }

    public function test_fluent_parameters_setter(): void
    {
        $exception = new \Look\EloquentCypher\Exceptions\GraphException('Error');

        $result = $exception->setParameters(['test' => 'value']);

        $this->assertSame($exception, $result);
    }

    /**
     * Test migration hint handling
     */
    public function test_set_and_get_migration_hint(): void
    {
        $exception = new \Look\EloquentCypher\Exceptions\GraphException('Error');
        $hint = 'Consider using MERGE instead of INSERT ON DUPLICATE KEY UPDATE';

        $exception->setMigrationHint($hint);

        $this->assertEquals($hint, $exception->getMigrationHint());
    }

    public function test_migration_hint_is_null_by_default(): void
    {
        $exception = new \Look\EloquentCypher\Exceptions\GraphException('Error');

        $this->assertNull($exception->getMigrationHint());
    }

    public function test_fluent_migration_hint_setter(): void
    {
        $exception = new \Look\EloquentCypher\Exceptions\GraphException('Error');

        $result = $exception->setMigrationHint('Use pattern matching');

        $this->assertSame($exception, $result);
    }

    /**
     * Test detailed message generation
     */
    public function test_get_detailed_message_basic(): void
    {
        $exception = new \Look\EloquentCypher\Exceptions\GraphException('Basic error');

        $detailed = $exception->getDetailedMessage();

        $this->assertEquals('Basic error', $detailed);
    }

    public function test_get_detailed_message_with_migration_hint(): void
    {
        $exception = new \Look\EloquentCypher\Exceptions\GraphException('Error');
        $exception->setMigrationHint('Use MERGE for upsert operations');

        $detailed = $exception->getDetailedMessage();

        $this->assertStringContainsString('Error', $detailed);
        $this->assertStringContainsString('Migration Hint:', $detailed);
        $this->assertStringContainsString('Use MERGE for upsert operations', $detailed);
    }

    public function test_get_detailed_message_with_cypher(): void
    {
        $exception = new \Look\EloquentCypher\Exceptions\GraphException('Query failed');
        $exception->setCypher('MATCH (n:User) WHERE n.id = $id RETURN n');

        $detailed = $exception->getDetailedMessage();

        $this->assertStringContainsString('Query failed', $detailed);
        $this->assertStringContainsString('Cypher Query:', $detailed);
        $this->assertStringContainsString('MATCH (n:User)', $detailed);
    }

    public function test_get_detailed_message_with_parameters(): void
    {
        $exception = new \Look\EloquentCypher\Exceptions\GraphException('Parameter error');
        $exception->setParameters(['id' => 123, 'name' => 'John']);

        $detailed = $exception->getDetailedMessage();

        $this->assertStringContainsString('Parameter error', $detailed);
        $this->assertStringContainsString('Parameters:', $detailed);
        $this->assertStringContainsString('"id": 123', $detailed);
        $this->assertStringContainsString('"name": "John"', $detailed);
    }

    public function test_get_detailed_message_complete(): void
    {
        $exception = new \Look\EloquentCypher\Exceptions\GraphException('Complete error');
        $exception->setMigrationHint('Use pattern matching instead of joins')
            ->setCypher('MATCH (u:User)-[:POSTED]->(p:Post) RETURN u, p')
            ->setParameters(['userId' => 1, 'limit' => 10]);

        $detailed = $exception->getDetailedMessage();

        // Check all components are present
        $this->assertStringContainsString('Complete error', $detailed);
        $this->assertStringContainsString('Migration Hint:', $detailed);
        $this->assertStringContainsString('Use pattern matching', $detailed);
        $this->assertStringContainsString('Cypher Query:', $detailed);
        $this->assertStringContainsString('MATCH (u:User)', $detailed);
        $this->assertStringContainsString('Parameters:', $detailed);
        $this->assertStringContainsString('"userId": 1', $detailed);
        $this->assertStringContainsString('"limit": 10', $detailed);
    }

    /**
     * Test method chaining
     */
    public function test_method_chaining(): void
    {
        $exception = (new \Look\EloquentCypher\Exceptions\GraphException('Chained error'))
            ->setCypher('MATCH (n) RETURN n')
            ->setParameters(['id' => 1])
            ->setMigrationHint('Use Neo4j patterns');

        $this->assertEquals('MATCH (n) RETURN n', $exception->getCypher());
        $this->assertEquals(['id' => 1], $exception->getParameters());
        $this->assertEquals('Use Neo4j patterns', $exception->getMigrationHint());
    }

    /**
     * Test edge cases
     */
    public function test_empty_message(): void
    {
        $exception = new \Look\EloquentCypher\Exceptions\GraphException('');

        $this->assertEquals('', $exception->getMessage());
        $this->assertEquals('', $exception->getDetailedMessage());
    }

    public function test_empty_parameters(): void
    {
        $exception = new \Look\EloquentCypher\Exceptions\GraphException('Error');
        $exception->setParameters([]);

        $detailed = $exception->getDetailedMessage();

        // Empty parameters should not be shown
        $this->assertStringNotContainsString('Parameters:', $detailed);
    }

    public function test_complex_parameters_formatting(): void
    {
        $exception = new \Look\EloquentCypher\Exceptions\GraphException('Error');
        $exception->setParameters([
            'nested' => [
                'array' => [
                    'value1',
                    'value2',
                ],
            ],
            'boolean' => true,
            'null' => null,
            'float' => 3.14,
        ]);

        $detailed = $exception->getDetailedMessage();

        // Check JSON formatting is correct
        $this->assertStringContainsString('"nested"', $detailed);
        $this->assertStringContainsString('"array"', $detailed);
        $this->assertStringContainsString('"value1"', $detailed);
        $this->assertStringContainsString('true', $detailed);
        $this->assertStringContainsString('null', $detailed);
        $this->assertStringContainsString('3.14', $detailed);
    }

    public function test_long_cypher_query(): void
    {
        $longQuery = "MATCH (u:User {id: \$userId})\n".
                    "OPTIONAL MATCH (u)-[:FOLLOWS]->(f:User)\n".
                    "OPTIONAL MATCH (u)-[:POSTED]->(p:Post)\n".
                    "OPTIONAL MATCH (p)-[:TAGGED]->(t:Tag)\n".
                    'RETURN u, collect(DISTINCT f) as following, '.
                    'collect(DISTINCT p) as posts, collect(DISTINCT t) as tags';

        $exception = new \Look\EloquentCypher\Exceptions\GraphException('Complex query failed');
        $exception->setCypher($longQuery);

        $detailed = $exception->getDetailedMessage();

        // Check that the full query is included
        $this->assertStringContainsString('MATCH (u:User', $detailed);
        $this->assertStringContainsString('OPTIONAL MATCH', $detailed);
        $this->assertStringContainsString('collect(DISTINCT', $detailed);
    }
}
