<?php

namespace Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

class Neo4jSchemaGrammarTest extends TestCase
{
    private \Look\EloquentCypher\Schema\GraphSchemaGrammar $grammar;

    protected function setUp(): void
    {
        parent::setUp();
        $this->grammar = new \Look\EloquentCypher\Schema\GraphSchemaGrammar;
    }

    /**
     * Test index compilation
     */
    public function test_compile_single_property_index(): void
    {
        $blueprint = $this->createMock(\Look\EloquentCypher\Schema\GraphBlueprint::class);
        $blueprint->method('getCommands')->willReturn([
            [
                'name' => 'index',
                'label' => 'User',
                'properties' => ['email'],
                'indexName' => 'user_email_idx',
                'isRelationship' => false,
            ],
        ]);

        $statements = $this->grammar->compile($blueprint);

        $this->assertCount(1, $statements);
        $this->assertEquals(
            'CREATE INDEX user_email_idx IF NOT EXISTS FOR (n:User) ON (n.email)',
            $statements[0]
        );
    }

    public function test_compile_multi_property_index(): void
    {
        $blueprint = $this->createMock(\Look\EloquentCypher\Schema\GraphBlueprint::class);
        $blueprint->method('getCommands')->willReturn([
            [
                'name' => 'index',
                'label' => 'User',
                'properties' => ['firstName', 'lastName'],
                'indexName' => 'user_name_idx',
                'isRelationship' => false,
            ],
        ]);

        $statements = $this->grammar->compile($blueprint);

        $this->assertCount(1, $statements);
        $this->assertEquals(
            'CREATE INDEX user_name_idx IF NOT EXISTS FOR (n:User) ON (n.firstName, n.lastName)',
            $statements[0]
        );
    }

    public function test_compile_relationship_index(): void
    {
        $blueprint = $this->createMock(\Look\EloquentCypher\Schema\GraphBlueprint::class);
        $blueprint->method('getCommands')->willReturn([
            [
                'name' => 'index',
                'label' => 'FOLLOWS',
                'properties' => ['since'],
                'indexName' => 'follows_since_idx',
                'isRelationship' => true,
            ],
        ]);

        $statements = $this->grammar->compile($blueprint);

        $this->assertCount(1, $statements);
        $this->assertEquals(
            'CREATE INDEX follows_since_idx IF NOT EXISTS FOR ()-[r:FOLLOWS]-() ON (r.since)',
            $statements[0]
        );
    }

    public function test_compile_relationship_multi_property_index(): void
    {
        $blueprint = $this->createMock(\Look\EloquentCypher\Schema\GraphBlueprint::class);
        $blueprint->method('getCommands')->willReturn([
            [
                'name' => 'index',
                'label' => 'RATED',
                'properties' => ['score', 'timestamp'],
                'indexName' => 'rated_composite_idx',
                'isRelationship' => true,
            ],
        ]);

        $statements = $this->grammar->compile($blueprint);

        $this->assertCount(1, $statements);
        $this->assertEquals(
            'CREATE INDEX rated_composite_idx IF NOT EXISTS FOR ()-[r:RATED]-() ON (r.score, r.timestamp)',
            $statements[0]
        );
    }

    /**
     * Test text index compilation
     */
    public function test_compile_text_index(): void
    {
        $blueprint = $this->createMock(\Look\EloquentCypher\Schema\GraphBlueprint::class);
        $blueprint->method('getCommands')->willReturn([
            [
                'name' => 'textIndex',
                'label' => 'Article',
                'properties' => ['content'],
                'indexName' => 'article_content_text',
            ],
        ]);

        $statements = $this->grammar->compile($blueprint);

        $this->assertCount(1, $statements);
        $this->assertEquals(
            'CREATE TEXT INDEX article_content_text IF NOT EXISTS FOR (n:Article) ON (n.content)',
            $statements[0]
        );
    }

    /**
     * Test unique constraint compilation
     */
    public function test_compile_single_property_unique(): void
    {
        $blueprint = $this->createMock(\Look\EloquentCypher\Schema\GraphBlueprint::class);
        $blueprint->method('getCommands')->willReturn([
            [
                'name' => 'unique',
                'label' => 'User',
                'properties' => ['email'],
                'constraintName' => 'user_email_unique',
                'isRelationship' => false,
            ],
        ]);

        $statements = $this->grammar->compile($blueprint);

        $this->assertCount(1, $statements);
        $this->assertEquals(
            'CREATE CONSTRAINT user_email_unique IF NOT EXISTS FOR (n:User) REQUIRE n.email IS UNIQUE',
            $statements[0]
        );
    }

    public function test_compile_multi_property_unique(): void
    {
        $blueprint = $this->createMock(\Look\EloquentCypher\Schema\GraphBlueprint::class);
        $blueprint->method('getCommands')->willReturn([
            [
                'name' => 'unique',
                'label' => 'User',
                'properties' => ['firstName', 'lastName'],
                'constraintName' => 'user_name_unique',
                'isRelationship' => false,
            ],
        ]);

        $statements = $this->grammar->compile($blueprint);

        $this->assertCount(1, $statements);
        $this->assertEquals(
            'CREATE CONSTRAINT user_name_unique IF NOT EXISTS FOR (n:User) REQUIRE (n.firstName, n.lastName) IS UNIQUE',
            $statements[0]
        );
    }

    public function test_compile_relationship_unique_creates_index(): void
    {
        // Relationships don't support unique constraints, so it should create an index instead
        $blueprint = $this->createMock(\Look\EloquentCypher\Schema\GraphBlueprint::class);
        $blueprint->method('getCommands')->willReturn([
            [
                'name' => 'unique',
                'label' => 'FOLLOWS',
                'properties' => ['id'],
                'constraintName' => 'follows_id_unique',
                'isRelationship' => true,
                'indexName' => null,
            ],
        ]);

        $statements = $this->grammar->compile($blueprint);

        $this->assertCount(1, $statements);
        $this->assertStringStartsWith('CREATE INDEX', $statements[0]);
        $this->assertStringContainsString('FOLLOWS', $statements[0]);
    }

    /**
     * Test drop operations
     */
    public function test_compile_drop_index(): void
    {
        $blueprint = $this->createMock(\Look\EloquentCypher\Schema\GraphBlueprint::class);
        $blueprint->method('getCommands')->willReturn([
            [
                'name' => 'dropIndex',
                'indexName' => 'user_email_idx',
            ],
        ]);

        $statements = $this->grammar->compile($blueprint);

        $this->assertCount(1, $statements);
        $this->assertEquals('DROP INDEX user_email_idx IF EXISTS', $statements[0]);
    }

    public function test_compile_drop_constraint(): void
    {
        $blueprint = $this->createMock(\Look\EloquentCypher\Schema\GraphBlueprint::class);
        $blueprint->method('getCommands')->willReturn([
            [
                'name' => 'dropConstraint',
                'constraintName' => 'user_email_unique',
            ],
        ]);

        $statements = $this->grammar->compile($blueprint);

        $this->assertCount(1, $statements);
        $this->assertEquals('DROP CONSTRAINT user_email_unique IF EXISTS', $statements[0]);
    }

    /**
     * Test multiple commands compilation
     */
    public function test_compile_multiple_commands(): void
    {
        $blueprint = $this->createMock(\Look\EloquentCypher\Schema\GraphBlueprint::class);
        $blueprint->method('getCommands')->willReturn([
            [
                'name' => 'index',
                'label' => 'User',
                'properties' => ['email'],
                'indexName' => 'user_email_idx',
                'isRelationship' => false,
            ],
            [
                'name' => 'unique',
                'label' => 'User',
                'properties' => ['username'],
                'constraintName' => 'user_username_unique',
                'isRelationship' => false,
            ],
            [
                'name' => 'textIndex',
                'label' => 'User',
                'properties' => ['bio'],
                'indexName' => 'user_bio_text',
            ],
        ]);

        $statements = $this->grammar->compile($blueprint);

        $this->assertCount(3, $statements);
        $this->assertStringContainsString('CREATE INDEX', $statements[0]);
        $this->assertStringContainsString('CREATE CONSTRAINT', $statements[1]);
        $this->assertStringContainsString('IS UNIQUE', $statements[1]);
        $this->assertStringContainsString('CREATE TEXT INDEX', $statements[2]);
    }

    /**
     * Test index/constraint name generation
     */
    public function test_generate_index_name_when_not_provided(): void
    {
        $blueprint = $this->createMock(\Look\EloquentCypher\Schema\GraphBlueprint::class);
        $blueprint->method('getCommands')->willReturn([
            [
                'name' => 'index',
                'label' => 'User',
                'properties' => ['email'],
                'indexName' => null,
                'isRelationship' => false,
            ],
        ]);

        $statements = $this->grammar->compile($blueprint);

        $this->assertCount(1, $statements);
        // Should generate: user_email_index
        $this->assertStringContainsString('user_email_index', $statements[0]);
    }

    public function test_generate_constraint_name_when_not_provided(): void
    {
        $blueprint = $this->createMock(\Look\EloquentCypher\Schema\GraphBlueprint::class);
        $blueprint->method('getCommands')->willReturn([
            [
                'name' => 'unique',
                'label' => 'User',
                'properties' => ['email'],
                'constraintName' => null,
                'isRelationship' => false,
            ],
        ]);

        $statements = $this->grammar->compile($blueprint);

        $this->assertCount(1, $statements);
        // Should generate: user_email_unique
        $this->assertStringContainsString('user_email_unique', $statements[0]);
    }

    /**
     * Test unknown command handling
     */
    public function test_ignore_unknown_command(): void
    {
        $blueprint = $this->createMock(\Look\EloquentCypher\Schema\GraphBlueprint::class);
        $blueprint->method('getCommands')->willReturn([
            [
                'name' => 'unknownCommand',
                'some' => 'data',
            ],
        ]);

        $statements = $this->grammar->compile($blueprint);

        $this->assertCount(0, $statements);
    }

    /**
     * Test empty blueprint
     */
    public function test_compile_empty_blueprint(): void
    {
        $blueprint = $this->createMock(\Look\EloquentCypher\Schema\GraphBlueprint::class);
        $blueprint->method('getCommands')->willReturn([]);

        $statements = $this->grammar->compile($blueprint);

        $this->assertCount(0, $statements);
    }
}
