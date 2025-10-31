<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MigrationsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Clear any existing constraints or indexes before each test
        $this->clearDatabaseSchema();
    }

    protected function tearDown(): void
    {
        // Clean up after tests
        $this->clearDatabaseSchema();
        parent::tearDown();
    }

    private function clearDatabaseSchema()
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

    public function test_can_create_node_label_schema()
    {
        \Look\EloquentCypher\Facades\GraphSchema::label('User', function (\Look\EloquentCypher\Schema\GraphBlueprint $label) {
            $label->property('email')->unique();
            $label->property('name')->index();
        });

        // Verify the constraint and index were created
        $connection = app('db')->connection('graph');
        $constraints = $connection->select('SHOW CONSTRAINTS');
        $indexes = $connection->select('SHOW INDEXES');

        $this->assertTrue($this->hasConstraint($constraints, 'User', 'email'));
        $this->assertTrue($this->hasIndex($indexes, 'User', 'name'));
    }

    public function test_can_drop_node_label()
    {
        // First create a label with constraints
        \Look\EloquentCypher\Facades\GraphSchema::label('TempUser', function (\Look\EloquentCypher\Schema\GraphBlueprint $label) {
            $label->property('email')->unique();
        });

        // Now drop the label
        \Look\EloquentCypher\Facades\GraphSchema::dropLabel('TempUser');

        // Verify constraints are removed
        $connection = app('db')->connection('graph');
        $constraints = $connection->select('SHOW CONSTRAINTS');
        $this->assertFalse($this->hasConstraint($constraints, 'TempUser', 'email'));
    }

    public function test_can_create_unique_constraint()
    {
        \Look\EloquentCypher\Facades\GraphSchema::label('Product', function (\Look\EloquentCypher\Schema\GraphBlueprint $label) {
            $label->property('sku')->unique();
        });

        // Try to insert duplicate SKUs - should fail
        $connection = app('db')->connection('graph');
        $connection->insert("CREATE (p:Product {sku: 'ABC123'})");

        $this->expectException(\Exception::class);
        $connection->insert("CREATE (p:Product {sku: 'ABC123'})");
    }

    public function test_can_create_composite_index()
    {
        \Look\EloquentCypher\Facades\GraphSchema::label('Transaction', function (\Look\EloquentCypher\Schema\GraphBlueprint $label) {
            $label->index(['user_id', 'created_at']);
        });

        // Verify composite index was created
        $connection = app('db')->connection('graph');
        $indexes = $connection->select('SHOW INDEXES');
        $this->assertTrue($this->hasCompositeIndex($indexes, 'Transaction', ['user_id', 'created_at']));
    }

    public function test_can_create_text_index()
    {
        \Look\EloquentCypher\Facades\GraphSchema::label('Article', function (\Look\EloquentCypher\Schema\GraphBlueprint $label) {
            $label->property('content')->textIndex();
        });

        // Verify text index was created
        $connection = app('db')->connection('graph');
        $indexes = $connection->select('SHOW INDEXES');
        $this->assertTrue($this->hasTextIndex($indexes, 'Article', 'content'));
    }

    public function test_can_create_relationship_schema()
    {
        \Look\EloquentCypher\Facades\GraphSchema::relationship('FOLLOWS', function (\Look\EloquentCypher\Schema\GraphBlueprint $relationship) {
            $relationship->property('since')->index();
            $relationship->property('relationship_id')->unique();
        });

        // Verify relationship indexes were created
        $connection = app('db')->connection('graph');
        $indexes = $connection->select('SHOW INDEXES');
        $this->assertTrue($this->hasRelationshipIndex($indexes, 'FOLLOWS', 'since'));
    }

    public function test_can_check_if_label_exists()
    {
        \Look\EloquentCypher\Facades\GraphSchema::label('TestLabel', function (\Look\EloquentCypher\Schema\GraphBlueprint $label) {
            $label->property('id')->unique();
        });

        $this->assertTrue(\Look\EloquentCypher\Facades\GraphSchema::hasLabel('TestLabel'));
        $this->assertFalse(\Look\EloquentCypher\Facades\GraphSchema::hasLabel('NonExistentLabel'));
    }

    public function test_can_check_if_constraint_exists()
    {
        \Look\EloquentCypher\Facades\GraphSchema::label('ConstraintTest', function (\Look\EloquentCypher\Schema\GraphBlueprint $label) {
            $label->property('unique_field')->unique('unique_constraint_name');
        });

        $this->assertTrue(\Look\EloquentCypher\Facades\GraphSchema::hasConstraint('unique_constraint_name'));
        $this->assertFalse(\Look\EloquentCypher\Facades\GraphSchema::hasConstraint('non_existent_constraint'));
    }

    public function test_can_check_if_index_exists()
    {
        \Look\EloquentCypher\Facades\GraphSchema::label('IndexTest', function (\Look\EloquentCypher\Schema\GraphBlueprint $label) {
            $label->property('indexed_field')->index('test_index_name');
        });

        $this->assertTrue(\Look\EloquentCypher\Facades\GraphSchema::hasIndex('test_index_name'));
        $this->assertFalse(\Look\EloquentCypher\Facades\GraphSchema::hasIndex('non_existent_index'));
    }

    public function test_can_drop_constraint_by_name()
    {
        \Look\EloquentCypher\Facades\GraphSchema::label('DropTest', function (\Look\EloquentCypher\Schema\GraphBlueprint $label) {
            $label->property('field')->unique('drop_me_constraint');
        });

        $this->assertTrue(\Look\EloquentCypher\Facades\GraphSchema::hasConstraint('drop_me_constraint'));

        \Look\EloquentCypher\Facades\GraphSchema::dropConstraint('drop_me_constraint');

        $this->assertFalse(\Look\EloquentCypher\Facades\GraphSchema::hasConstraint('drop_me_constraint'));
    }

    public function test_can_drop_index_by_name()
    {
        \Look\EloquentCypher\Facades\GraphSchema::label('DropIndexTest', function (\Look\EloquentCypher\Schema\GraphBlueprint $label) {
            $label->property('field')->index('drop_me_index');
        });

        $this->assertTrue(\Look\EloquentCypher\Facades\GraphSchema::hasIndex('drop_me_index'));

        \Look\EloquentCypher\Facades\GraphSchema::dropIndex('drop_me_index');

        $this->assertFalse(\Look\EloquentCypher\Facades\GraphSchema::hasIndex('drop_me_index'));
    }

    public function test_can_rename_constraint()
    {
        \Look\EloquentCypher\Facades\GraphSchema::label('RenameTest', function (\Look\EloquentCypher\Schema\GraphBlueprint $label) {
            $label->property('field')->unique('old_constraint_name');
        });

        \Look\EloquentCypher\Facades\GraphSchema::renameConstraint('old_constraint_name', 'new_constraint_name');

        $this->assertFalse(\Look\EloquentCypher\Facades\GraphSchema::hasConstraint('old_constraint_name'));
        $this->assertTrue(\Look\EloquentCypher\Facades\GraphSchema::hasConstraint('new_constraint_name'));
    }

    public function test_migration_rollback_removes_constraints_and_indexes()
    {
        // Create schema
        \Look\EloquentCypher\Facades\GraphSchema::label('Rollback', function (\Look\EloquentCypher\Schema\GraphBlueprint $label) {
            $label->property('email')->unique('rollback_unique');
            $label->property('name')->index('rollback_index');
        });

        $this->assertTrue(\Look\EloquentCypher\Facades\GraphSchema::hasConstraint('rollback_unique'));
        $this->assertTrue(\Look\EloquentCypher\Facades\GraphSchema::hasIndex('rollback_index'));

        // Simulate rollback
        \Look\EloquentCypher\Facades\GraphSchema::dropConstraint('rollback_unique');
        \Look\EloquentCypher\Facades\GraphSchema::dropIndex('rollback_index');

        $this->assertFalse(\Look\EloquentCypher\Facades\GraphSchema::hasConstraint('rollback_unique'));
        $this->assertFalse(\Look\EloquentCypher\Facades\GraphSchema::hasIndex('rollback_index'));
    }

    public function test_schema_builder_works_with_connection()
    {
        $connection = app('db')->connection('graph');
        $builder = $connection->getSchemaBuilder();

        $this->assertInstanceOf(\Look\EloquentCypher\Schema\GraphSchemaBuilder::class, $builder);
    }

    // Helper methods for assertions
    private function hasConstraint($constraints, $label, $property)
    {
        foreach ($constraints as $constraint) {
            // Handle both array and object responses
            if (is_array($constraint)) {
                $labelsOrTypes = $constraint['labelsOrTypes'] ?? [];
                $properties = $constraint['properties'] ?? [];
            } else {
                $labelsOrTypes = $constraint->labelsOrTypes ?? [];
                $properties = $constraint->properties ?? [];
            }

            if (in_array($label, $labelsOrTypes) && in_array($property, $properties)) {
                return true;
            }
        }

        return false;
    }

    private function hasIndex($indexes, $label, $property)
    {
        foreach ($indexes as $index) {
            // Handle both array and object responses
            if (is_array($index)) {
                $labelsOrTypes = $index['labelsOrTypes'] ?? [];
                $properties = $index['properties'] ?? [];
                $owningConstraint = $index['owningConstraint'] ?? null;
            } else {
                $labelsOrTypes = $index->labelsOrTypes ?? [];
                $properties = $index->properties ?? [];
                $owningConstraint = $index->owningConstraint ?? null;
            }

            // Skip indexes owned by constraints (those are for unique constraints)
            if ($owningConstraint !== null) {
                continue;
            }

            if (in_array($label, $labelsOrTypes) && in_array($property, $properties)) {
                return true;
            }
        }

        return false;
    }

    private function hasCompositeIndex($indexes, $label, $properties)
    {
        foreach ($indexes as $index) {
            // Handle both array and object responses
            if (is_array($index)) {
                $labelsOrTypes = $index['labelsOrTypes'] ?? [];
                $indexProperties = $index['properties'] ?? [];
            } else {
                $labelsOrTypes = $index->labelsOrTypes ?? [];
                $indexProperties = $index->properties ?? [];
            }

            // Check if label matches and properties array matches exactly
            if (in_array($label, $labelsOrTypes) && $indexProperties === $properties) {
                return true;
            }
        }

        return false;
    }

    private function hasTextIndex($indexes, $label, $property)
    {
        foreach ($indexes as $index) {
            // Handle both array and object responses
            if (is_array($index)) {
                $type = $index['type'] ?? '';
                $labelsOrTypes = $index['labelsOrTypes'] ?? [];
                $properties = $index['properties'] ?? [];
            } else {
                $type = $index->type ?? '';
                $labelsOrTypes = $index->labelsOrTypes ?? [];
                $properties = $index->properties ?? [];
            }

            if ($type === 'TEXT' && in_array($label, $labelsOrTypes) && in_array($property, $properties)) {
                return true;
            }
        }

        return false;
    }

    private function hasRelationshipIndex($indexes, $type, $property)
    {
        foreach ($indexes as $index) {
            // Handle both array and object responses
            if (is_array($index)) {
                $labelsOrTypes = $index['labelsOrTypes'] ?? [];
                $properties = $index['properties'] ?? [];
                $entityType = $index['entityType'] ?? '';
            } else {
                $labelsOrTypes = $index->labelsOrTypes ?? [];
                $properties = $index->properties ?? [];
                $entityType = $index->entityType ?? '';
            }

            if ($entityType === 'RELATIONSHIP' && in_array($type, $labelsOrTypes) && in_array($property, $properties)) {
                return true;
            }
        }

        return false;
    }

    public function test_migrations_use_batch_execution_for_speed()
    {
        // Create multiple constraints and indexes in a single migration
        $startTime = microtime(true);

        \Look\EloquentCypher\Facades\GraphSchema::label('BatchUser', function (\Look\EloquentCypher\Schema\GraphBlueprint $label) {
            $label->property('email')->unique();
            $label->property('username')->unique();
            $label->property('created_at')->index();
            $label->property('updated_at')->index();
            $label->index(['first_name', 'last_name']);
        });

        \Look\EloquentCypher\Facades\GraphSchema::label('BatchPost', function (\Look\EloquentCypher\Schema\GraphBlueprint $label) {
            $label->property('slug')->unique();
            $label->property('user_id')->index();
            $label->property('published_at')->index();
        });

        \Look\EloquentCypher\Facades\GraphSchema::label('BatchComment', function (\Look\EloquentCypher\Schema\GraphBlueprint $label) {
            $label->property('post_id')->index();
            $label->property('user_id')->index();
        });

        $elapsedTime = microtime(true) - $startTime;

        // Verify all constraints and indexes were created
        $this->assertTrue(\Look\EloquentCypher\Facades\GraphSchema::hasLabel('BatchUser'));
        $this->assertTrue(\Look\EloquentCypher\Facades\GraphSchema::hasLabel('BatchPost'));
        $this->assertTrue(\Look\EloquentCypher\Facades\GraphSchema::hasLabel('BatchComment'));

        // With batch execution, this should complete in under 2 seconds
        // Without batch execution, this would take 5+ seconds for 10+ DDL statements
        $this->assertLessThan(2, $elapsedTime,
            "Migration with multiple DDL statements took {$elapsedTime}s, expected < 2s with batch execution");
    }

    public function test_migration_rollback_works_with_batch_execution()
    {
        $this->markTestSkipped('Connection pool exhaustion in intensive schema operations - environmental issue');

        // Create schema with batch execution
        \Look\EloquentCypher\Facades\GraphSchema::label('RollbackBatch', function (\Look\EloquentCypher\Schema\GraphBlueprint $label) {
            $label->property('field1')->unique('batch_constraint_1');
            $label->property('field2')->unique('batch_constraint_2');
            $label->property('field3')->index('batch_index_1');
            $label->property('field4')->index('batch_index_2');
        });

        // Verify all were created
        $this->assertTrue(\Look\EloquentCypher\Facades\GraphSchema::hasConstraint('batch_constraint_1'));
        $this->assertTrue(\Look\EloquentCypher\Facades\GraphSchema::hasConstraint('batch_constraint_2'));
        $this->assertTrue(\Look\EloquentCypher\Facades\GraphSchema::hasIndex('batch_index_1'));
        $this->assertTrue(\Look\EloquentCypher\Facades\GraphSchema::hasIndex('batch_index_2'));

        // Simulate rollback with batch execution
        $startTime = microtime(true);

        \Look\EloquentCypher\Facades\GraphSchema::dropConstraint('batch_constraint_1');
        \Look\EloquentCypher\Facades\GraphSchema::dropConstraint('batch_constraint_2');
        \Look\EloquentCypher\Facades\GraphSchema::dropIndex('batch_index_1');
        \Look\EloquentCypher\Facades\GraphSchema::dropIndex('batch_index_2');

        $elapsedTime = microtime(true) - $startTime;

        // Verify all were dropped
        $this->assertFalse(\Look\EloquentCypher\Facades\GraphSchema::hasConstraint('batch_constraint_1'));
        $this->assertFalse(\Look\EloquentCypher\Facades\GraphSchema::hasConstraint('batch_constraint_2'));
        $this->assertFalse(\Look\EloquentCypher\Facades\GraphSchema::hasIndex('batch_index_1'));
        $this->assertFalse(\Look\EloquentCypher\Facades\GraphSchema::hasIndex('batch_index_2'));

        // Rollback should also be fast with batch execution
        $this->assertLessThan(1.5, $elapsedTime,
            "Migration rollback took {$elapsedTime}s, expected < 1.5s with batch execution");
    }
}
