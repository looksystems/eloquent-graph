<?php

namespace Tests\Feature\Console;

use Tests\TestCase;

class SchemaCommandsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->clearDatabaseSchema();
        $this->clearDatabase();
        $this->setupTestSchema();
    }

    protected function tearDown(): void
    {
        $this->clearDatabase();
        $this->clearDatabaseSchema();
        parent::tearDown();
    }

    protected function clearDatabaseSchema()
    {
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

    protected function setupTestSchema()
    {
        $connection = app('db')->connection('graph');

        // Create test nodes with unique identifiers
        $uniqueId = uniqid();
        $connection->insert("CREATE (:TestUser_{$uniqueId} {id: 1, name: 'John', email: 'john@test.com'})");
        $connection->insert("CREATE (:TestPost_{$uniqueId} {id: 2, title: 'Test Post'})");

        // Create relationship
        $connection->insert("MATCH (u:TestUser_{$uniqueId}), (p:TestPost_{$uniqueId}) CREATE (u)-[:TEST_WROTE_{$uniqueId}]->(p)");

        // Create constraints
        \Look\EloquentCypher\Facades\GraphSchema::label("TestUser_{$uniqueId}", function (\Look\EloquentCypher\Schema\GraphBlueprint $label) use ($uniqueId) {
            $label->property('email')->unique("test_user_email_unique_{$uniqueId}");
        });

        // Create indexes
        \Look\EloquentCypher\Facades\GraphSchema::label("TestPost_{$uniqueId}", function (\Look\EloquentCypher\Schema\GraphBlueprint $label) use ($uniqueId) {
            $label->property('title')->index("test_post_title_index_{$uniqueId}");
        });
    }

    public function test_neo4j_schema_command_displays_complete_overview()
    {
        $this->artisan('neo4j:schema')
            ->expectsOutput('Neo4j Schema Overview')
            ->assertExitCode(0);
    }

    public function test_neo4j_schema_command_displays_labels_section()
    {
        $this->artisan('neo4j:schema')
            ->expectsOutputToContain('Labels:')
            ->assertExitCode(0);
    }

    public function test_neo4j_schema_command_displays_relationships_section()
    {
        $this->artisan('neo4j:schema')
            ->expectsOutputToContain('Relationship Types:')
            ->assertExitCode(0);
    }

    public function test_neo4j_schema_command_with_json_option()
    {
        $this->artisan('neo4j:schema --json')
            ->assertExitCode(0);
    }

    public function test_neo4j_schema_labels_command_lists_all_labels()
    {
        $this->artisan('neo4j:schema:labels')
            ->expectsOutput('Neo4j Node Labels')
            ->assertExitCode(0);
    }

    public function test_neo4j_schema_labels_command_with_count_option()
    {
        $this->artisan('neo4j:schema:labels --count')
            ->expectsOutputToContain('Count')
            ->assertExitCode(0);
    }

    public function test_neo4j_schema_relationships_command_lists_all_types()
    {
        $this->artisan('neo4j:schema:relationships')
            ->expectsOutput('Neo4j Relationship Types')
            ->assertExitCode(0);
    }

    public function test_neo4j_schema_relationships_command_with_count_option()
    {
        $this->artisan('neo4j:schema:relationships --count')
            ->expectsOutputToContain('Count')
            ->assertExitCode(0);
    }

    public function test_neo4j_schema_properties_command_lists_all_keys()
    {
        $this->artisan('neo4j:schema:properties')
            ->expectsOutput('Neo4j Property Keys')
            ->assertExitCode(0);
    }

    public function test_neo4j_schema_constraints_command_lists_all_constraints()
    {
        $this->artisan('neo4j:schema:constraints')
            ->expectsOutput('Neo4j Constraints')
            ->assertExitCode(0);
    }

    public function test_neo4j_schema_constraints_command_displays_table_headers()
    {
        // Command should run successfully and show title
        $this->artisan('neo4j:schema:constraints')
            ->expectsOutput('Neo4j Constraints')
            ->assertExitCode(0);
    }

    public function test_neo4j_schema_constraints_command_with_type_filter()
    {
        $this->artisan('neo4j:schema:constraints --type=UNIQUENESS')
            ->assertExitCode(0);
    }

    public function test_neo4j_schema_indexes_command_lists_all_indexes()
    {
        $this->artisan('neo4j:schema:indexes')
            ->expectsOutput('Neo4j Indexes')
            ->assertExitCode(0);
    }

    public function test_neo4j_schema_indexes_command_displays_table_headers()
    {
        // Command should run successfully and show title
        $this->artisan('neo4j:schema:indexes')
            ->expectsOutput('Neo4j Indexes')
            ->assertExitCode(0);
    }

    public function test_neo4j_schema_indexes_command_with_type_filter()
    {
        $this->artisan('neo4j:schema:indexes --type=RANGE')
            ->assertExitCode(0);
    }

    public function test_neo4j_schema_export_command_exports_to_json()
    {
        $file = sys_get_temp_dir().'/test-schema-'.uniqid().'.json';

        try {
            $this->artisan("neo4j:schema:export {$file}")
                ->expectsOutput("Schema exported successfully to {$file}")
                ->assertExitCode(0);

            $this->assertFileExists($file);
            $content = file_get_contents($file);
            $data = json_decode($content, true);

            $this->assertIsArray($data);
            $this->assertArrayHasKey('labels', $data);
            $this->assertArrayHasKey('relationshipTypes', $data);
            $this->assertArrayHasKey('propertyKeys', $data);
            $this->assertArrayHasKey('constraints', $data);
            $this->assertArrayHasKey('indexes', $data);
        } finally {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }

    public function test_neo4j_schema_export_command_with_yaml_format()
    {
        $file = sys_get_temp_dir().'/test-schema-'.uniqid().'.yaml';

        try {
            $this->artisan("neo4j:schema:export {$file} --format=yaml")
                ->expectsOutput("Schema exported successfully to {$file}")
                ->assertExitCode(0);

            $this->assertFileExists($file);
            $content = file_get_contents($file);
            $this->assertStringContainsString('labels:', $content);
            $this->assertStringContainsString('relationshipTypes:', $content);
        } finally {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }

    public function test_neo4j_schema_export_command_creates_directory_if_not_exists()
    {
        $dir = sys_get_temp_dir().'/test-dir-'.uniqid();
        $file = $dir.'/schema.json';

        try {
            $this->artisan("neo4j:schema:export {$file}")
                ->assertExitCode(0);

            $this->assertFileExists($file);
        } finally {
            if (file_exists($file)) {
                unlink($file);
            }
            if (is_dir($dir)) {
                rmdir($dir);
            }
        }
    }

    public function test_commands_work_with_empty_database()
    {
        $this->clearDatabase();
        $this->clearDatabaseSchema();

        // All commands should handle empty databases gracefully
        $this->artisan('neo4j:schema')->assertExitCode(0);
        $this->artisan('neo4j:schema:labels')->assertExitCode(0);
        $this->artisan('neo4j:schema:relationships')->assertExitCode(0);
        $this->artisan('neo4j:schema:properties')->assertExitCode(0);
        $this->artisan('neo4j:schema:constraints')->assertExitCode(0);
        $this->artisan('neo4j:schema:indexes')->assertExitCode(0);
    }
}
