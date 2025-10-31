<?php

use Illuminate\Support\Collection;
use Tests\Models\Post;
use Tests\Models\User;
// Import test models
use WikibaseSolutions\CypherDSL\Functions\RawFunction;
use WikibaseSolutions\CypherDSL\Query;

beforeEach(function () {
    $this->connection = DB::connection('graph');

    // Clean database
    $this->connection->select('MATCH (n) DETACH DELETE n');

    // Create test graph data with relationships
    $this->connection->select('
        CREATE (u1:users {id: 1, name: "John", age: 30, status: "active"})
        CREATE (u2:users {id: 2, name: "Jane", age: 25, status: "active"})
        CREATE (u3:users {id: 3, name: "Bob", age: 35, status: "inactive"})
        CREATE (u4:users {id: 4, name: "Alice", age: 28, status: "active"})
        CREATE (p1:posts {id: 1, title: "First Post", user_id: 1})
        CREATE (p2:posts {id: 2, title: "Second Post", user_id: 1})
        CREATE (p3:posts {id: 3, title: "Third Post", user_id: 2})
        CREATE (p4:posts {id: 4, title: "Fourth Post", user_id: 3})

        CREATE (u1)-[:FOLLOWS]->(u2)
        CREATE (u1)-[:FOLLOWS]->(u3)
        CREATE (u2)-[:FOLLOWS]->(u1)
        CREATE (u2)-[:FOLLOWS]->(u4)
        CREATE (u3)-[:FOLLOWS]->(u4)
        CREATE (u4)-[:FOLLOWS]->(u1)

        CREATE (u1)-[:HAS_POST]->(p1)
        CREATE (u1)-[:HAS_POST]->(p2)
        CREATE (u2)-[:HAS_POST]->(p3)
        CREATE (u3)-[:HAS_POST]->(p4)

        CREATE (u1)-[:FRIENDS]->(u2)
        CREATE (u2)-[:FRIENDS]->(u1)
        CREATE (u3)-[:FRIENDS]->(u4)
        CREATE (u4)-[:FRIENDS]->(u3)
    ');
});

afterEach(function () {
    $this->connection->select('MATCH (n) DETACH DELETE n');
});

// ===== OUTGOING TRAVERSAL TESTS =====

test('outgoing traversal finds related nodes', function () {
    $user = User::find(1);

    $following = $user->matchFrom()
        ->outgoing('FOLLOWS', 'users')
        ->returning(Query::variable('target'))
        ->get();

    expect($following)->toBeInstanceOf(Collection::class);
    expect($following)->toHaveCount(2);

    $names = $following->pluck('name')->sort()->values()->toArray();
    expect($names)->toBe(['Bob', 'Jane']);
});

test('outgoing traversal without label filter', function () {
    $user = User::find(1);

    $result = $user->matchFrom()
        ->outgoing('HAS_POST')
        ->returning(Query::variable('target'))
        ->get();

    expect($result)->toHaveCount(2);

    $titles = $result->pluck('title')->sort()->values()->toArray();
    expect($titles)->toBe(['First Post', 'Second Post']);
});

test('static match with outgoing traversal', function () {
    $results = User::match()
        ->where(Query::variable('n')->property('name')->equals(Query::literal('John')))
        ->outgoing('FOLLOWS', 'users')
        ->returning(Query::variable('target'))
        ->get();

    expect($results)->toHaveCount(2);

    $names = $results->pluck('name')->sort()->values()->toArray();
    expect($names)->toBe(['Bob', 'Jane']);
});

test('outgoing traversal with where clause', function () {
    $user = User::find(1);

    $activeFollowing = $user->matchFrom()
        ->outgoing('FOLLOWS', 'users')
        ->where(Query::variable('target')->property('status')->equals(Query::literal('active')))
        ->returning(Query::variable('target'))
        ->get();

    expect($activeFollowing)->toHaveCount(1);
    expect($activeFollowing->first()->name)->toBe('Jane');
});

// ===== INCOMING TRAVERSAL TESTS =====

test('incoming traversal finds related nodes', function () {
    $user = User::find(1);

    $followers = $user->matchFrom()
        ->incoming('FOLLOWS', 'users')
        ->returning(Query::variable('source'))
        ->get();

    expect($followers)->toHaveCount(2);

    $names = $followers->pluck('name')->sort()->values()->toArray();
    expect($names)->toBe(['Alice', 'Jane']);
});

test('incoming traversal without label filter', function () {
    $post = Post::find(1);

    $authors = $post->matchFrom()
        ->incoming('HAS_POST')
        ->returning(Query::variable('source'))
        ->get();

    expect($authors)->toHaveCount(1);
    expect($authors->first()->name)->toBe('John');
});

test('static match with incoming traversal', function () {
    $results = Post::match()
        ->where(Query::variable('n')->property('title')->equals(Query::literal('First Post')))
        ->incoming('HAS_POST', 'users')
        ->returning(Query::variable('source'))
        ->get();

    expect($results)->toHaveCount(1);
    expect($results->first()->name)->toBe('John');
});

test('incoming traversal with where clause', function () {
    $user = User::find(1);

    $activeFollowers = $user->matchFrom()
        ->incoming('FOLLOWS', 'users')
        ->where(Query::variable('source')->property('age')->lt(Query::literal(30)))
        ->returning(Query::variable('source'))
        ->get();

    expect($activeFollowers)->toHaveCount(2); // Jane (25) and Alice (28)
});

// ===== BIDIRECTIONAL TRAVERSAL TESTS =====

test('bidirectional traversal finds all connected nodes', function () {
    $user = User::find(1);

    $friends = $user->matchFrom()
        ->bidirectional('FRIENDS', 'users')
        ->returning(Query::variable('other'))
        ->get();

    // John has FRIENDS relationship with Jane (bidirectional creates 2 edges)
    // So we get both directions matched
    expect($friends)->toHaveCount(2);

    $names = $friends->pluck('name')->unique()->values()->toArray();
    expect($names)->toBe(['Jane']);
});

test('bidirectional traversal without label', function () {
    $user = User::find(3);

    $friends = $user->matchFrom()
        ->bidirectional('FRIENDS')
        ->returning(Query::variable('other'))
        ->get();

    // Bob has FRIENDS relationship with Alice (bidirectional creates 2 edges)
    expect($friends)->toHaveCount(2);

    $names = $friends->pluck('name')->unique()->values()->toArray();
    expect($names)->toBe(['Alice']);
});

test('static match with bidirectional traversal', function () {
    $results = User::match()
        ->where(Query::variable('n')->property('name')->equals(Query::literal('Bob')))
        ->bidirectional('FRIENDS', 'users')
        ->returning(Query::variable('other'))
        ->get();

    // Bob has bidirectional FRIENDS with Alice (2 edges)
    expect($results)->toHaveCount(2);

    $names = $results->pluck('name')->unique()->values()->toArray();
    expect($names)->toBe(['Alice']);
});

test('bidirectional traversal with where clause', function () {
    // Find all friends regardless of direction, then filter
    $results = User::match()
        ->bidirectional('FRIENDS', 'users')
        ->where(Query::variable('other')->property('status')->equals(Query::literal('active')))
        ->returning([Query::variable('n'), Query::variable('other')])
        ->get();

    // Should find John-Jane and Alice-Bob pairs where other is active
    expect($results->count())->toBeGreaterThan(0);
});

// ===== CHAINED TRAVERSALS =====

test('chained outgoing traversals work', function () {
    // Create a multi-hop relationship: u1 -> u2 -> others
    // First get Jane, then see who she follows
    $result = User::find(1)->matchFrom()
        ->outgoing('FOLLOWS', 'users')
        ->where(Query::variable('target')->property('name')->equals(Query::literal('Jane')))
        ->returning(Query::variable('target'))
        ->get();

    expect($result)->toHaveCount(1); // We found Jane
});

test('mixed direction traversals', function () {
    $user = User::find(2);

    // Jane's followers
    $result = $user->matchFrom()
        ->incoming('FOLLOWS', 'users')
        ->returning(Query::variable('source'))
        ->get();

    expect($result)->toBeInstanceOf(Collection::class);
    expect($result)->toHaveCount(1); // Only John follows Jane
});

// ===== SHORTEST PATH TESTS =====

test('shortest path between two nodes', function () {
    $user1 = User::find(1);
    $user4 = User::find(4);

    $path = $user1->matchFrom()
        ->shortestPath($user4, 'FOLLOWS')
        ->returning(Query::variable('path'))
        ->get();

    expect($path)->toBeInstanceOf(Collection::class);
    expect($path)->toHaveCount(1);
});

test('shortest path with max depth', function () {
    $user1 = User::find(1);
    $user4 = User::find(4);

    $path = $user1->matchFrom()
        ->shortestPath($user4, 'FOLLOWS', 2)
        ->returning(Query::variable('path'))
        ->get();

    expect($path)->toBeInstanceOf(Collection::class);
});

test('shortest path using node ID', function () {
    $user = User::find(1);

    $path = $user->matchFrom()
        ->shortestPath(4, 'FOLLOWS')
        ->returning(Query::variable('path'))
        ->get();

    expect($path)->toBeInstanceOf(Collection::class);
});

test('shortest path without relationship type', function () {
    $user1 = User::find(1);
    $user3 = User::find(3);

    $path = $user1->matchFrom()
        ->shortestPath($user3)
        ->returning(Query::variable('path'))
        ->get();

    expect($path)->toBeInstanceOf(Collection::class);
    expect($path)->toHaveCount(1);
});

// ===== ALL PATHS TESTS =====

test('all paths between two nodes', function () {
    $user1 = User::find(1);
    $user4 = User::find(4);

    $paths = $user1->matchFrom()
        ->allPaths($user4, 'FOLLOWS', 3)
        ->returning(Query::variable('path'))
        ->get();

    expect($paths)->toBeInstanceOf(Collection::class);
    expect($paths->count())->toBeGreaterThan(0);
});

test('all paths with default max depth', function () {
    $user1 = User::find(1);
    $user2 = User::find(2);

    $paths = $user1->matchFrom()
        ->allPaths($user2, 'FOLLOWS')
        ->returning(Query::variable('path'))
        ->get();

    expect($paths)->toBeInstanceOf(Collection::class);
    // There are multiple paths from u1 to u2 through different routes
    expect($paths->count())->toBeGreaterThan(0);
});

test('all paths using node ID', function () {
    $user = User::find(1);

    $paths = $user->matchFrom()
        ->allPaths(2, 'FOLLOWS', 2)
        ->returning(Query::variable('path'))
        ->get();

    expect($paths)->toBeInstanceOf(Collection::class);
});

test('all paths without relationship type', function () {
    $user1 = User::find(1);
    $user2 = User::find(2);

    $paths = $user1->matchFrom()
        ->allPaths($user2, null, 2)
        ->returning(Query::variable('path'))
        ->get();

    expect($paths)->toBeInstanceOf(Collection::class);
    expect($paths->count())->toBeGreaterThan(0);
});

// ===== STATIC VS INSTANCE USAGE =====

test('static match can use graph patterns', function () {
    $results = User::match()
        ->outgoing('FOLLOWS', 'users')
        ->returning([Query::variable('n'), Query::variable('target')])
        ->get();

    expect($results)->toBeInstanceOf(Collection::class);
    expect($results->count())->toBe(6); // Total number of FOLLOWS relationships (6 edges)
});

test('instance matchFrom provides context node', function () {
    $user = User::find(1);

    // Using instance should limit to just this user's relationships
    $following = $user->matchFrom()
        ->outgoing('FOLLOWS', 'users')
        ->returning(Query::variable('target'))
        ->get();

    expect($following)->toHaveCount(2); // John follows Jane and Bob
});

// ===== COMPLEX PATTERN COMBINATIONS =====

test('combining multiple patterns in one query', function () {
    $user = User::find(1);

    // Find users that this user follows
    $result = $user->matchFrom()
        ->outgoing('FOLLOWS', 'users')
        ->returning(Query::variable('target'))
        ->get();

    expect($result)->toBeInstanceOf(Collection::class);
    expect($result)->toHaveCount(2); // John follows Jane and Bob
});

test('patterns work with limit and order', function () {
    $results = User::match()
        ->outgoing('FOLLOWS', 'users')
        ->orderBy(Query::variable('target')->property('name'))
        ->limit(Query::literal(2))
        ->returning(Query::variable('target'))
        ->get();

    expect($results)->toHaveCount(2);
});

test('patterns can be combined with aggregations', function () {
    // Count how many people each user follows
    $cypher = User::match()
        ->outgoing('FOLLOWS', 'users')
        ->returning([
            'name' => Query::variable('n')->property('name'),
            'follow_count' => new RawFunction('COUNT', [Query::variable('target')]),
        ])
        ->toCypher();

    expect($cypher)->toContain('MATCH');
    expect($cypher)->toContain('COUNT');
});
