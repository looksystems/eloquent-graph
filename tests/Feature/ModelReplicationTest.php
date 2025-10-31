<?php

use Tests\Models\Post;
use Tests\Models\Role;
use Tests\Models\User;

// TEST SUITE: Model Replication & Comparison
// Focus: Core replication behavior, model comparison, memory efficiency, edge cases

test('replicate creates independent model instance', function () {
    $original = User::create([
        'name' => 'Original User',
        'email' => 'original@example.com',
        'age' => 30,
    ]);

    $replica = $original->replicate();

    expect($replica)->toBeInstanceOf(User::class);
    expect($replica->exists)->toBeFalse();
    expect($replica->id)->toBeNull();
    expect($replica->name)->toBe('Original User');
    expect($replica->email)->toBe('original@example.com');
    expect($replica->age)->toBe(30);
});

test('replicate excludes primary key by default', function () {
    $user = User::create([
        'name' => 'Test User',
        'email' => 'test@example.com',
    ]);

    $originalId = $user->id;
    $replica = $user->replicate();

    expect($replica->id)->toBeNull();
    expect($originalId)->not->toBeNull();
    expect($replica->exists)->toBeFalse();
});

test('replicate excludes timestamps by default', function () {
    $user = User::create([
        'name' => 'Timestamp Test',
        'email' => 'timestamp@example.com',
    ]);

    $originalCreatedAt = $user->created_at;
    $originalUpdatedAt = $user->updated_at;
    $replica = $user->replicate();

    expect($replica->created_at)->toBeNull();
    expect($replica->updated_at)->toBeNull();
    expect($originalCreatedAt)->not->toBeNull();
    expect($originalUpdatedAt)->not->toBeNull();
});

test('replicate excludes specified attributes', function () {
    $user = User::create([
        'name' => 'Exclusion Test',
        'email' => 'exclusion@example.com',
        'age' => 25,
        'status' => 'active',
        'secret_key' => 'super-secret',
    ]);

    $replica = $user->replicate(['secret_key', 'status']);

    expect($replica->name)->toBe('Exclusion Test');
    expect($replica->email)->toBe('exclusion@example.com');
    expect($replica->age)->toBe(25);
    expect($replica->secret_key)->toBeNull();
    expect($replica->status)->toBeNull();
});

test('replicate preserves cast attributes correctly', function () {
    $user = User::create([
        'name' => 'Cast Test',
        'email' => 'cast@example.com',
        'age' => 30,
        'is_active' => true,
        'score' => 95.5,
        'preferences' => ['theme' => 'dark'],
        'metadata' => ['key' => 'value'],
    ]);

    $replica = $user->replicate();

    expect($replica->age)->toBeInt();
    expect($replica->is_active)->toBeBool();
    expect($replica->score)->toBeFloat();
    expect($replica->preferences)->toBeArray();
    expect($replica->metadata)->toBeArray();
    expect($replica->preferences['theme'])->toBe('dark');
    expect($replica->metadata['key'])->toBe('value');
});

test('replicate and save creates new database record', function () {
    $original = User::create([
        'name' => 'Original',
        'email' => 'original@example.com',
    ]);

    $replica = $original->replicate();
    $replica->email = 'replica@example.com';
    $saved = $replica->save();

    expect($saved)->toBeTrue();
    expect($replica->exists)->toBeTrue();
    expect($replica->id)->not->toBeNull();
    expect($replica->id)->not->toBe($original->id);

    // Verify both exist in database
    expect(User::count())->toBe(2);
    expect(User::where('email', 'original@example.com')->exists())->toBeTrue();
    expect(User::where('email', 'replica@example.com')->exists())->toBeTrue();
});

test('replicateQuietly does not fire model events', function () {
    $eventFired = false;

    User::replicating(function () use (&$eventFired) {
        $eventFired = true;
    });

    $user = User::create([
        'name' => 'Quiet Test',
        'email' => 'quiet@example.com',
    ]);

    $replica = $user->replicateQuietly();

    expect($eventFired)->toBeFalse();
    expect($replica->name)->toBe('Quiet Test');

    // Clean up
    User::flushEventListeners();
});

test('model comparison with is() method', function () {
    $user1 = User::create(['name' => 'User 1', 'email' => 'user1@example.com']);
    $user2 = User::create(['name' => 'User 2', 'email' => 'user2@example.com']);
    $user1Copy = User::find($user1->id);

    expect($user1->is($user1Copy))->toBeTrue();
    expect($user1->is($user2))->toBeFalse();
    expect($user1->isNot($user2))->toBeTrue();
    expect($user1->isNot($user1Copy))->toBeFalse();
});

test('model comparison with different model types', function () {
    $user = User::create(['name' => 'User', 'email' => 'user@example.com']);
    $post = Post::create(['title' => 'Post', 'content' => 'Content', 'user_id' => $user->id]);

    expect($user->is($post))->toBeFalse();
    expect($user->isNot($post))->toBeTrue();
});

test('model comparison with null and non-existent models', function () {
    $user = User::create(['name' => 'User', 'email' => 'user@example.com']);
    $newUser = new User(['name' => 'New User']);

    expect($user->is($newUser))->toBeFalse();
    expect($user->is(null))->toBeFalse();
    expect($newUser->is($user))->toBeFalse();
});

test('replicate preserves loaded relationships', function () {
    $user = User::create(['name' => 'User With Posts', 'email' => 'posts@example.com']);
    $post1 = $user->posts()->create(['title' => 'Post 1', 'content' => 'Content 1']);
    $post2 = $user->posts()->create(['title' => 'Post 2', 'content' => 'Content 2']);

    $userWithPosts = User::with('posts')->find($user->id);
    $replica = $userWithPosts->replicate();

    expect($replica->relationLoaded('posts'))->toBeTrue();
    expect($replica->posts)->toHaveCount(2);
    expect($replica->posts->pluck('title')->toArray())->toContain('Post 1');
    expect($replica->posts->pluck('title')->toArray())->toContain('Post 2');
});

test('deep replication maintains relationship data integrity', function () {
    $user = User::create(['name' => 'Author', 'email' => 'author@example.com']);
    $post1 = $user->posts()->create(['title' => 'First Post', 'content' => 'First Content']);
    $post2 = $user->posts()->create(['title' => 'Second Post', 'content' => 'Second Content']);

    $userWithPosts = User::with('posts')->find($user->id);
    $replica = $userWithPosts->replicate();

    // Verify relationship structure is maintained
    expect($replica->posts)->toHaveCount(2);
    foreach ($replica->posts as $post) {
        expect($post->user_id)->toBe($user->id); // Should still reference original
        expect($post->title)->toContain('Post');
        expect($post->content)->toContain('Content');
    }
});

test('replicate with many-to-many relationships', function () {
    $user = User::create(['name' => 'User With Roles', 'email' => 'roles@example.com']);
    $role1 = Role::create(['name' => 'Admin', 'description' => 'Administrator']);
    $role2 = Role::create(['name' => 'Editor', 'description' => 'Content Editor']);

    $user->roles()->attach([$role1->id, $role2->id]);
    $userWithRoles = User::with('roles')->find($user->id);
    $replica = $userWithRoles->replicate();

    expect($replica->relationLoaded('roles'))->toBeTrue();
    expect($replica->roles)->toHaveCount(2);
    expect($replica->roles->pluck('name')->toArray())->toContain('Admin');
    expect($replica->roles->pluck('name')->toArray())->toContain('Editor');
});

test('memory efficiency with large attribute data', function () {
    $largeData = [];
    for ($i = 0; $i < 1000; $i++) {
        $largeData["key_$i"] = "value_$i".str_repeat('x', 100);
    }

    $user = User::create([
        'name' => 'Large Data User',
        'email' => 'large@example.com',
        'metadata' => $largeData,
    ]);

    $memoryBefore = memory_get_usage();
    $replica = $user->replicate();
    $memoryAfter = memory_get_usage();

    $memoryUsed = ($memoryAfter - $memoryBefore) / 1024 / 1024; // MB

    expect($replica->metadata)->toHaveCount(1000);
    expect($replica->metadata['key_500'])->toContain('value_500');
    expect($memoryUsed)->toBeLessThan(10); // Should use less than 10MB
});

test('replicate handles null values in complex attributes', function () {
    $user = User::create([
        'name' => 'Null Test',
        'email' => 'null@example.com',
        'age' => null,
        'preferences' => null,
        'metadata' => ['key' => null, 'value' => 'exists'],
    ]);

    $replica = $user->replicate();

    expect($replica->name)->toBe('Null Test');
    expect($replica->age)->toBeNull();
    expect($replica->preferences)->toBeNull();
    expect($replica->metadata['key'])->toBeNull();
    expect($replica->metadata['value'])->toBe('exists');
});

test('replicate with custom excluded attributes list', function () {
    $user = User::create([
        'name' => 'Custom Exclude',
        'email' => 'exclude@example.com',
        'age' => 30,
        'status' => 'active',
        'role' => 'admin',
        'salary' => 50000,
    ]);

    $replica = $user->replicate(['status', 'role', 'salary']);

    expect($replica->name)->toBe('Custom Exclude');
    expect($replica->email)->toBe('exclude@example.com');
    expect($replica->age)->toBe(30);
    expect($replica->status)->toBeNull();
    expect($replica->role)->toBeNull();
    expect($replica->salary)->toBeNull();
});

test('replicate fires replicating event with correct data', function () {
    $eventData = null;

    User::replicating(function ($model) use (&$eventData) {
        $eventData = [
            'name' => $model->name,
            'email' => $model->email,
            'class' => get_class($model),
        ];
    });

    $user = User::create(['name' => 'Event Test', 'email' => 'event@example.com']);
    $replica = $user->replicate();

    expect($eventData)->not->toBeNull();
    expect($eventData['name'])->toBe('Event Test');
    expect($eventData['email'])->toBe('event@example.com');
    expect($eventData['class'])->toBe(User::class);

    // Clean up
    User::flushEventListeners();
});

test('multiple sequential replications maintain independence', function () {
    $original = User::create([
        'name' => 'Sequential Test',
        'email' => 'sequential@example.com',
        'age' => 25,
    ]);

    $replica1 = $original->replicate();
    $replica1->name = 'Replica 1';
    $replica1->age = 26;

    $replica2 = $original->replicate();
    $replica2->name = 'Replica 2';
    $replica2->age = 27;

    expect($replica1->name)->toBe('Replica 1');
    expect($replica1->age)->toBe(26);
    expect($replica2->name)->toBe('Replica 2');
    expect($replica2->age)->toBe(27);
    expect($original->name)->toBe('Sequential Test');
    expect($original->age)->toBe(25);
});

test('replicate performance with moderate complexity', function () {
    $user = User::create([
        'name' => 'Performance Test',
        'email' => 'performance@example.com',
        'preferences' => array_fill(0, 100, ['theme' => 'dark']),
        'metadata' => ['data' => str_repeat('x', 1000)],
    ]);

    $startTime = microtime(true);
    $replica = $user->replicate();
    $endTime = microtime(true);

    $executionTime = $endTime - $startTime;

    expect($replica->name)->toBe('Performance Test');
    expect($replica->preferences)->toHaveCount(100);
    expect($executionTime)->toBeLessThan(0.1); // Should complete in under 100ms
});
