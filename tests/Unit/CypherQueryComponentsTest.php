<?php

namespace Tests\Unit;

use Look\EloquentCypher\Query\CypherQueryComponents;
use PHPUnit\Framework\TestCase;

class CypherQueryComponentsTest extends TestCase
{
    private CypherQueryComponents $components;

    protected function setUp(): void
    {
        parent::setUp();
        $this->components = new CypherQueryComponents;
    }

    /**
     * Test basic MATCH clause building
     */
    public function test_build_match_without_conditions(): void
    {
        $result = $this->components->buildMatch('User');
        $this->assertEquals('MATCH (n:User)', $result);

        $result = $this->components->buildMatch('Post', 'p');
        $this->assertEquals('MATCH (p:Post)', $result);
    }

    public function test_build_match_with_conditions(): void
    {
        $conditions = ['name' => 'John', 'age' => 30];
        $result = $this->components->buildMatch('User', 'u', $conditions);
        $this->assertEquals('MATCH (u:User {name: $name, age: $age})', $result);
    }

    public function test_build_match_with_null_conditions(): void
    {
        $conditions = ['name' => 'John', 'age' => null, 'city' => 'NYC'];
        $result = $this->components->buildMatch('User', 'u', $conditions);
        $this->assertEquals('MATCH (u:User {name: $name, city: $city})', $result);
    }

    /**
     * Test WHERE clause building
     */
    public function test_build_where_empty(): void
    {
        $result = $this->components->buildWhere([]);
        $this->assertEquals('', $result);
    }

    public function test_build_where_basic_condition(): void
    {
        $conditions = [
            ['type' => 'Basic', 'column' => 'name', 'operator' => '=', 'value' => 'John'],
        ];
        $result = $this->components->buildWhere($conditions);
        $this->assertEquals('WHERE n.name = $name', $result);
    }

    public function test_build_where_multiple_conditions(): void
    {
        $conditions = [
            ['type' => 'Basic', 'column' => 'name', 'operator' => '=', 'value' => 'John'],
            ['type' => 'Basic', 'column' => 'age', 'operator' => '>', 'value' => 25],
        ];
        $result = $this->components->buildWhere($conditions, 'u');
        $this->assertEquals('WHERE u.name = $name AND u.age > $age', $result);
    }

    public function test_build_where_in_condition(): void
    {
        $conditions = [
            ['type' => 'In', 'column' => 'status', 'values' => ['active', 'pending']],
        ];
        $result = $this->components->buildWhere($conditions);
        $this->assertEquals('WHERE n.status IN $status', $result);
    }

    public function test_build_where_not_in_condition(): void
    {
        $conditions = [
            ['type' => 'NotIn', 'column' => 'status', 'values' => ['deleted', 'archived']],
        ];
        $result = $this->components->buildWhere($conditions);
        $this->assertEquals('WHERE NOT n.status IN $status', $result);
    }

    public function test_build_where_null_condition(): void
    {
        $conditions = [
            ['type' => 'Null', 'column' => 'deleted_at'],
        ];
        $result = $this->components->buildWhere($conditions);
        $this->assertEquals('WHERE n.deleted_at IS NULL', $result);
    }

    public function test_build_where_not_null_condition(): void
    {
        $conditions = [
            ['type' => 'NotNull', 'column' => 'email'],
        ];
        $result = $this->components->buildWhere($conditions);
        $this->assertEquals('WHERE n.email IS NOT NULL', $result);
    }

    public function test_build_where_between_condition(): void
    {
        $conditions = [
            ['type' => 'between', 'column' => 'age', 'values' => [18, 65]],
        ];
        $result = $this->components->buildWhere($conditions);
        $this->assertEquals('WHERE n.age >= $age_min AND n.age <= $age_max', $result);
    }

    public function test_build_where_raw_condition(): void
    {
        $conditions = [
            ['type' => 'Raw', 'sql' => 'n.views > 1000'],
        ];
        $result = $this->components->buildWhere($conditions);
        $this->assertEquals('WHERE n.views > 1000', $result);
    }

    public function test_build_where_with_id_column(): void
    {
        $conditions = [
            ['type' => 'Basic', 'column' => 'id', 'operator' => '=', 'value' => 123],
        ];
        $result = $this->components->buildWhere($conditions);
        $this->assertEquals('WHERE id(n) = $id', $result);
    }

    /**
     * Test RETURN clause building
     */
    public function test_build_return_all(): void
    {
        $result = $this->components->buildReturn();
        $this->assertEquals('RETURN n', $result);

        $result = $this->components->buildReturn(['*']);
        $this->assertEquals('RETURN n', $result);
    }

    public function test_build_return_specific_columns(): void
    {
        $result = $this->components->buildReturn(['name', 'email'], 'u');
        $this->assertEquals('RETURN u, u.name, u.email', $result);
    }

    public function test_build_return_with_raw_expression(): void
    {
        $columns = [
            ['type' => 'raw', 'expression' => 'count(*) as total'],
        ];
        $result = $this->components->buildReturn($columns);
        $this->assertEquals('RETURN n, count(*) as total', $result);
    }

    public function test_build_return_with_complex_raw_expression(): void
    {
        $columns = [
            ['type' => 'raw', 'expression' => '*, (views + likes) as engagement'],
        ];
        $result = $this->components->buildReturn($columns, 'p');
        $this->assertEquals('RETURN p, (p.views + p.likes) as engagement', $result);
    }

    public function test_build_return_with_array_of_raw_expressions(): void
    {
        $columns = [
            ['type' => 'raw', 'expression' => ['n.name', 'count(*) as total']],
        ];
        $result = $this->components->buildReturn($columns);
        $this->assertEquals('RETURN n, n.name, count(*) as total', $result);
    }

    /**
     * Test ORDER BY clause building
     */
    public function test_build_order_by_empty(): void
    {
        $result = $this->components->buildOrderBy([]);
        $this->assertEquals('', $result);
    }

    public function test_build_order_by_single(): void
    {
        $orders = [
            ['column' => 'name', 'direction' => 'asc'],
        ];
        $result = $this->components->buildOrderBy($orders);
        $this->assertEquals('ORDER BY n.name ASC', $result);
    }

    public function test_build_order_by_multiple(): void
    {
        $orders = [
            ['column' => 'created_at', 'direction' => 'desc'],
            ['column' => 'name', 'direction' => 'asc'],
        ];
        $result = $this->components->buildOrderBy($orders, 'u');
        $this->assertEquals('ORDER BY u.created_at DESC, u.name ASC', $result);
    }

    public function test_build_order_by_with_raw_expression(): void
    {
        $orders = [
            ['type' => 'raw', 'expression' => 'views + likes DESC'],
        ];
        $result = $this->components->buildOrderBy($orders);
        $this->assertEquals('ORDER BY n.views + n.likes DESC', $result);
    }

    /**
     * Test LIMIT and SKIP clause building
     */
    public function test_build_limit_only(): void
    {
        $result = $this->components->buildLimit(10);
        $this->assertEquals('LIMIT 10', $result);
    }

    public function test_build_limit_with_offset(): void
    {
        $result = $this->components->buildLimit(10, 20);
        $this->assertEquals('SKIP 20 LIMIT 10', $result);
    }

    public function test_build_limit_offset_only(): void
    {
        $result = $this->components->buildLimit(null, 5);
        $this->assertEquals('SKIP 5', $result);
    }

    public function test_build_limit_none(): void
    {
        $result = $this->components->buildLimit(null, null);
        $this->assertEquals('', $result);
    }

    public function test_build_limit_zero(): void
    {
        $result = $this->components->buildLimit(0);
        $this->assertEquals('LIMIT 0', $result);
    }

    /**
     * Test SET clause building
     */
    public function test_build_set_single(): void
    {
        $bindings = [];
        $result = $this->components->buildSet(['name' => 'John'], 'n', $bindings);
        $this->assertEquals('SET n.name = $name', $result);
        $this->assertEquals(['name' => 'John'], $bindings);
    }

    public function test_build_set_multiple(): void
    {
        $bindings = [];
        $values = ['name' => 'John', 'email' => 'john@example.com', 'age' => 30];
        $result = $this->components->buildSet($values, 'u', $bindings);
        $this->assertEquals('SET u.name = $name, u.email = $email, u.age = $age', $result);
        $this->assertEquals($values, $bindings);
    }

    public function test_build_set_with_table_prefix(): void
    {
        $bindings = [];
        $result = $this->components->buildSet(['users.name' => 'John'], 'n', $bindings);
        $this->assertEquals('SET n.name = $name', $result);
        $this->assertEquals(['name' => 'John'], $bindings);
    }

    /**
     * Test CREATE clause building
     */
    public function test_build_create_empty(): void
    {
        $result = $this->components->buildCreate('User', []);
        $this->assertEquals('CREATE (n:User)', $result);
    }

    public function test_build_create_with_attributes(): void
    {
        $attributes = ['name' => 'John', 'email' => 'john@example.com'];
        $result = $this->components->buildCreate('User', $attributes, 'u');
        $this->assertEquals('CREATE (u:User {name: $name, email: $email})', $result);
    }

    /**
     * Test DELETE clause building
     */
    public function test_build_delete_detach(): void
    {
        $result = $this->components->buildDelete();
        $this->assertEquals('DETACH DELETE n', $result);

        $result = $this->components->buildDelete('u', true);
        $this->assertEquals('DETACH DELETE u', $result);
    }

    public function test_build_delete_no_detach(): void
    {
        $result = $this->components->buildDelete('n', false);
        $this->assertEquals('DELETE n', $result);
    }

    /**
     * Test operator translation
     */
    public function test_operator_translation(): void
    {
        // Test != becomes <>
        $conditions = [
            ['type' => 'Basic', 'column' => 'status', 'operator' => '!=', 'value' => 'deleted'],
        ];
        $result = $this->components->buildWhere($conditions);
        $this->assertEquals('WHERE n.status <> $status', $result);

        // Test LIKE becomes =~
        $conditions = [
            ['type' => 'Basic', 'column' => 'name', 'operator' => 'like', 'value' => 'John%'],
        ];
        $result = $this->components->buildWhere($conditions);
        $this->assertEquals('WHERE n.name =~ $name', $result);

        // Test NOT LIKE becomes NOT =~
        $conditions = [
            ['type' => 'Basic', 'column' => 'name', 'operator' => 'not like', 'value' => 'John%'],
        ];
        $result = $this->components->buildWhere($conditions);
        $this->assertEquals('WHERE n.name NOT =~ $name', $result);
    }

    /**
     * Test complex expression prefixing
     */
    public function test_complex_expression_prefixing(): void
    {
        $columns = [
            ['type' => 'raw', 'expression' => '(views + likes) / followers as engagement_rate'],
        ];
        $result = $this->components->buildReturn($columns, 'p');
        $this->assertEquals('RETURN p, (p.views + p.likes) / p.followers as engagement_rate', $result);
    }

    public function test_expression_with_existing_prefix(): void
    {
        $columns = [
            ['type' => 'raw', 'expression' => 'n.views + n.likes as total'],
        ];
        $result = $this->components->buildReturn($columns);
        $this->assertEquals('RETURN n, n.views + n.likes as total', $result);
    }

    /**
     * Test edge cases and special scenarios
     */
    public function test_empty_array_handling(): void
    {
        $result = $this->components->buildMatch('User', 'n', []);
        $this->assertEquals('MATCH (n:User)', $result);

        $result = $this->components->buildWhere([]);
        $this->assertEquals('', $result);

        $result = $this->components->buildOrderBy([]);
        $this->assertEquals('', $result);
    }

    public function test_mixed_condition_types(): void
    {
        $conditions = [
            ['type' => 'Basic', 'column' => 'name', 'operator' => '=', 'value' => 'John'],
            ['type' => 'NotNull', 'column' => 'email'],
            ['type' => 'In', 'column' => 'status', 'values' => ['active', 'pending']],
            ['type' => 'between', 'column' => 'age', 'values' => [18, 65]],
        ];
        $result = $this->components->buildWhere($conditions);
        $expected = 'WHERE n.name = $name AND n.email IS NOT NULL AND n.status IN $status AND n.age >= $age_min AND n.age <= $age_max';
        $this->assertEquals($expected, $result);
    }

    public function test_column_prefixing_with_different_aliases(): void
    {
        $result = $this->components->buildReturn(['name', 'email'], 'user');
        $this->assertEquals('RETURN user, user.name, user.email', $result);

        $orders = [['column' => 'created_at', 'direction' => 'desc']];
        $result = $this->components->buildOrderBy($orders, 'post');
        $this->assertEquals('ORDER BY post.created_at DESC', $result);
    }

    public function test_parameter_name_cleaning(): void
    {
        $bindings = [];
        $values = ['users.profile.name' => 'John'];
        $result = $this->components->buildSet($values, 'n', $bindings);
        $this->assertEquals('SET n.name = $name', $result);
        $this->assertEquals(['name' => 'John'], $bindings);
    }
}
