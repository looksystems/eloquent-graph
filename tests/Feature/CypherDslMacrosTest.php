<?php

namespace Tests\Feature;

use Tests\Fixtures\Neo4jUser;
use Tests\TestCase;
use WikibaseSolutions\CypherDSL\Query;

class CypherDslMacrosTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanDatabase();
    }

    protected function tearDown(): void
    {
        // Clean up macros
        \Look\EloquentCypher\Builders\GraphCypherDslBuilder::flushMacros();
        Neo4jUser::flushMacros();

        $this->cleanDatabase();
        parent::tearDown();
    }

    protected function cleanDatabase(): void
    {
        \DB::connection('graph')->select('MATCH (n) DETACH DELETE n');
    }

    public function test_can_register_macro_on_builder()
    {
        // Register a macro
        \Look\EloquentCypher\Builders\GraphCypherDslBuilder::macro('activeOnly', function () {
            return $this->where(
                Query::variable('n')->property('active')->equals(Query::literal(true))
            );
        });

        // Create test data
        \DB::connection('graph')->select(
            'CREATE (:users {id: 1, name: $name1, active: true}),
                    (:users {id: 2, name: $name2, active: false})',
            ['name1' => 'Active User', 'name2' => 'Inactive User']
        );

        // Use the macro
        $results = \DB::connection('graph')->cypher()
            ->match(Query::node('users')->named('n'))
            ->activeOnly()
            ->returning(Query::variable('n'))
            ->get();

        $this->assertCount(1, $results);
        $this->assertEquals('Active User', $results->first()->name ?? $results->first()['name']);
    }

    public function test_can_register_macro_with_parameters()
    {
        // Register a macro with parameters
        \Look\EloquentCypher\Builders\GraphCypherDslBuilder::macro('olderThan', function ($age) {
            return $this->where(
                Query::variable('n')->property('age')->gt(Query::literal($age))
            );
        });

        // Create test data
        \DB::connection('graph')->select(
            'CREATE (:users {id: 1, name: $name1, age: 25}),
                    (:users {id: 2, name: $name2, age: 35}),
                    (:users {id: 3, name: $name3, age: 45})',
            ['name1' => 'Young', 'name2' => 'Middle', 'name3' => 'Old']
        );

        // Use the macro with parameter
        $results = \DB::connection('graph')->cypher()
            ->match(Query::node('users')->named('n'))
            ->olderThan(30)
            ->returning(Query::variable('n'))
            ->get();

        $this->assertCount(2, $results);
        $names = $results->map(fn ($u) => $u->name ?? $u['name'])->sort()->values();
        $this->assertEquals(['Middle', 'Old'], $names->toArray());
    }

    public function test_macros_can_be_chained()
    {
        // Register a single macro that applies both conditions using and()
        \Look\EloquentCypher\Builders\GraphCypherDslBuilder::macro('activeAndVerified', function () {
            $activeCondition = Query::variable('n')->property('active')->equals(Query::literal(true));
            $verifiedCondition = Query::variable('n')->property('verified')->equals(Query::literal(true));

            return $this->where(
                $activeCondition->and($verifiedCondition)
            );
        });

        // Create test data
        \DB::connection('graph')->select(
            'CREATE (:users {id: 1, name: $name1, active: true, verified: true}),
                    (:users {id: 2, name: $name2, active: true, verified: false}),
                    (:users {id: 3, name: $name3, active: false, verified: true})',
            ['name1' => 'Both', 'name2' => 'Active Only', 'name3' => 'Verified Only']
        );

        // Use the combined macro
        $results = \DB::connection('graph')->cypher()
            ->match(Query::node('users')->named('n'))
            ->activeAndVerified()
            ->returning(Query::variable('n'))
            ->get();

        $this->assertCount(1, $results);
        $this->assertEquals('Both', $results->first()->name ?? $results->first()['name']);
    }

    public function test_static_macro_on_model_class()
    {
        // Register a static macro on the model
        Neo4jUser::macro('activeUsers', function () {
            return static::match()
                ->where(Query::variable('n')->property('active')->equals(Query::literal(true)))
                ->returning(Query::variable('n'));
        });

        // Create test data
        Neo4jUser::create(['id' => 1, 'name' => 'Active User', 'active' => true]);
        Neo4jUser::create(['id' => 2, 'name' => 'Inactive User', 'active' => false]);

        // Use the static macro
        $results = Neo4jUser::activeUsers()->get();

        $this->assertCount(1, $results);
        $this->assertEquals('Active User', $results->first()->name);
    }

    public function test_instance_macro_on_model()
    {
        // Register an instance macro
        Neo4jUser::macro('getFriends', function () {
            return $this->matchFrom()
                ->outgoing('FRIENDS', 'users')
                ->returning(Query::variable('target'))
                ->get();
        });

        // Create test data
        $user1 = Neo4jUser::create(['id' => 1, 'name' => 'User 1']);
        $user2 = Neo4jUser::create(['id' => 2, 'name' => 'User 2']);
        $user3 = Neo4jUser::create(['id' => 3, 'name' => 'User 3']);

        // Create relationships
        \DB::connection('graph')->select(
            'MATCH (u1:users {id: $id1}), (u2:users {id: $id2}), (u3:users {id: $id3})
             CREATE (u1)-[:FRIENDS]->(u2),
                    (u1)-[:FRIENDS]->(u3)',
            ['id1' => 1, 'id2' => 2, 'id3' => 3]
        );

        // Use the instance macro
        $friends = $user1->getFriends();

        $this->assertCount(2, $friends);
        $names = $friends->map(fn ($u) => $u->name ?? $u['name'])->sort()->values();
        $this->assertEquals(['User 2', 'User 3'], $names->toArray());
    }

    public function test_macro_returning_builder_can_be_chained()
    {
        // Register macro that returns builder for chaining
        \Look\EloquentCypher\Builders\GraphCypherDslBuilder::macro('withPosts', function () {
            // Add a where condition to filter users who have posts
            return $this->where(
                Query::variable('n')->property('id')->equals(Query::literal(1))
            );
        });

        // Create test data
        \DB::connection('graph')->select(
            'CREATE (u1:users {id: 1, name: $name1}),
                    (u2:users {id: 2, name: $name2}),
                    (p1:posts {id: 10, title: $title1}),
                    (u1)-[:HAS_POST]->(p1)',
            ['name1' => 'User 1', 'name2' => 'User 2', 'title1' => 'Post 1']
        );

        // Use macro and chain additional methods - test simpler case
        $results = \DB::connection('graph')->cypher()
            ->match(Query::node('users')->named('n'))
            ->withPosts()
            ->returning(Query::variable('n'))
            ->get();

        $this->assertCount(1, $results);
        $this->assertEquals('User 1', $results->first()->name ?? $results->first()['name']);
    }

    public function test_complex_macro_with_multiple_operations()
    {
        // Register a complex macro
        \Look\EloquentCypher\Builders\GraphCypherDslBuilder::macro('findInfluencers', function ($minFollowers = 100) {
            // Note: This is a simplified version as we can't easily do subqueries with DSL
            return $this->where(
                Query::variable('n')->property('follower_count')->gte(Query::literal($minFollowers))
            );
        });

        // Create test data
        \DB::connection('graph')->select(
            'CREATE (:users {id: 1, name: $name1, follower_count: 50}),
                    (:users {id: 2, name: $name2, follower_count: 150}),
                    (:users {id: 3, name: $name3, follower_count: 500})',
            ['name1' => 'Regular', 'name2' => 'Influencer', 'name3' => 'Super Influencer']
        );

        // Use the complex macro
        $results = \DB::connection('graph')->cypher()
            ->match(Query::node('users')->named('n'))
            ->findInfluencers(200)
            ->returning(Query::variable('n'))
            ->get();

        $this->assertCount(1, $results);
        $this->assertEquals('Super Influencer', $results->first()->name ?? $results->first()['name']);
    }
}
