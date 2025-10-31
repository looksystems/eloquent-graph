<?php

use Illuminate\Support\Collection;
use WikibaseSolutions\CypherDSL\Query;

beforeEach(function () {
    $this->connection = DB::connection('graph');

    // Clean database
    $this->connection->select('MATCH (n) DETACH DELETE n');

    // Create test data
    $this->connection->select('
        CREATE (u1:User {id: 1, name: "John", age: 30, status: "active"})
        CREATE (u2:User {id: 2, name: "Jane", age: 25, status: "active"})
        CREATE (u3:User {id: 3, name: "Bob", age: 35, status: "inactive"})
        CREATE (p1:Post {id: 1, title: "First Post", user_id: 1})
        CREATE (p2:Post {id: 2, title: "Second Post", user_id: 1})
        CREATE (p3:Post {id: 3, title: "Third Post", user_id: 2})
    ');
});

afterEach(function () {
    $this->connection->select('MATCH (n) DETACH DELETE n');
});

test('connection cypher with no args returns dsl builder', function () {
    $builder = $this->connection->cypher();

    expect($builder)->toBeInstanceOf(\Look\EloquentCypher\Builders\GraphCypherDslBuilder::class);
});

test('connection cypher with string query still works', function () {
    $result = $this->connection->cypher('MATCH (n:User) RETURN n.name as name ORDER BY n.name');

    expect($result)->toBeArray();
    expect($result)->toHaveCount(3);
    expect($result[0]['name'] ?? $result[0]->name ?? null)->toBe('Bob');
});

test('dsl builder proxies methods via call', function () {
    $builder = $this->connection->cypher();

    // Test that we can chain DSL methods
    $result = $builder->match(Query::node('User'));

    expect($result)->toBeInstanceOf(\Look\EloquentCypher\Builders\GraphCypherDslBuilder::class);
    expect($result)->toBe($builder); // Should return same instance for chaining
});

test('dsl builder toCypher returns valid cypher string', function () {
    $builder = $this->connection->cypher();

    $cypher = $builder
        ->match(Query::node('User'))
        ->returning(Query::node('User'))
        ->toCypher();

    expect($cypher)->toBeString();
    expect($cypher)->toContain('MATCH');
    expect($cypher)->toContain('User');
    expect($cypher)->toContain('RETURN');
});

test('dsl builder toSql is alias for toCypher', function () {
    $builder = $this->connection->cypher();

    $builder
        ->match(Query::node('User'))
        ->returning(Query::node('User'));

    $cypher = $builder->toCypher();
    $sql = $builder->toSql();

    expect($sql)->toBe($cypher);
});

test('dsl builder get executes query and returns collection', function () {
    $builder = $this->connection->cypher();

    $result = $builder
        ->match(Query::node('User')->named('u'))
        ->returning(Query::variable('u'))
        ->get();

    expect($result)->toBeInstanceOf(Collection::class);
    expect($result)->toHaveCount(3);

    // Check we have user data
    $firstUser = $result->first();
    expect($firstUser)->not()->toBeNull();
    expect($firstUser)->toHaveProperty('id');
    expect($firstUser)->toHaveProperty('name');
});

test('dsl builder first returns single result', function () {
    $builder = $this->connection->cypher();

    $result = $builder
        ->match(Query::node('User')->named('u'))
        ->returning(Query::variable('u'))
        ->orderBy(Query::variable('u')->property('name'))
        ->first();

    expect($result)->toBeObject();
    expect($result->name ?? $result['name'] ?? null)->toBe('Bob');
});

test('dsl builder first returns null for empty results', function () {
    $builder = $this->connection->cypher();

    $result = $builder
        ->match(Query::node('NonExistent')->named('n'))
        ->returning(Query::variable('n'))
        ->first();

    expect($result)->toBeNull();
});

test('dsl builder count returns integer', function () {
    $builder = $this->connection->cypher();

    $count = $builder
        ->match(Query::node('User'))
        ->count();

    expect($count)->toBeInt();
    expect($count)->toBe(3);
});

test('dsl builder with where conditions', function () {
    $builder = $this->connection->cypher();

    $result = $builder
        ->match(Query::node('User')->named('u'))
        ->where(Query::variable('u')->property('age')->gt(Query::literal(25)))
        ->returning(Query::variable('u'))
        ->get();

    expect($result)->toHaveCount(2); // John (30) and Bob (35)

    $names = $result->pluck('name')->sort()->values();
    expect($names->toArray())->toBe(['Bob', 'John']);
});

test('dsl builder with parameters', function () {
    $builder = $this->connection->cypher();

    $result = $builder
        ->match(Query::node('User')->named('u'))
        ->where(
            Query::variable('u')->property('status')
                ->equals(Query::parameter('status'))
        )
        ->withParameter('status', 'active')
        ->returning(Query::variable('u'))
        ->get();

    expect($result)->toHaveCount(2); // John and Jane
});

test('dsl builder dd method exists', function () {
    $builder = $this->connection->cypher();

    $builder
        ->match(Query::node('User'))
        ->returning(Query::node('User'));

    // We can't actually test dd() as it exits, but we can verify it exists
    expect(method_exists($builder, 'dd'))->toBeTrue();
});

test('dsl builder dump outputs and returns self', function () {
    $builder = $this->connection->cypher();

    $result = $builder
        ->match(Query::node('User'))
        ->returning(Query::node('User'))
        ->dump();

    // dump() should return the builder for chaining
    expect($result)->toBeInstanceOf(\Look\EloquentCypher\Builders\GraphCypherDslBuilder::class);
    expect($result)->toBe($builder);
});

test('dsl builder complex query with multiple nodes', function () {
    $builder = $this->connection->cypher();

    $result = $builder
        ->match([
            Query::node('User')->named('u'),
            Query::node('Post')->named('p'),
        ])
        ->where(Query::variable('p')->property('user_id')
            ->equals(Query::variable('u')->property('id')))
        ->returning([
            'user_name' => Query::variable('u')->property('name'),
            'post_title' => Query::variable('p')->property('title'),
        ])
        ->orderBy(Query::variable('u')->property('name'))
        ->get();

    expect($result)->toHaveCount(3); // 3 posts total (2 for John, 1 for Jane)

    $firstResult = $result->first();
    expect($firstResult)->toHaveProperty('user_name');
    expect($firstResult)->toHaveProperty('post_title');
});

test('dsl builder with limit and skip', function () {
    $builder = $this->connection->cypher();

    $result = $builder
        ->match(Query::node('User')->named('u'))
        ->returning(Query::variable('u'))
        ->orderBy(Query::variable('u')->property('name'))
        ->skip(Query::literal(1))
        ->limit(Query::literal(1))
        ->get();

    expect($result)->toHaveCount(1);
    expect($result->first()->name ?? $result->first()['name'] ?? null)->toBe('Jane');
});

test('dsl builder handles empty results gracefully', function () {
    $builder = $this->connection->cypher();

    $result = $builder
        ->match(Query::node('Product')->named('p'))
        ->returning(Query::variable('p'))
        ->get();

    expect($result)->toBeInstanceOf(Collection::class);
    expect($result->isEmpty())->toBeTrue();
});

test('dsl builder with order by desc', function () {
    $builder = $this->connection->cypher();

    $result = $builder
        ->match(Query::node('User')->named('u'))
        ->returning(Query::variable('u'))
        ->orderBy(Query::variable('u')->property('age'), 'DESC')
        ->get();

    $ages = $result->pluck('age');
    expect($ages->toArray())->toBe([35, 30, 25]);
});

test('dsl builder returning specific properties', function () {
    $builder = $this->connection->cypher();

    $result = $builder
        ->match(Query::node('User')->named('u'))
        ->returning([
            'name' => Query::variable('u')->property('name'),
            'age' => Query::variable('u')->property('age'),
        ])
        ->first();

    expect($result)->toHaveProperty('name');
    expect($result)->toHaveProperty('age');
    expect($result)->not()->toHaveProperty('status');
});

test('dsl builder with aggregation', function () {
    $builder = $this->connection->cypher();

    $result = $builder
        ->match(Query::node('User')->named('u'))
        ->returning(Query::rawExpression('COUNT(u) as total'))
        ->first();

    expect($result->total ?? $result['total'] ?? null)->toBe(3);
});

test('dsl builder with distinct', function () {
    $builder = $this->connection->cypher();

    // Create duplicate status
    $this->connection->select('CREATE (:User {id: 4, name: "Alice", age: 28, status: "active"})');

    $result = $builder
        ->match(Query::node('User')->named('u'))
        ->returning(Query::rawExpression('DISTINCT u.status as status'))
        ->get();

    expect($result)->toHaveCount(2); // active and inactive
    $statuses = $result->pluck('status')->sort()->values();
    expect($statuses->toArray())->toBe(['active', 'inactive']);
});

test('dsl builder chaining multiple where conditions', function () {
    $builder = $this->connection->cypher();

    $result = $builder
        ->match(Query::node('User')->named('u'))
        ->where(
            Query::variable('u')->property('age')->gte(Query::literal(25))
                ->and(Query::variable('u')->property('status')->equals(Query::literal('active')))
        )
        ->returning(Query::variable('u'))
        ->get();

    expect($result)->toHaveCount(2); // John and Jane
});

test('dsl builder with or conditions', function () {
    $builder = $this->connection->cypher();

    $result = $builder
        ->match(Query::node('User')->named('u'))
        ->where(
            Query::variable('u')->property('age')->lt(Query::literal(26))
                ->or(Query::variable('u')->property('age')->gt(Query::literal(34)))
        )
        ->returning(Query::variable('u'))
        ->get();

    expect($result)->toHaveCount(2); // Jane (25) and Bob (35)
});

test('dsl builder parameter extraction', function () {
    $builder = $this->connection->cypher();

    $builder
        ->match(Query::node('User')->named('u'))
        ->where(
            Query::variable('u')->property('age')
                ->gt(Query::parameter('minAge'))
        )
        ->withParameter('minAge', 25)
        ->returning(Query::variable('u'));

    // This will be used internally - we just verify the method exists
    expect(method_exists($builder, 'extractBindings'))->toBeTrue();
});

test('dsl builder raw cypher fallback for unsupported features', function () {
    $builder = $this->connection->cypher();

    // For features not yet in DSL, we can fall back to raw
    $result = $this->connection->cypher('MATCH (u:User) WHERE u.age > 25 RETURN u');

    expect($result)->toBeArray();
    expect($result)->toHaveCount(2);
});
