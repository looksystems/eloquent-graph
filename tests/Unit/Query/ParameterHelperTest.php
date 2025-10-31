<?php

namespace Tests\Unit\Query;

use Laudis\Neo4j\Types\CypherList;
use Laudis\Neo4j\Types\CypherMap;
use Look\EloquentCypher\Query\ParameterHelper;
use Tests\TestCase;

class ParameterHelperTest extends TestCase
{
    public function test_converts_empty_array_to_cypher_list(): void
    {
        $result = ParameterHelper::ensureList([]);

        $this->assertInstanceOf(CypherList::class, $result);
        $this->assertCount(0, $result);
    }

    public function test_converts_indexed_array_to_cypher_list(): void
    {
        $input = ['apple', 'banana', 'cherry'];
        $result = ParameterHelper::ensureList($input);

        $this->assertInstanceOf(CypherList::class, $result);
        $this->assertCount(3, $result);
        $this->assertEquals('apple', $result[0]);
        $this->assertEquals('banana', $result[1]);
        $this->assertEquals('cherry', $result[2]);
    }

    public function test_converts_empty_array_to_cypher_map(): void
    {
        $result = ParameterHelper::ensureMap([]);

        $this->assertInstanceOf(CypherMap::class, $result);
        $this->assertCount(0, $result);
    }

    public function test_converts_associative_array_to_cypher_map(): void
    {
        $input = ['name' => 'John', 'age' => 30, 'city' => 'London'];
        $result = ParameterHelper::ensureMap($input);

        $this->assertInstanceOf(CypherMap::class, $result);
        $this->assertCount(3, $result);
        $this->assertEquals('John', $result->get('name'));
        $this->assertEquals(30, $result->get('age'));
        $this->assertEquals('London', $result->get('city'));
    }

    public function test_smart_convert_auto_detects_indexed_arrays_as_lists(): void
    {
        $input = [1, 2, 3, 4, 5];
        $result = ParameterHelper::smartConvert($input);

        $this->assertInstanceOf(CypherList::class, $result);
        $this->assertCount(5, $result);
        $this->assertEquals(1, $result[0]);
        $this->assertEquals(5, $result[4]);
    }

    public function test_smart_convert_auto_detects_associative_arrays_as_maps(): void
    {
        $input = ['first' => 'John', 'last' => 'Doe', 'email' => 'john@example.com'];
        $result = ParameterHelper::smartConvert($input);

        $this->assertInstanceOf(CypherMap::class, $result);
        $this->assertCount(3, $result);
        $this->assertEquals('John', $result->get('first'));
        $this->assertEquals('Doe', $result->get('last'));
        $this->assertEquals('john@example.com', $result->get('email'));
    }

    public function test_smart_convert_handles_nested_structures_correctly(): void
    {
        $input = [
            'user' => [
                'name' => 'John',
                'tags' => ['php', 'neo4j', 'laravel'],
            ],
            'scores' => [85, 90, 88],
        ];

        $result = ParameterHelper::smartConvert($input);

        $this->assertInstanceOf(CypherMap::class, $result);

        // Check nested map
        $user = $result->get('user');
        $this->assertInstanceOf(CypherMap::class, $user);
        $this->assertEquals('John', $user->get('name'));

        // Check nested list within map
        $tags = $user->get('tags');
        $this->assertInstanceOf(CypherList::class, $tags);
        $this->assertCount(3, $tags);
        $this->assertEquals('php', $tags[0]);

        // Check list at top level
        $scores = $result->get('scores');
        $this->assertInstanceOf(CypherList::class, $scores);
        $this->assertCount(3, $scores);
        $this->assertEquals(85, $scores[0]);
    }

    public function test_smart_convert_handles_mixed_keys_as_map(): void
    {
        // Mixed numeric and string keys should be treated as a map
        $input = [0 => 'zero', 'one' => 1, 2 => 'two'];
        $result = ParameterHelper::smartConvert($input);

        $this->assertInstanceOf(CypherMap::class, $result);
        $this->assertEquals('zero', $result->get('0'));
        $this->assertEquals(1, $result->get('one'));
        $this->assertEquals('two', $result->get('2'));
    }

    public function test_smart_convert_handles_non_sequential_numeric_keys_as_map(): void
    {
        // Non-sequential numeric keys should be treated as a map
        $input = [0 => 'first', 2 => 'third', 5 => 'sixth'];
        $result = ParameterHelper::smartConvert($input);

        $this->assertInstanceOf(CypherMap::class, $result);
        $this->assertEquals('first', $result->get('0'));
        $this->assertEquals('third', $result->get('2'));
        $this->assertEquals('sixth', $result->get('5'));
    }

    public function test_smart_convert_preserves_non_array_types(): void
    {
        // Test that non-array values pass through unchanged
        $this->assertEquals('string', ParameterHelper::smartConvert('string'));
        $this->assertEquals(42, ParameterHelper::smartConvert(42));
        $this->assertEquals(3.14, ParameterHelper::smartConvert(3.14));
        $this->assertEquals(true, ParameterHelper::smartConvert(true));
        $this->assertNull(ParameterHelper::smartConvert(null));
    }
}
