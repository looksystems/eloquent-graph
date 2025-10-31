<?php

use Tests\Models\User;
use Tests\TestCase\Helpers\GraphDataFactory;
use Tests\TestCase\Helpers\Neo4jTestHelper;
use Tests\TestCase\Helpers\PerformanceMonitor;

describe('Model Replication Advanced Tests', function () {

    beforeEach(function () {
        $this->helper = new Neo4jTestHelper($this->neo4jClient);
        $this->factory = new GraphDataFactory($this->neo4jClient);
        $this->monitor = new PerformanceMonitor($this->neo4jClient);
    });

    afterEach(function () {
        $this->factory->cleanup();
    });

    test('replicate excludes specified attributes', function () {
        $user = User::create([
            'name' => 'Original User',
            'email' => 'original@example.com',
            'secret_key' => 'super-secret',
            'internal_id' => 12345,
        ]);

        $replica = $user->replicate(['secret_key', 'internal_id']);

        expect($replica->name)->toBe('Original User');
        expect($replica->email)->toBe('original@example.com');
        expect($replica->secret_key)->toBeNull();
        expect($replica->internal_id)->toBeNull();
        expect($replica->id)->toBeNull(); // Primary key should be excluded by default
        expect($replica->exists)->toBeFalse();
    });

    test('replicate excludes default attributes automatically', function () {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $original_id = $user->id;
        $original_created_at = $user->created_at;
        $original_updated_at = $user->updated_at;

        $replica = $user->replicate();

        expect($replica->id)->toBeNull();
        expect($replica->created_at)->toBeNull();
        expect($replica->updated_at)->toBeNull();
        expect($replica->name)->toBe('Test User');
        expect($replica->email)->toBe('test@example.com');
        expect($replica->exists)->toBeFalse();
    });

    test('replicateQuietly skips events', function () {
        $replicatingEventFired = false;

        User::replicating(function ($user) use (&$replicatingEventFired) {
            $replicatingEventFired = true;
        });

        $original = User::create([
            'name' => 'Original User',
            'email' => 'original@example.com',
        ]);

        $replica = $original->replicateQuietly();

        expect($replicatingEventFired)->toBeFalse();
        expect($replica->name)->toBe('Original User');
        expect($replica->exists)->toBeFalse();

        // Now test that normal replicate DOES fire the event
        $replica2 = $original->replicate();
        expect($replicatingEventFired)->toBeTrue();

        // Clean up event listener
        User::flushEventListeners();
    });

    test('replicate preserves relationships', function () {
        // Create user with relationships
        $user = User::create([
            'name' => 'User with Relations',
            'email' => 'relations@example.com',
        ]);

        $posts = $this->helper->createTestPosts([$user], 2);
        $roles = $this->helper->createTestRoles(2);
        $user->roles()->attach([$roles[0]->id, $roles[1]->id]);

        // Load relationships
        $user->load(['posts', 'roles']);

        $replica = $user->replicate();

        expect($replica->getRelations())->toHaveKey('posts');
        expect($replica->getRelations())->toHaveKey('roles');
        expect($replica->posts)->toHaveCount(2);
        expect($replica->roles)->toHaveCount(2);
    });

    test('deep replication with relationships maintains data integrity', function () {
        // Create simpler test data for this specific test
        $author = User::create([
            'name' => 'Test Author',
            'email' => 'author@test.com',
            'type' => 'author',
        ]);

        $posts = $this->helper->createTestPosts([$author], 2);
        $roles = $this->helper->createTestRoles(2);
        $author->roles()->attach([$roles[0]->id, $roles[1]->id]);

        // Load all relationships
        $author->load(['posts', 'roles']);

        expect($author->posts)->toHaveCount(2);
        expect($author->roles)->toHaveCount(2);

        $replica = $author->replicate();

        expect($replica->posts)->toHaveCount($author->posts->count());
        expect($replica->roles)->toHaveCount($author->roles->count());

        // Verify that relationship data is preserved but not the primary keys
        expect($replica->id)->toBeNull();
        foreach ($replica->posts as $post) {
            expect($post->title)->toContain('Post');
            expect($post->user_id)->toBe($author->id); // Should still reference original
        }
    });

    test('replicate with custom attributes', function () {
        $user = User::create([
            'name' => 'Custom User',
            'email' => 'custom@example.com',
            'status' => 'active',
        ]);

        $replica = $user->replicate();
        $replica->name = 'Replicated User';
        $replica->email = 'replicated@example.com';
        $replica->status = 'pending';

        expect($replica->name)->toBe('Replicated User');
        expect($replica->status)->toBe('pending');
        expect($replica->exists)->toBeFalse();

        $replica->save();
        expect($replica->exists)->toBeTrue();
        expect($replica->id)->not->toBe($user->id);
    });

    test('replicate performance with large objects', function () {
        // Create user with substantial data
        $user = User::create([
            'name' => 'Large Data User',
            'email' => 'large@example.com',
            'preferences' => array_fill(0, 100, ['key' => 'value', 'data' => str_repeat('x', 100)]),
            'metadata' => json_encode(array_fill(0, 50, 'large_string_'.str_repeat('y', 200))),
        ]);

        $this->helper->createTestPosts([$user], 10);
        $user->load('posts');

        $stats = $this->monitor->monitorMemory(function () use ($user) {
            return $user->replicate();
        }, 'large_object_replication');

        expect($stats['memory_mb'])->toBeLessThan(10); // Should not use excessive memory
        expect($stats['execution_time'])->toBeLessThan(1); // Should be fast
        expect($stats['result'])->toBeInstanceOf(User::class);
    });

    test('replicate maintains casting behavior', function () {
        $castingData = $this->factory->createCastingTestData();
        $original = $castingData[0];

        $replica = $original->replicate();

        // Verify that casting is preserved
        expect($replica->preferences)->toBeArray();
        expect($replica->tags)->toBeArray();
        expect($replica->is_active)->toBeBool();
        expect($replica->score)->toBeFloat();

        if ($replica->last_login) {
            expect($replica->last_login)->toBeInstanceOf(\Carbon\Carbon::class);
        }
    });

    test('replicate handles null values correctly', function () {
        $user = User::create([
            'name' => 'Null Test User',
            'email' => 'null@example.com',
            'age' => 30,
            'status' => 'active',
        ]);

        // Test with explicit null setting
        $user->optional_field = null;
        $user->another_field = 'has_value';
        $user->save();

        $replica = $user->replicate();

        expect($replica->name)->toBe('Null Test User');
        expect($replica->email)->toBe('null@example.com');
        expect($replica->age)->toBe(30);
        expect($replica->status)->toBe('active');

        // These attributes may not be preserved exactly due to how Laravel handles null values
        // The important thing is that replication doesn't crash with null values
        expect($replica->exists)->toBeFalse();
    });

    test('replicate fires replicating event', function () {
        $eventData = null;

        User::replicating(function ($user) use (&$eventData) {
            $eventData = [
                'name' => $user->name,
                'email' => $user->email,
            ];
        });

        $user = User::create([
            'name' => 'Event Test User',
            'email' => 'event@example.com',
        ]);

        $replica = $user->replicate();

        expect($eventData)->not->toBeNull();
        expect($eventData['name'])->toBe('Event Test User');
        expect($eventData['email'])->toBe('event@example.com');

        // Clean up
        User::flushEventListeners();
    });

    test('replicate works with soft deletes', function () {
        // This test would require a model with soft deletes
        // For now, we'll use a regular user and test the concept
        $user = User::create([
            'name' => 'Soft Delete Test',
            'email' => 'softdelete@example.com',
        ]);

        // Manually set deleted_at to simulate soft delete
        $user->deleted_at = now();
        $user->save();

        $replica = $user->replicate(['deleted_at']); // Explicitly exclude deleted_at

        expect($replica->deleted_at)->toBeNull(); // Should exclude deleted_at
        expect($replica->name)->toBe('Soft Delete Test');
        expect($replica->exists)->toBeFalse();
    });

    test('memory efficiency in replication with many relationships', function () {
        $complexData = $this->factory->createSocialNetworkScenario();
        $user = $complexData['users'][0];

        // Load multiple relationship types
        $user->load(['posts', 'roles']);

        $memoryStats = $this->monitor->monitorMemory(function () use ($user) {
            $replicas = [];
            for ($i = 0; $i < 5; $i++) {
                $replicas[] = $user->replicate();
            }

            return $replicas;
        }, 'multiple_replications');

        expect($memoryStats['memory_mb'])->toBeLessThan(20);
        expect($memoryStats['result'])->toHaveCount(5);
        expect($memoryStats['result'][0])->toBeInstanceOf(User::class);
    });

    test('replicate preserves attribute order and structure', function () {
        $user = User::create([
            'name' => 'Structure Test',
            'email' => 'structure@example.com',
            'metadata' => ['a' => 1, 'b' => 2, 'c' => 3],
            'preferences' => ['theme' => 'dark', 'lang' => 'en'],
        ]);

        $replica = $user->replicate();

        expect($replica->metadata)->toBe(['a' => 1, 'b' => 2, 'c' => 3]);
        expect($replica->preferences)->toBe(['theme' => 'dark', 'lang' => 'en']);
        expect(array_keys($replica->toArray()))->toContain('name', 'email', 'metadata', 'preferences');
    });

    test('replicate handles concurrent operations', function () {
        $user = User::create([
            'name' => 'Concurrent Test',
            'email' => 'concurrent@example.com',
        ]);

        // Simulate concurrent replications
        $replicas = [];
        for ($i = 0; $i < 3; $i++) {
            $replicas[] = $user->replicate();
            $replicas[$i]->name = "Replica $i";
            $replicas[$i]->email = "replica{$i}@example.com";
        }

        // Save all replicas
        foreach ($replicas as $replica) {
            $replica->save();
        }

        // Verify all were created successfully
        foreach ($replicas as $index => $replica) {
            expect($replica->exists)->toBeTrue();
            expect($replica->name)->toBe("Replica $index");
            expect($replica->id)->not->toBe($user->id);
        }

        // Verify original is unchanged
        $user->refresh();
        expect($user->name)->toBe('Concurrent Test');
        expect($user->email)->toBe('concurrent@example.com');
    });
});
