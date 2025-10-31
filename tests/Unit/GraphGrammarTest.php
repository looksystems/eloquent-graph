<?php

namespace Tests\Unit;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Expression;
use Mockery;
use PHPUnit\Framework\TestCase;

class Neo4jGrammarTest extends TestCase
{
    private \Look\EloquentCypher\GraphGrammar $grammar;

    protected function setUp(): void
    {
        parent::setUp();
        $connection = Mockery::mock(Connection::class);
        $this->grammar = new \Look\EloquentCypher\GraphGrammar($connection);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }

    /**
     * Test expression unwrapping - the core functionality
     */
    public function test_expression_unwrapping_single_level(): void
    {
        $expression = new Expression('MATCH (n) RETURN n');
        $result = $this->grammar->getValue($expression);
        $this->assertEquals('MATCH (n) RETURN n', $result);
    }

    public function test_nested_expression_unwrapping_multiple_levels(): void
    {
        $innerExpression = new Expression('count(*)');
        $middleExpression = new Expression($innerExpression);
        $outerExpression = new Expression($middleExpression);

        $result = $this->grammar->getValue($outerExpression);
        $this->assertEquals('count(*)', $result);
    }

    public function test_scalar_passthrough_for_string_int_float(): void
    {
        // Test that scalars pass through unchanged
        $this->assertEquals('test_string', $this->grammar->getValue('test_string'));
        $this->assertEquals(42, $this->grammar->getValue(42));
        $this->assertEquals(3.14, $this->grammar->getValue(3.14));
    }

    public function test_null_handling_returns_null(): void
    {
        $result = $this->grammar->getValue(null);
        $this->assertNull($result);
    }

    public function test_deeply_nested_expressions_unwrap_correctly(): void
    {
        // Test complex nested expression with actual Cypher patterns
        $deepExpression = new Expression(
            new Expression(
                new Expression('(n.views + n.likes) / n.followers as engagement_rate')
            )
        );

        $result = $this->grammar->getValue($deepExpression);
        $this->assertEquals('(n.views + n.likes) / n.followers as engagement_rate', $result);
    }
}
