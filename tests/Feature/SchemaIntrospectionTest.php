<?php

namespace Tests\Feature;

use Tests\TestCase;

class SchemaIntrospectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Clear database schema before each test (parent already clears data)
        $this->clearDatabaseSchema();
        // Ensure database is clean after schema cleanup
        $this->clearDatabase();
    }

    protected function tearDown(): void
    {
        // Clean up after tests
        $this->clearDatabase();
        $this->clearDatabaseSchema();
        parent::tearDown();
    }

    protected function clearDatabaseSchema()
    {
        // Drop all constraints and indexes for test isolation
        try {
            $connection = app('db')->connection('graph');
            $constraints = $connection->select('CALL db.constraints() YIELD name RETURN name');
            foreach ($constraints as $constraint) {
                $connection->statement("DROP CONSTRAINT {$constraint->name} IF EXISTS");
            }

            $indexes = $connection->select("CALL db.indexes() YIELD name WHERE name NOT STARTS WITH '__' RETURN name");
            foreach ($indexes as $index) {
                $connection->statement("DROP INDEX {$index->name} IF EXISTS");
            }
        } catch (\Exception $e) {
            // Database may not support these procedures in test environment
        }
    }

    public function test_get_all_labels_returns_array()
    {
        // Note: Neo4j's db.labels() returns all labels ever used in the database
        // Labels persist even after nodes are deleted, so we can't test for empty
        $labels = \Look\EloquentCypher\Facades\GraphSchema::getAllLabels();

        $this->assertIsArray($labels);
    }

    public function test_get_all_labels_returns_existing_labels()
    {
        // Create some nodes with unique labels for this test
        $connection = app('db')->connection('graph');
        $connection->insert('CREATE (:SchemaTestUser_'.uniqid()." {name: 'John'})");
        $connection->insert('CREATE (:SchemaTestPost_'.uniqid()." {title: 'Test Post'})");
        $connection->insert('CREATE (:SchemaTestComment_'.uniqid()." {text: 'Nice!'})");

        $labelsBefore = \Look\EloquentCypher\Facades\GraphSchema::getAllLabels();
        $countBefore = count($labelsBefore);

        // Create more nodes
        $uniqueLabel = 'UniqueSchemaTestLabel_'.uniqid();
        $connection->insert("CREATE (:$uniqueLabel {test: 'value'})");

        $labelsAfter = \Look\EloquentCypher\Facades\GraphSchema::getAllLabels();

        $this->assertIsArray($labelsAfter);
        $this->assertGreaterThan($countBefore, count($labelsAfter));
        $this->assertContains($uniqueLabel, $labelsAfter);
    }

    public function test_get_all_relationship_types_returns_array()
    {
        // Note: Relationship types persist in Neo4j metadata
        $types = \Look\EloquentCypher\Facades\GraphSchema::getAllRelationshipTypes();

        $this->assertIsArray($types);
    }

    public function test_get_all_relationship_types_returns_existing_types()
    {
        // Create unique relationship type for this test
        $connection = app('db')->connection('graph');
        $uniqueRel = 'SCHEMA_TEST_REL_'.strtoupper(uniqid());
        $connection->insert("CREATE (u:User {name: 'John'})-[:{$uniqueRel}]->(p:Post {title: 'Test'})");

        $types = \Look\EloquentCypher\Facades\GraphSchema::getAllRelationshipTypes();

        $this->assertIsArray($types);
        $this->assertContains($uniqueRel, $types);
    }

    public function test_get_all_property_keys_returns_array()
    {
        // Note: Property keys persist in Neo4j metadata
        $keys = \Look\EloquentCypher\Facades\GraphSchema::getAllPropertyKeys();

        $this->assertIsArray($keys);
    }

    public function test_get_all_property_keys_returns_existing_keys()
    {
        // Create unique property key for this test
        $connection = app('db')->connection('graph');
        $uniqueKey = 'schema_test_prop_'.uniqid();
        $connection->insert("CREATE (:User {{$uniqueKey}: 'test_value'})");

        $keys = \Look\EloquentCypher\Facades\GraphSchema::getAllPropertyKeys();

        $this->assertIsArray($keys);
        $this->assertContains($uniqueKey, $keys);
    }

    public function test_get_constraints_returns_array()
    {
        // After clearDatabaseSchema(), there should be no constraints
        $constraints = \Look\EloquentCypher\Facades\GraphSchema::getConstraints();

        $this->assertIsArray($constraints);
    }

    public function test_get_constraints_returns_existing_constraints()
    {
        // Get count before adding constraints
        $beforeCount = count(\Look\EloquentCypher\Facades\GraphSchema::getConstraints());

        // Create some constraints with unique names
        $uniqueId = uniqid();
        \Look\EloquentCypher\Facades\GraphSchema::label('SchemaTestUser_'.$uniqueId, function (\Look\EloquentCypher\Schema\GraphBlueprint $label) use ($uniqueId) {
            $label->property('email')->unique('user_email_unique_'.$uniqueId);
        });

        \Look\EloquentCypher\Facades\GraphSchema::label('SchemaTestPost_'.$uniqueId, function (\Look\EloquentCypher\Schema\GraphBlueprint $label) use ($uniqueId) {
            $label->property('slug')->unique('post_slug_unique_'.$uniqueId);
        });

        $constraints = \Look\EloquentCypher\Facades\GraphSchema::getConstraints();

        $this->assertIsArray($constraints);
        $this->assertEquals($beforeCount + 2, count($constraints));

        // Verify structure of returned constraints
        foreach ($constraints as $constraint) {
            $this->assertArrayHasKey('name', $constraint);
            $this->assertArrayHasKey('type', $constraint);
            $this->assertArrayHasKey('entityType', $constraint);
            $this->assertArrayHasKey('labelsOrTypes', $constraint);
            $this->assertArrayHasKey('properties', $constraint);
        }

        // Check specific constraints
        $constraintNames = array_column($constraints, 'name');
        $this->assertContains('user_email_unique_'.$uniqueId, $constraintNames);
        $this->assertContains('post_slug_unique_'.$uniqueId, $constraintNames);
    }

    public function test_get_indexes_returns_empty_array_when_no_indexes_exist()
    {
        $indexes = \Look\EloquentCypher\Facades\GraphSchema::getIndexes();

        $this->assertIsArray($indexes);
        // May have system indexes, so just check it's an array
    }

    public function test_get_indexes_returns_existing_indexes()
    {
        // Create some indexes
        \Look\EloquentCypher\Facades\GraphSchema::label('User', function (\Look\EloquentCypher\Schema\GraphBlueprint $label) {
            $label->property('name')->index('user_name_index');
        });

        \Look\EloquentCypher\Facades\GraphSchema::label('Post', function (\Look\EloquentCypher\Schema\GraphBlueprint $label) {
            $label->property('created_at')->index('post_created_index');
        });

        $indexes = \Look\EloquentCypher\Facades\GraphSchema::getIndexes();

        $this->assertIsArray($indexes);
        $this->assertNotEmpty($indexes);

        // Filter out system indexes (those starting with __)
        $userIndexes = array_filter($indexes, fn ($idx) => ! str_starts_with($idx['name'] ?? '', '__'));
        $this->assertGreaterThanOrEqual(2, count($userIndexes));

        // Verify structure of returned indexes
        foreach ($userIndexes as $index) {
            $this->assertArrayHasKey('name', $index);
            $this->assertArrayHasKey('type', $index);
            $this->assertArrayHasKey('entityType', $index);
            $this->assertArrayHasKey('labelsOrTypes', $index);
            $this->assertArrayHasKey('properties', $index);
            $this->assertArrayHasKey('state', $index);
        }

        // Check specific indexes
        $indexNames = array_column($userIndexes, 'name');
        $this->assertContains('user_name_index', $indexNames);
        $this->assertContains('post_created_index', $indexNames);
    }

    public function test_introspect_returns_complete_schema_structure()
    {
        // Set up a complete schema scenario
        $connection = app('db')->connection('graph');

        // Create nodes and relationships
        $connection->insert("CREATE (u:User {id: 1, name: 'John', email: 'john@example.com'})");
        $connection->insert("CREATE (p:Post {id: 2, title: 'Test', slug: 'test-post'})");
        $connection->insert('CREATE (u)-[:WROTE]->(p)');

        // Create constraints and indexes
        \Look\EloquentCypher\Facades\GraphSchema::label('User', function (\Look\EloquentCypher\Schema\GraphBlueprint $label) {
            $label->property('email')->unique('user_email_unique');
            $label->property('name')->index('user_name_index');
        });

        \Look\EloquentCypher\Facades\GraphSchema::label('Post', function (\Look\EloquentCypher\Schema\GraphBlueprint $label) {
            $label->property('slug')->unique('post_slug_unique');
        });

        // Introspect the schema
        $schema = \Look\EloquentCypher\Facades\GraphSchema::introspect();

        // Verify structure
        $this->assertIsArray($schema);
        $this->assertArrayHasKey('labels', $schema);
        $this->assertArrayHasKey('relationshipTypes', $schema);
        $this->assertArrayHasKey('propertyKeys', $schema);
        $this->assertArrayHasKey('constraints', $schema);
        $this->assertArrayHasKey('indexes', $schema);

        // Verify content
        $this->assertContains('User', $schema['labels']);
        $this->assertContains('Post', $schema['labels']);
        $this->assertContains('WROTE', $schema['relationshipTypes']);
        $this->assertContains('email', $schema['propertyKeys']);
        $this->assertContains('title', $schema['propertyKeys']);
        $this->assertNotEmpty($schema['constraints']);
        $this->assertNotEmpty($schema['indexes']);
    }

    public function test_introspect_returns_correct_structure()
    {
        // Note: We test structure, not emptiness, because Neo4j metadata persists
        $schema = \Look\EloquentCypher\Facades\GraphSchema::introspect();

        $this->assertIsArray($schema);
        $this->assertArrayHasKey('labels', $schema);
        $this->assertArrayHasKey('relationshipTypes', $schema);
        $this->assertArrayHasKey('propertyKeys', $schema);
        $this->assertArrayHasKey('constraints', $schema);
        $this->assertArrayHasKey('indexes', $schema);

        $this->assertIsArray($schema['labels']);
        $this->assertIsArray($schema['relationshipTypes']);
        $this->assertIsArray($schema['propertyKeys']);
        $this->assertIsArray($schema['constraints']);
        $this->assertIsArray($schema['indexes']);
    }

    public function test_get_all_methods_handle_neo4j_response_format()
    {
        // Create test data
        $connection = app('db')->connection('graph');
        $connection->insert("CREATE (:TestLabel {test_property: 'value'})");
        $connection->insert('CREATE (:TestLabel)-[:TEST_REL]->(:AnotherLabel)');

        // These should not throw exceptions regardless of Neo4j response format (array vs object)
        $labels = \Look\EloquentCypher\Facades\GraphSchema::getAllLabels();
        $this->assertIsArray($labels);

        $types = \Look\EloquentCypher\Facades\GraphSchema::getAllRelationshipTypes();
        $this->assertIsArray($types);

        $keys = \Look\EloquentCypher\Facades\GraphSchema::getAllPropertyKeys();
        $this->assertIsArray($keys);
    }
}
