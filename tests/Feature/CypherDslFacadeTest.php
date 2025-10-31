<?php

namespace Tests\Feature;

use Look\EloquentCypher\Facades\Cypher;
use Look\EloquentCypher\Support\CypherDslFactory;
use Tests\TestCase;
use WikibaseSolutions\CypherDSL\Parameter;
use WikibaseSolutions\CypherDSL\Patterns\Node;
use WikibaseSolutions\CypherDSL\Patterns\Path;
use WikibaseSolutions\CypherDSL\Query;

class CypherDslFacadeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Register the facade accessor in the app container
        $this->app->singleton('neo4j.cypher.dsl', function ($app) {
            return new CypherDslFactory($app['db']);
        });

        $this->cleanDatabase();
    }

    protected function tearDown(): void
    {
        $this->cleanDatabase();
        parent::tearDown();
    }

    protected function cleanDatabase(): void
    {
        \DB::connection('graph')->select('MATCH (n) DETACH DELETE n');
    }

    public function test_facade_resolves_to_factory()
    {
        $factory = Cypher::getFacadeRoot();
        $this->assertInstanceOf(CypherDslFactory::class, $factory);
    }

    public function test_facade_query_method_returns_builder()
    {
        $builder = Cypher::query();
        $this->assertInstanceOf(\Look\EloquentCypher\Builders\GraphCypherDslBuilder::class, $builder);
    }

    public function test_facade_node_method_returns_node()
    {
        $node = Cypher::node('User');
        $this->assertInstanceOf(Node::class, $node);
        $this->assertEquals('User', $node->getLabels()[0]);
    }

    public function test_facade_parameter_method_returns_parameter()
    {
        $param = Cypher::parameter('age');
        $this->assertInstanceOf(Parameter::class, $param);
        // Parameter requires a name, cannot be created without one
    }

    public function test_facade_relationship_method_returns_relationship()
    {
        $rel = Cypher::relationship();
        $this->assertInstanceOf(Path::class, $rel);
    }

    public function test_facade_query_builder_can_execute_queries()
    {
        // Create test data
        \DB::connection('graph')->select(
            'CREATE (u:users {id: $id, name: $name})',
            ['id' => 1, 'name' => 'John Doe']
        );

        $results = Cypher::query()
            ->match(Query::node('users')->named('u'))
            ->returning(Query::variable('u'))
            ->get();

        $this->assertCount(1, $results);
        $this->assertEquals('John Doe', $results->first()->name ?? $results->first()['name']);
    }

    public function test_facade_query_with_complex_pattern()
    {
        // Create test data
        \DB::connection('graph')->select(
            'CREATE (u:users {id: 1, name: $name, age: 30}),
                    (p:posts {id: 2, title: $title}),
                    (u)-[:HAS_POST]->(p)',
            ['name' => 'Jane', 'title' => 'Test Post']
        );

        $results = Cypher::query()
            ->match(
                Query::node('users')->named('u')
                    ->relationshipTo(Query::node('posts')->named('p'), ['HAS_POST'])
            )
            ->returning([
                'u' => Query::variable('u'),
                'p' => Query::variable('p'),
            ])
            ->get();

        $this->assertCount(1, $results);
        $row = $results->first();
        $this->assertEquals('Jane', $row->u->name ?? $row->u['name']);
        $this->assertEquals('Test Post', $row->p->title ?? $row->p['title']);
    }

    public function test_facade_can_use_parameters_in_queries()
    {
        // Create test data
        \DB::connection('graph')->select(
            'CREATE (:users {id: 1, name: $name1, age: 25}),
                    (:users {id: 2, name: $name2, age: 35})',
            ['name1' => 'Young User', 'name2' => 'Old User']
        );

        $minAge = 30;
        $results = Cypher::query()
            ->match(Query::node('users')->named('u'))
            ->where(Query::variable('u')->property('age')->gte(Query::literal($minAge)))
            ->returning(Query::variable('u'))
            ->get();

        $this->assertCount(1, $results);
        $this->assertEquals('Old User', $results->first()->name ?? $results->first()['name']);
    }

    public function test_facade_helper_methods_provide_convenience()
    {
        // Create test data
        \DB::connection('graph')->select(
            'CREATE (u:users {id: 1, name: $name})',
            ['name' => 'Test User']
        );

        // Using facade helper methods to build query components
        $userNode = Cypher::node('users')->named('u');

        $results = Cypher::query()
            ->match($userNode)
            ->returning(Query::variable('u'))
            ->get();

        $this->assertCount(1, $results);
        $this->assertEquals('Test User', $results->first()->name ?? $results->first()['name']);
    }

    public function test_facade_works_with_multiple_database_connections()
    {
        // Create data
        \DB::connection('graph')->select(
            'CREATE (u:users {id: 1, name: $name})',
            ['name' => 'Neo4j User']
        );

        // Query should use Neo4j connection automatically
        $results = Cypher::query()
            ->match(Query::node('users')->named('u'))
            ->returning(Query::variable('u'))
            ->get();

        $this->assertCount(1, $results);
        $this->assertEquals('Neo4j User', $results->first()->name ?? $results->first()['name']);
    }
}
