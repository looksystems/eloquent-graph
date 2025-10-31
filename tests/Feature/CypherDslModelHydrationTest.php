<?php

use Illuminate\Support\Collection;
use Tests\Models\Post;
use Tests\Models\User;
use WikibaseSolutions\CypherDSL\Query;

beforeEach(function () {
    $this->connection = DB::connection('graph');

    // Clean database
    $this->connection->select('MATCH (n) DETACH DELETE n');

    // Create test data with various attributes to test casting
    $this->connection->select('
        CREATE (u1:users {
            id: 1,
            name: "John Doe",
            email: "john@example.com",
            age: 30,
            is_active: true,
            metadata: \'{"theme": "dark", "notifications": true}\',
            created_at: datetime(),
            updated_at: datetime()
        })
        CREATE (u2:users {
            id: 2,
            name: "Jane Smith",
            email: "jane@example.com",
            age: 25,
            is_active: false,
            metadata: \'{"theme": "light", "notifications": false}\',
            created_at: datetime(),
            updated_at: datetime()
        })
        CREATE (u3:users {
            id: 3,
            name: "Bob Wilson",
            email: "bob@example.com",
            age: 35,
            is_active: true,
            metadata: null,
            created_at: datetime(),
            updated_at: datetime()
        })
        CREATE (p1:posts {
            id: 1,
            user_id: 1,
            title: "First Post",
            content: "This is the first post",
            published: true,
            views: 100,
            tags: \'["php", "laravel"]\',
            created_at: datetime(),
            updated_at: datetime()
        })
        CREATE (p2:posts {
            id: 2,
            user_id: 1,
            title: "Second Post",
            content: "This is the second post",
            published: false,
            views: 50,
            tags: \'["graph", "database"]\',
            created_at: datetime(),
            updated_at: datetime()
        })
        CREATE (p3:posts {
            id: 3,
            user_id: 2,
            title: "Jane\'s Post",
            content: "Post by Jane",
            published: true,
            views: 200,
            tags: null,
            created_at: datetime(),
            updated_at: datetime()
        })
    ');
});

afterEach(function () {
    $this->connection->select('MATCH (n) DETACH DELETE n');
});

test('Model match returns DSL builder', function () {
    $builder = User::match();

    expect($builder)->toBeInstanceOf(\Look\EloquentCypher\Builders\GraphCypherDslBuilder::class);
});

test('Model static match returns Collection of hydrated models', function () {
    $users = User::match()
        ->returning(Query::variable('n'))
        ->get();

    expect($users)->toBeInstanceOf(Collection::class)
        ->and($users)->toHaveCount(3)
        ->and($users->first())->toBeInstanceOf(User::class)
        ->and($users->first()->name)->toBe('John Doe');
});

test('Model match with where conditions filters correctly', function () {
    $activeUsers = User::match()
        ->where(Query::variable('n')->property('is_active')->equals(Query::literal(true)))
        ->returning(Query::variable('n'))
        ->get();

    expect($activeUsers)->toHaveCount(2)
        ->and($activeUsers->pluck('name')->sort()->values()->toArray())
        ->toBe(['Bob Wilson', 'John Doe']);
});

test('Model match hydrates with proper casts', function () {
    $user = User::match()
        ->where(Query::variable('n')->property('id')->equals(Query::literal(1)))
        ->returning(Query::variable('n'))
        ->first();

    expect($user)->toBeInstanceOf(User::class)
        ->and($user->id)->toBeInt()
        ->and($user->name)->toBeString()
        ->and($user->is_active)->toBeBool()
        ->and($user->is_active)->toBeTrue()
        ->and($user->metadata)->toBeArray()
        ->and($user->metadata['theme'] ?? null)->toBe('dark')
        ->and($user->created_at)->toBeInstanceOf(\Carbon\Carbon::class)
        ->and($user->updated_at)->toBeInstanceOf(\Carbon\Carbon::class);
});

test('Model instance matchFrom returns DSL builder with source node context', function () {
    $user = User::find(1);
    $builder = $user->matchFrom();

    expect($builder)->toBeInstanceOf(\Look\EloquentCypher\Builders\GraphCypherDslBuilder::class);
});

test('Model instance matchFrom can traverse relationships', function () {
    // First create a relationship
    $this->connection->select('
        MATCH (u:users {id: 1}), (p:posts {user_id: 1})
        CREATE (u)-[:HAS_POST]->(p)
    ');

    $user = User::find(1);

    // Use matchFrom to find posts via relationship
    $posts = $user->matchFrom()
        ->match(Query::node('posts')->named('p'))
        ->where(Query::variable('p')->property('user_id')->equals(Query::literal(1)))
        ->returning(Query::variable('p'))
        ->get();

    expect($posts)->toHaveCount(2)
        ->and($posts->pluck('title')->sort()->values()->toArray())
        ->toBe(['First Post', 'Second Post']);
});

test('Model match returns empty collection for no results', function () {
    $users = User::match()
        ->where(Query::variable('n')->property('age')->gt(Query::literal(100)))
        ->returning(Query::variable('n'))
        ->get();

    expect($users)->toBeInstanceOf(Collection::class)
        ->and($users->isEmpty())->toBeTrue();
});

test('Model match with first returns single model or null', function () {
    $user = User::match()
        ->where(Query::variable('n')->property('email')->equals(Query::literal('jane@example.com')))
        ->returning(Query::variable('n'))
        ->first();

    expect($user)->toBeInstanceOf(User::class)
        ->and($user->name)->toBe('Jane Smith');

    $noUser = User::match()
        ->where(Query::variable('n')->property('id')->equals(Query::literal(999)))
        ->returning(Query::variable('n'))
        ->first();

    expect($noUser)->toBeNull();
});

test('Model match with count returns integer', function () {
    $count = User::match()->count();

    expect($count)->toBeInt()
        ->and($count)->toBe(3);

    $activeCount = User::match()
        ->where(Query::variable('n')->property('is_active')->equals(Query::literal(true)))
        ->count();

    expect($activeCount)->toBe(2);
});

test('Post model match works with different model class', function () {
    $posts = Post::match()
        ->returning(Query::variable('n'))
        ->get();

    expect($posts)->toBeInstanceOf(Collection::class)
        ->and($posts)->toHaveCount(3)
        ->and($posts->first())->toBeInstanceOf(Post::class)
        ->and($posts->first()->title)->toBeString();
});

test('Model match preserves model attributes and original', function () {
    $user = User::match()
        ->where(Query::variable('n')->property('id')->equals(Query::literal(1)))
        ->returning(Query::variable('n'))
        ->first();

    expect($user->getAttributes())->toHaveKey('id')
        ->and($user->getAttributes())->toHaveKey('name')
        ->and($user->getAttributes())->toHaveKey('email')
        ->and($user->getOriginal())->toHaveKey('id')
        ->and($user->getOriginal())->toHaveKey('name')
        ->and($user->getOriginal())->toHaveKey('email')
        ->and($user->isDirty())->toBeFalse()
        ->and($user->wasRecentlyCreated)->toBeFalse();
});

test('Model match with ordering returns models in correct order', function () {
    $users = User::match()
        ->returning(Query::variable('n'))
        ->orderBy(Query::variable('n')->property('age'))
        ->get();

    expect($users->pluck('age')->toArray())->toBe([25, 30, 35]);

    $usersDesc = User::match()
        ->returning(Query::variable('n'))
        ->orderBy(Query::variable('n')->property('age'), 'DESC')
        ->get();

    expect($usersDesc->pluck('age')->toArray())->toBe([35, 30, 25]);
});

test('Model match with limit and skip paginates results', function () {
    $users = User::match()
        ->returning(Query::variable('n'))
        ->orderBy(Query::variable('n')->property('name'))
        ->skip(Query::literal(1))
        ->limit(Query::literal(1))
        ->get();

    expect($users)->toHaveCount(1)
        ->and($users->first()->name)->toBe('Jane Smith');
});

test('Model match hydrates null values correctly', function () {
    $user = User::match()
        ->where(Query::variable('n')->property('id')->equals(Query::literal(3)))
        ->returning(Query::variable('n'))
        ->first();

    expect($user->metadata)->toBeNull();

    $post = Post::match()
        ->where(Query::variable('n')->property('id')->equals(Query::literal(3)))
        ->returning(Query::variable('n'))
        ->first();

    expect($post->tags)->toBeNull();
});

test('Model match works with complex queries joining models', function () {
    // Note: This is testing that the DSL builder can be used with models
    // The actual join logic would need to return both nodes and handle hydration
    $query = User::match()
        ->match([
            Query::node('posts')->named('p'),
        ])
        ->where(Query::variable('p')->property('user_id')
            ->equals(Query::variable('n')->property('id')))
        ->where(Query::variable('p')->property('published')->equals(Query::literal(true)))
        ->returning([
            'user' => Query::variable('n'),
            'post' => Query::variable('p'),
        ]);

    $cypher = $query->toCypher();

    expect($cypher)->toContain('users')
        ->and($cypher)->toContain('posts');
});

test('Connection cypher without model returns stdClass objects', function () {
    $results = $this->connection->cypher()
        ->match(Query::node('users')->named('u'))
        ->returning(Query::variable('u'))
        ->orderBy(Query::variable('u')->property('name'))
        ->get();

    expect($results)->toBeInstanceOf(Collection::class)
        ->and($results->first())->toBeObject()
        ->and($results->first())->not()->toBeInstanceOf(User::class)
        ->and($results->first()->name ?? null)->toBe('Bob Wilson');
});

test('Model match applies model table name automatically', function () {
    $query = User::match();
    $cypher = $query->toCypher();

    // The match() method should automatically use the model's table name
    expect($cypher)->toContain('users');
});

test('Model matchFrom includes source node context', function () {
    $user = User::find(1);
    $query = $user->matchFrom();
    $cypher = $query->toCypher();

    // Should include the specific user node
    expect($cypher)->toContain('users')
        ->and($cypher)->toContain('1'); // The user ID should be in the query
});

test('Model match can use dd and dump for debugging', function () {
    $builder = User::match()
        ->where(Query::variable('n')->property('is_active')->equals(Query::literal(true)));

    // Test dump returns builder for chaining
    $result = $builder->dump();
    expect($result)->toBe($builder);

    // dd exists (we can't test it directly as it exits)
    expect(method_exists($builder, 'dd'))->toBeTrue();
});
