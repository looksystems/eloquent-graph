<?php

namespace Tests\Feature;

use Look\EloquentCypher\Commands\CheckCompatibilityCommand;
use Look\EloquentCypher\Commands\MigrateToEdgesCommand;
use Look\EloquentCypher\Services\CompatibilityChecker;
use Tests\Models\NativeAuthor;
use Tests\Models\NativeBook;
use Tests\Models\NativePost;
use Tests\Models\NativeUser;
use Tests\Models\Post;
use Tests\Models\User;
use Tests\TestCase\GraphTestCase;

class MigrationToolsTest extends GraphTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->clearNeo4jDatabase();
    }

    public function test_edge_manager_can_create_edges_between_nodes()
    {
        $user = NativeUser::create(['name' => 'John', 'email' => 'john@example.com']);
        $post = NativePost::create(['title' => 'Test Post', 'content' => 'Content']);

        $manager = new \Look\EloquentCypher\Services\EdgeManager($user->getConnection());
        $edge = $manager->createEdge($user, $post, 'HAS_POST', ['created_at' => now()->toISOString()]);

        $this->assertNotNull($edge);
        $this->assertArrayHasKey('id', $edge);

        // Verify edge exists
        $cypher = 'MATCH (u:users {id: $userId})-[r:HAS_POST]->(p:posts {id: $postId}) RETURN r';
        $result = $user->getConnection()->select($cypher, [
            'userId' => $user->id,
            'postId' => $post->id,
        ]);

        $this->assertCount(1, $result);
        $this->assertNotNull($result[0]['r']['created_at'] ?? $result[0]->r->created_at);
    }

    public function test_edge_manager_can_update_edge_properties()
    {
        $user = NativeUser::create(['name' => 'John', 'email' => 'john@example.com']);
        $post = NativePost::create(['title' => 'Test Post', 'content' => 'Content']);

        $manager = new \Look\EloquentCypher\Services\EdgeManager($user->getConnection());
        $edge = $manager->createEdge($user, $post, 'HAS_POST', ['status' => 'draft']);

        // Update edge properties
        $manager->updateEdgeProperties($edge['id'] ?? $edge->id, ['status' => 'published']);

        // Verify properties updated
        $cypher = 'MATCH (u:users {id: $userId})-[r:HAS_POST]->(p:posts {id: $postId}) RETURN r';
        $result = $user->getConnection()->select($cypher, [
            'userId' => $user->id,
            'postId' => $post->id,
        ]);

        $this->assertEquals('published', $result[0]['r']['status'] ?? $result[0]->r->status);
    }

    public function test_edge_manager_can_delete_edges()
    {
        $user = NativeUser::create(['name' => 'John', 'email' => 'john@example.com']);
        $post = NativePost::create(['title' => 'Test Post', 'content' => 'Content']);

        $manager = new \Look\EloquentCypher\Services\EdgeManager($user->getConnection());
        $edge = $manager->createEdge($user, $post, 'HAS_POST');

        // Delete edge
        $manager->deleteEdge($edge['id'] ?? $edge->id);

        // Verify edge deleted
        $cypher = 'MATCH (u:users {id: $userId})-[r:HAS_POST]->(p:posts {id: $postId}) RETURN r';
        $result = $user->getConnection()->select($cypher, [
            'userId' => $user->id,
            'postId' => $post->id,
        ]);

        $this->assertEmpty($result);
    }

    public function test_edge_manager_can_find_edges_between_nodes()
    {
        $user = NativeUser::create(['name' => 'John', 'email' => 'john@example.com']);
        $post = NativePost::create(['title' => 'Test Post', 'content' => 'Content']);

        $manager = new \Look\EloquentCypher\Services\EdgeManager($user->getConnection());
        $manager->createEdge($user, $post, 'HAS_POST');
        $manager->createEdge($user, $post, 'LIKES');

        // Find all edges
        $edges = $manager->findEdgesBetween($user, $post);
        $this->assertCount(2, $edges);

        // Find specific edge type
        $hasPostEdges = $manager->findEdgesBetween($user, $post, 'HAS_POST');
        $this->assertCount(1, $hasPostEdges);
    }

    public function test_compatibility_checker_identifies_models_requiring_foreign_keys()
    {
        $user = new User;
        $post = new Post;

        // Create a relationship that uses foreign keys
        $user->id = 1;
        $post->user_id = 1;
        $post->save();

        $checker = new CompatibilityChecker;

        // Check if the model requires foreign keys
        $requiresForeignKeys = $checker->requiresForeignKeys($post, 'user');

        // Regular models without $useNativeRelationships should require foreign keys
        $this->assertTrue($requiresForeignKeys);
    }

    public function test_compatibility_checker_suggests_hybrid_strategy_for_models_with_foreign_key_dependencies()
    {
        $user = new User;
        $post = new Post;

        $user->id = 1;
        $post->user_id = 1;
        $post->save();

        $checker = new CompatibilityChecker;
        $strategy = $checker->suggestMigrationStrategy($post, 'user');

        // Should suggest hybrid to maintain compatibility
        $this->assertEquals('hybrid', $strategy);
    }

    public function test_compatibility_checker_suggests_edge_strategy_for_native_models()
    {
        $user = new NativeUser;
        $post = new NativePost;

        $checker = new CompatibilityChecker;
        $strategy = $checker->suggestMigrationStrategy($post, 'user');

        // Native models can use edge-only strategy
        $this->assertEquals('edge', $strategy);
    }

    public function test_migration_command_creates_edges_from_existing_foreign_key_relationships()
    {
        // Create models with foreign key relationships
        $user = User::create(['name' => 'John', 'email' => 'john@example.com']);
        $post1 = Post::create(['title' => 'Post 1', 'content' => 'Content 1', 'user_id' => $user->id]);
        $post2 = Post::create(['title' => 'Post 2', 'content' => 'Content 2', 'user_id' => $user->id]);

        // Verify no edges exist initially
        $cypher = 'MATCH (u:users)-[r:HAS_POSTS]->(p:posts) WHERE u.id = $userId RETURN r';
        $result = $user->getConnection()->select($cypher, ['userId' => $user->id]);
        $this->assertEmpty($result);

        // Run migration command
        $command = new MigrateToEdgesCommand;
        $command->setLaravel(app());

        // Simulate command execution with specific model
        $command->migrateModel(User::class, 'hybrid');

        // Verify edges created
        $result = $user->getConnection()->select($cypher, ['userId' => $user->id]);
        $this->assertCount(2, $result);

        // Verify foreign keys still exist in hybrid mode
        $this->assertEquals($user->id, Post::find($post1->id)->user_id);
        $this->assertEquals($user->id, Post::find($post2->id)->user_id);
    }

    public function test_migration_command_can_remove_foreign_keys_in_edge_only_mode()
    {
        // Create models with foreign key relationships
        $user = User::create(['name' => 'John', 'email' => 'john@example.com']);
        $post = Post::create(['title' => 'Post', 'content' => 'Content', 'user_id' => $user->id]);

        // Run migration in edge-only mode
        $command = new MigrateToEdgesCommand;
        $command->setLaravel(app());
        $command->migrateModel(User::class, 'edge', true); // removeForeignKeys = true

        // Verify edge created
        $cypher = 'MATCH (u:users {id: $userId})-[r:HAS_POSTS]->(p:posts {id: $postId}) RETURN r';
        $result = $user->getConnection()->select($cypher, [
            'userId' => $user->id,
            'postId' => $post->id,
        ]);
        $this->assertCount(1, $result);

        // Verify foreign key removed
        $freshPost = $post->getConnection()->select(
            'MATCH (p:posts {id: $postId}) RETURN p',
            ['postId' => $post->id]
        )[0];

        $postData = $freshPost['p'] ?? $freshPost->p;
        $this->assertNull($postData['user_id'] ?? $postData->user_id ?? null);
    }

    public function test_migration_command_handles_many_to_many_relationships()
    {
        // Create authors and books with many-to-many relationship
        $author = NativeAuthor::create(['name' => 'Jane Doe']);
        $book1 = NativeBook::create(['title' => 'Book 1']);
        $book2 = NativeBook::create(['title' => 'Book 2']);

        // Create relationships using foreign keys (simulate existing data)
        $connection = $author->getConnection();
        $connection->statement(
            'CREATE (a:author_book {author_id: $authorId, book_id: $bookId, role: $role})',
            ['authorId' => $author->id, 'bookId' => $book1->id, 'role' => 'primary']
        );
        $connection->statement(
            'CREATE (a:author_book {author_id: $authorId, book_id: $bookId, role: $role})',
            ['authorId' => $author->id, 'bookId' => $book2->id, 'role' => 'contributor']
        );

        // Run migration
        $command = new MigrateToEdgesCommand;
        $command->setLaravel(app());
        $command->migrateManyToMany(NativeAuthor::class, 'books', 'hybrid');

        // Verify edges created with pivot data
        $cypher = 'MATCH (a:native_authors {id: $authorId})-[r:NATIVE_AUTHORS_NATIVE_BOOKS]->(b:native_books) RETURN r, b';
        $result = $connection->select($cypher, ['authorId' => $author->id]);

        $this->assertCount(2, $result);

        // Check pivot data transferred
        $roles = array_map(fn ($r) => $r['r']['role'] ?? $r->r->role, $result);
        $this->assertContains('primary', $roles);
        $this->assertContains('contributor', $roles);
    }

    public function test_compatibility_checker_command_outputs_compatibility_report()
    {
        // This test would require mocking console output
        // For now, we'll just test that the command can be instantiated
        $command = new CheckCompatibilityCommand;
        $this->assertInstanceOf(CheckCompatibilityCommand::class, $command);
    }
}
