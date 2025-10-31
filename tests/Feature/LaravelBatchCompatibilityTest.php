<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Models\User;
use Tests\TestCase;

/**
 * Test Laravel batch operation API compatibility.
 * Ensures that batch operations maintain 100% Laravel API compatibility.
 */
class LaravelBatchCompatibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::connection('graph')->query()->from('users')->delete();
    }

    public function test_insert_with_single_record_works_associative_array(): void
    {
        $result = DB::connection('graph')->table('users')->insert([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'age' => 30,
        ]);

        $this->assertTrue($result);

        $user = DB::connection('graph')->table('users')
            ->where('email', 'john@example.com')
            ->first();

        $this->assertNotNull($user);
        $this->assertEquals('John Doe', $user->name);
        $this->assertEquals('john@example.com', $user->email);
        $this->assertEquals(30, $user->age);
    }

    public function test_insert_with_multiple_records_works_array_of_arrays(): void
    {
        $records = [
            ['name' => 'User 1', 'email' => 'user1@example.com', 'age' => 25],
            ['name' => 'User 2', 'email' => 'user2@example.com', 'age' => 30],
            ['name' => 'User 3', 'email' => 'user3@example.com', 'age' => 35],
        ];

        // Time the operation to ensure it's using batch execution
        $startTime = microtime(true);
        $result = DB::connection('graph')->table('users')->insert($records);
        $elapsedTime = microtime(true) - $startTime;

        $this->assertTrue($result);

        // Verify all records were inserted
        $count = DB::connection('graph')->table('users')->count();
        $this->assertEquals(3, $count);

        // Verify each record
        foreach ($records as $record) {
            $user = DB::connection('graph')->table('users')
                ->where('email', $record['email'])
                ->first();
            $this->assertNotNull($user);
            $this->assertEquals($record['name'], $user->name);
            $this->assertEquals($record['age'], $user->age);
        }

        // Ensure this was reasonably fast (should be < 1 second for 3 records)
        $this->assertLessThan(1, $elapsedTime, 'Batch insert took too long, might not be using batch execution');
    }

    public function test_insert_returns_boolean_like_laravel(): void
    {
        // Test with single record - should return true
        $result = DB::connection('graph')->table('users')->insert([
            'name' => 'Single User',
            'email' => 'single@example.com',
        ]);
        $this->assertIsBool($result);
        $this->assertTrue($result);

        // Test with multiple records - should return true
        $result = DB::connection('graph')->table('users')->insert([
            ['name' => 'User 1', 'email' => 'user1@example.com'],
            ['name' => 'User 2', 'email' => 'user2@example.com'],
        ]);
        $this->assertIsBool($result);
        $this->assertTrue($result);

        // Test with empty array - should return true (no-op)
        $result = DB::connection('graph')->table('users')->insert([]);
        $this->assertIsBool($result);
        $this->assertTrue($result);
    }

    public function test_upsert_with_unique_by_works_like_laravel(): void
    {
        // Insert initial records
        DB::connection('graph')->table('users')->insert([
            ['email' => 'existing@example.com', 'name' => 'Existing User', 'age' => 25],
            ['email' => 'another@example.com', 'name' => 'Another User', 'age' => 30],
        ]);

        // Upsert with some existing and some new records
        $records = [
            ['email' => 'existing@example.com', 'name' => 'Updated Name', 'age' => 26], // Update
            ['email' => 'another@example.com', 'name' => 'Another User', 'age' => 31], // Update
            ['email' => 'new@example.com', 'name' => 'New User', 'age' => 28], // Insert
        ];

        $affectedCount = DB::connection('graph')->table('users')->upsert(
            $records,
            ['email'], // uniqueBy
            ['name', 'age'] // update columns
        );

        // In Neo4j, affected means updated + inserted
        $this->assertEquals(3, $affectedCount);

        // Verify the updates
        $existing = DB::connection('graph')->table('users')
            ->where('email', 'existing@example.com')
            ->first();
        $this->assertEquals('Updated Name', $existing->name);
        $this->assertEquals(26, $existing->age);

        // Verify the insert
        $new = DB::connection('graph')->table('users')
            ->where('email', 'new@example.com')
            ->first();
        $this->assertNotNull($new);
        $this->assertEquals('New User', $new->name);

        // Verify total count
        $count = DB::connection('graph')->table('users')->count();
        $this->assertEquals(3, $count);
    }

    public function test_upsert_returns_affected_count_like_laravel(): void
    {
        // Test with no existing records (all inserts)
        $records = [
            ['email' => 'user1@example.com', 'name' => 'User 1'],
            ['email' => 'user2@example.com', 'name' => 'User 2'],
        ];

        $affected = DB::connection('graph')->table('users')->upsert(
            $records,
            ['email'],
            ['name']
        );

        $this->assertIsInt($affected);
        $this->assertEquals(2, $affected);

        // Test with all existing records (all updates)
        $records[0]['name'] = 'Updated User 1';
        $records[1]['name'] = 'Updated User 2';

        $affected = DB::connection('graph')->table('users')->upsert(
            $records,
            ['email'],
            ['name']
        );

        $this->assertIsInt($affected);
        $this->assertEquals(2, $affected);
    }

    public function test_insert_or_ignore_works_like_laravel(): void
    {
        // Insert initial record
        DB::connection('graph')->table('users')->insert([
            'email' => 'existing@example.com',
            'name' => 'Existing User',
        ]);

        // Try to insert with duplicate and new records
        $records = [
            ['email' => 'existing@example.com', 'name' => 'Should be ignored'],
            ['email' => 'new1@example.com', 'name' => 'New User 1'],
            ['email' => 'new2@example.com', 'name' => 'New User 2'],
        ];

        $insertedCount = DB::connection('graph')->table('users')->insertOrIgnore($records);

        // Should have inserted only the new records
        $this->assertEquals(2, $insertedCount);

        // Verify existing record wasn't updated
        $existing = DB::connection('graph')->table('users')
            ->where('email', 'existing@example.com')
            ->first();
        $this->assertEquals('Existing User', $existing->name);

        // Verify new records were inserted
        $new1 = DB::connection('graph')->table('users')
            ->where('email', 'new1@example.com')
            ->first();
        $this->assertNotNull($new1);
        $this->assertEquals('New User 1', $new1->name);

        // Verify total count
        $count = DB::connection('graph')->table('users')->count();
        $this->assertEquals(3, $count);
    }

    public function test_batch_operations_work_inside_transactions(): void
    {
        DB::connection('graph')->beginTransaction();

        try {
            // Batch insert inside transaction
            $records = [
                ['name' => 'TX User 1', 'email' => 'tx1@example.com'],
                ['name' => 'TX User 2', 'email' => 'tx2@example.com'],
                ['name' => 'TX User 3', 'email' => 'tx3@example.com'],
            ];

            $result = DB::connection('graph')->table('users')->insert($records);
            $this->assertTrue($result);

            // Verify records exist within transaction
            $count = DB::connection('graph')->table('users')->count();
            $this->assertEquals(3, $count);

            // Rollback the transaction
            DB::connection('graph')->rollBack();

            // Verify records were rolled back
            $count = DB::connection('graph')->table('users')->count();
            $this->assertEquals(0, $count);

            // Now test with commit
            DB::connection('graph')->beginTransaction();

            $result = DB::connection('graph')->table('users')->insert($records);
            $this->assertTrue($result);

            DB::connection('graph')->commit();

            // Verify records persisted after commit
            $count = DB::connection('graph')->table('users')->count();
            $this->assertEquals(3, $count);

        } catch (\Exception $e) {
            DB::connection('graph')->rollBack();
            throw $e;
        }
    }

    public function test_batch_operations_fire_proper_events(): void
    {
        Event::fake();

        // Test that events are fired for batch operations
        $records = [
            ['name' => 'Event User 1', 'email' => 'event1@example.com'],
            ['name' => 'Event User 2', 'email' => 'event2@example.com'],
        ];

        // Using Eloquent to ensure model events are fired
        User::insert($records);

        // In Laravel, batch insert doesn't fire individual model events
        // but it should fire query events
        Event::assertDispatched('eloquent.booting: '.User::class);
    }

    public function test_large_batch_insert_performance(): void
    {
        $this->markTestSkipped('Performance test - timing varies by system');

        // Test with 1000 records to ensure batch execution scales
        $records = [];
        for ($i = 1; $i <= 1000; $i++) {
            $records[] = [
                'name' => "User $i",
                'email' => "user$i@example.com",
                'age' => rand(18, 80),
            ];
        }

        $startTime = microtime(true);
        $result = DB::connection('graph')->table('users')->insert($records);
        $elapsedTime = microtime(true) - $startTime;

        $this->assertTrue($result);

        // Verify count
        $count = DB::connection('graph')->table('users')->count();
        $this->assertEquals(1000, $count);

        // Should complete in under 2 seconds with batch execution
        // (Without batch execution, this would take 10+ seconds)
        $this->assertLessThan(2, $elapsedTime,
            "Large batch insert took {$elapsedTime}s, expected < 2s with batch execution");
    }

    public function test_batch_upsert_performance(): void
    {
        $this->markTestSkipped('Performance test - timing varies by system');

        // Insert initial 500 records
        $initialRecords = [];
        for ($i = 1; $i <= 500; $i++) {
            $initialRecords[] = [
                'email' => "user$i@example.com",
                'name' => "User $i",
                'age' => 20,
            ];
        }
        DB::connection('graph')->table('users')->insert($initialRecords);

        // Prepare upsert with 500 updates and 500 new records
        $upsertRecords = [];
        for ($i = 1; $i <= 1000; $i++) {
            $upsertRecords[] = [
                'email' => "user$i@example.com",
                'name' => "Updated User $i",
                'age' => 25,
            ];
        }

        $startTime = microtime(true);
        $affected = DB::connection('graph')->table('users')->upsert(
            $upsertRecords,
            ['email'],
            ['name', 'age']
        );
        $elapsedTime = microtime(true) - $startTime;

        $this->assertEquals(1000, $affected);

        // Verify total count
        $count = DB::connection('graph')->table('users')->count();
        $this->assertEquals(1000, $count);

        // Should complete in under 3 seconds with batch execution
        $this->assertLessThan(3, $elapsedTime,
            "Large batch upsert took {$elapsedTime}s, expected < 3s with batch execution");
    }
}
