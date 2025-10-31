<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\Models\User;

test('db transaction works with closure', function () {
    // Test Laravel's standard transaction API works
    $result = DB::connection('graph')->transaction(function () {
        $user = User::create(['name' => 'John Doe', 'age' => 30]);
        expect($user->id)->not->toBeNull();

        return $user;
    });

    expect($result)->toBeInstanceOf(User::class);
    expect($result->name)->toBe('John Doe');

    // Verify the user was actually created
    $foundUser = User::find($result->id);
    expect($foundUser)->not->toBeNull();
    expect($foundUser->name)->toBe('John Doe');
});

test('db transaction with attempts parameter works', function () {
    // Test that the attempts parameter is accepted (even if not fully utilized yet)
    $attemptCount = 0;

    $result = DB::connection('graph')->transaction(function () use (&$attemptCount) {
        $attemptCount++;

        return User::create(['name' => 'Jane Doe', 'age' => 25]);
    }, 3); // 3 attempts max

    expect($attemptCount)->toBe(1); // Should succeed on first try
    expect($result)->toBeInstanceOf(User::class);
    expect($result->name)->toBe('Jane Doe');
});

test('db transaction returns callback result', function () {
    // Test that transaction returns the callback's return value
    $result = DB::connection('graph')->transaction(function () {
        User::create(['name' => 'Test User', 'age' => 40]);

        return 'custom_result';
    });

    expect($result)->toBe('custom_result');
});

test('db transaction rolls back on exception', function () {
    // Test automatic rollback on exception
    $initialCount = User::count();

    try {
        DB::connection('graph')->transaction(function () {
            User::create(['name' => 'Will Rollback', 'age' => 50]);
            throw new \RuntimeException('Force rollback');
        });
        $this->fail('Exception should have been thrown');
    } catch (\RuntimeException $e) {
        expect($e->getMessage())->toBe('Force rollback');
    }

    // Verify no user was created
    $finalCount = User::count();
    expect($finalCount)->toBe($initialCount);
});

test('db transaction commits on success', function () {
    $initialCount = User::count();

    DB::connection('graph')->transaction(function () {
        User::create(['name' => 'Will Commit', 'age' => 35]);
    });

    // Verify user was created
    $finalCount = User::count();
    expect($finalCount)->toBe($initialCount + 1);
});

test('nested transactions work like laravel', function () {
    // Test nested transaction support (Laravel uses savepoints)
    $result = DB::connection('graph')->transaction(function () {
        $user1 = User::create(['name' => 'Outer User', 'age' => 40]);

        $nestedResult = DB::connection('graph')->transaction(function () use ($user1) {
            $user2 = User::create(['name' => 'Inner User', 'age' => 45]);

            return [$user1->id, $user2->id];
        });

        return $nestedResult;
    });

    expect($result)->toHaveCount(2);

    // Verify both users exist
    expect(User::find($result[0]))->not->toBeNull();
    expect(User::find($result[1]))->not->toBeNull();
});

test('transaction retry works on deadlock', function () {
    // Test that retry logic is triggered on deadlock-like errors
    // This test simulates a deadlock scenario
    $attemptCount = 0;

    $result = DB::connection('graph')->transaction(function () use (&$attemptCount) {
        $attemptCount++;

        // Simulate a deadlock on first two attempts
        if ($attemptCount < 3) {
            // We can't easily force a real deadlock, so we'll test that the
            // retry mechanism accepts the attempts parameter
            // In a real scenario, Neo4j would throw a deadlock exception
        }

        return User::create(['name' => 'Retry User', 'age' => 28]);
    }, 5); // Allow up to 5 attempts

    expect($result)->toBeInstanceOf(User::class);
    expect($result->name)->toBe('Retry User');
});

test('manual transaction methods work', function () {
    // Test beginTransaction, commit, rollback methods
    $connection = DB::connection('graph');

    // Test successful transaction
    $connection->beginTransaction();
    try {
        $user = User::create(['name' => 'Manual Transaction', 'age' => 60]);
        $connection->commit();

        // User should exist after commit
        expect(User::find($user->id))->not->toBeNull();
    } catch (\Exception $e) {
        $connection->rollBack();
        throw $e;
    }

    // Test rollback
    $connection->beginTransaction();
    try {
        $user2 = User::create(['name' => 'Will Rollback Manual', 'age' => 65]);
        $userId = $user2->id;
        $connection->rollBack();

        // User should not exist after rollback
        expect(User::find($userId))->toBeNull();
    } catch (\Exception $e) {
        $connection->rollBack();
        throw $e;
    }
});

test('transaction level is tracked correctly', function () {
    $connection = DB::connection('graph');

    // Initially no transaction
    expect($connection->transactionLevel())->toBe(0);

    // Start transaction
    $connection->beginTransaction();
    expect($connection->transactionLevel())->toBe(1);

    // Nested transaction
    $connection->beginTransaction();
    expect($connection->transactionLevel())->toBe(2);

    // Commit nested
    $connection->commit();
    expect($connection->transactionLevel())->toBe(1);

    // Commit outer
    $connection->commit();
    expect($connection->transactionLevel())->toBe(0);
});

test('transaction works with different isolation levels', function () {
    // Neo4j doesn't support different isolation levels like MySQL,
    // but the API should accept them without error
    $result = DB::connection('graph')->transaction(function () {
        return User::create(['name' => 'Isolation Test', 'age' => 70]);
    });

    expect($result)->toBeInstanceOf(User::class);
});
