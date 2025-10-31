<?php

use Illuminate\Support\Facades\DB;
use Tests\Models\MultiLabelUser;
use Tests\Models\User;

beforeEach(function () {
    // Clean database before each test
    DB::connection('graph')->statement('MATCH (n) DETACH DELETE n');
});

test('creates node with single label by default', function () {
    $user = User::create(['name' => 'John']);

    // Verify single label exists
    $result = DB::connection('graph')->select('
        MATCH (n:users {id: $id})
        RETURN labels(n) as labels
    ', ['id' => $user->id]);

    expect($result)->toHaveCount(1)
        ->and($result[0]->labels ?? $result[0]['labels'])->toBe(['users']);
});

test('creates node with multiple labels when specified', function () {
    $user = MultiLabelUser::create(['name' => 'Jane']);

    $result = DB::connection('graph')->select('
        MATCH (n {id: $id})
        RETURN labels(n) as labels
    ', ['id' => $user->id]);

    $labels = $result[0]->labels ?? $result[0]['labels'];

    expect($labels)->toContain('users')
        ->and($labels)->toContain('Person')
        ->and($labels)->toContain('Individual')
        ->and($labels)->toHaveCount(3);
});

test('queries match on all labels', function () {
    // Create a multi-label user
    $user = MultiLabelUser::create(['name' => 'John']);

    // Query should find the node using all labels
    $found = MultiLabelUser::where('name', 'John')->first();

    expect($found)->not->toBeNull()
        ->and($found->id)->toBe($user->id)
        ->and($found->name)->toBe('John');

    // Verify in database that all labels exist
    $result = DB::connection('graph')->select('
        MATCH (n {id: $id})
        RETURN labels(n) as labels
    ', ['id' => $user->id]);

    $labels = $result[0]->labels ?? $result[0]['labels'];
    expect($labels)->toContain('users')
        ->and($labels)->toContain('Person')
        ->and($labels)->toContain('Individual');
});

test('can query with specific label subset', function () {
    $user = MultiLabelUser::create(['name' => 'John']);

    // This tests the scopeWithLabels functionality
    $users = MultiLabelUser::withLabels(['users', 'Person'])->get();

    expect($users)->toHaveCount(1)
        ->and($users->first()->name)->toBe('John');
});

test('can check if model has specific label', function () {
    $user = MultiLabelUser::create(['name' => 'John']);

    expect($user->hasLabel('users'))->toBeTrue()
        ->and($user->hasLabel('Person'))->toBeTrue()
        ->and($user->hasLabel('Individual'))->toBeTrue()
        ->and($user->hasLabel('Admin'))->toBeFalse();
});

test('get labels returns all labels', function () {
    $user = MultiLabelUser::create(['name' => 'John']);

    $labels = $user->getLabels();

    expect($labels)->toContain('users')
        ->and($labels)->toContain('Person')
        ->and($labels)->toContain('Individual')
        ->and($labels)->toHaveCount(3);
});

test('updates preserve all labels', function () {
    $user = MultiLabelUser::create(['name' => 'John']);
    $user->update(['name' => 'Jane']);

    $result = DB::connection('graph')->select('
        MATCH (n {id: $id})
        RETURN labels(n) as labels
    ', ['id' => $user->id]);

    $labels = $result[0]->labels ?? $result[0]['labels'];

    expect($labels)->toHaveCount(3)
        ->and($labels)->toContain('users')
        ->and($labels)->toContain('Person')
        ->and($labels)->toContain('Individual');
});

test('deletes work with multi label nodes', function () {
    $user = MultiLabelUser::create(['name' => 'John']);
    $id = $user->id;

    $user->delete();

    expect(MultiLabelUser::find($id))->toBeNull();
});

test('relationships work with multi label nodes', function () {
    $user = MultiLabelUser::create(['name' => 'John']);
    $post = $user->posts()->create(['title' => 'Test Post', 'body' => 'Content']);

    expect($user->posts)->toHaveCount(1)
        ->and($user->posts->first()->title)->toBe('Test Post');
});

test('eager loading works with multi label nodes', function () {
    $user = MultiLabelUser::create(['name' => 'John']);
    $user->posts()->create(['title' => 'Post 1', 'body' => 'Content 1']);
    $user->posts()->create(['title' => 'Post 2', 'body' => 'Content 2']);

    $users = MultiLabelUser::with('posts')->get();

    expect($users->first()->posts)->toHaveCount(2);
});

test('where has works with multi label nodes', function () {
    $user1 = MultiLabelUser::create(['name' => 'John']);
    $user1->posts()->create(['title' => 'Post 1', 'body' => 'Content']);

    $user2 = MultiLabelUser::create(['name' => 'Jane']);
    // user2 has no posts

    $users = MultiLabelUser::whereHas('posts')->get();

    expect($users)->toHaveCount(1)
        ->and($users->first()->name)->toBe('John');
});

test('query builder methods use multi labels', function () {
    $user1 = MultiLabelUser::create(['name' => 'John', 'age' => 25]);
    $user2 = MultiLabelUser::create(['name' => 'Jane', 'age' => 30]);

    // Query using where clause
    $users = MultiLabelUser::where('age', '>', 20)->get();

    expect($users)->toHaveCount(2);

    // Verify both users have all labels in database
    foreach ([$user1, $user2] as $user) {
        $result = DB::connection('graph')->select('
            MATCH (n {id: $id})
            RETURN labels(n) as labels
        ', ['id' => $user->id]);

        $labels = $result[0]->labels ?? $result[0]['labels'];
        expect($labels)->toContain('users')
            ->and($labels)->toContain('Person')
            ->and($labels)->toContain('Individual');
    }
});

test('native edge relationships work with multi labels', function () {
    // Create a multi-label user with a post
    $user = MultiLabelUser::create(['name' => 'John']);
    $post = $user->posts()->create(['title' => 'Test', 'body' => 'Content']);

    // Verify the relationship works
    $foundUser = MultiLabelUser::find($user->id);
    expect($foundUser->posts)->toHaveCount(1);

    // Refresh and verify
    $user->refresh();
    expect($user->posts()->count())->toBe(1);
});
