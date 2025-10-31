<?php

namespace Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Tests\Models\Comment;
use Tests\Models\Post;
use Tests\Models\Profile;
use Tests\Models\User;
use Tests\TestCase\GraphTestCase;

class ModelEventsTest extends GraphTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Clear any global event listeners between tests
        User::flushEventListeners();
        Post::flushEventListeners();
        Comment::flushEventListeners();
        Profile::flushEventListeners();
    }

    /**
     * Test creating event can cancel model creation with validation
     */
    public function test_creating_event_can_cancel_with_validation()
    {
        $validationErrors = [];

        User::creating(function ($user) use (&$validationErrors) {
            // Validate email format
            if (! filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
                $validationErrors[] = 'Invalid email format';

                return false;
            }

            // Validate name length
            if (strlen($user->name) < 3) {
                $validationErrors[] = 'Name too short';

                return false;
            }

            return true;
        });

        // Test with invalid email
        $user1 = User::create(['name' => 'John Doe', 'email' => 'not-an-email']);
        expect($user1->exists)->toBeFalse();
        expect($validationErrors)->toContain('Invalid email format');

        // Test with short name
        $validationErrors = [];
        $user2 = User::create(['name' => 'Jo', 'email' => 'jo@example.com']);
        expect($user2->exists)->toBeFalse();
        expect($validationErrors)->toContain('Name too short');

        // Test with valid data
        $validationErrors = [];
        $user3 = User::create(['name' => 'John', 'email' => 'john@example.com']);
        expect($user3->exists)->toBeTrue();
        expect($validationErrors)->toBeEmpty();
    }

    /**
     * Test updated event receives dirty attributes correctly
     */
    public function test_updated_event_receives_dirty_attributes()
    {
        $user = User::create(['name' => 'Original Name', 'email' => 'original@example.com']);

        $dirtyAttributes = null;
        $originalAttributes = null;
        $changedAttributes = null;

        User::updating(function ($user) use (&$dirtyAttributes, &$originalAttributes, &$changedAttributes) {
            $dirtyAttributes = $user->getDirty();
            $originalAttributes = $user->getOriginal();
            $changedAttributes = $user->getChanges();
        });

        User::updated(function ($user) use (&$changedAttributes) {
            $changedAttributes = $user->getChanges();
        });

        $user->name = 'New Name';
        $user->save();

        expect($dirtyAttributes)->toHaveKey('name');
        expect($dirtyAttributes['name'])->toBe('New Name');
        expect($originalAttributes['name'])->toBe('Original Name');
        expect($changedAttributes)->toHaveKey('name');
    }

    /**
     * Test deleting event with soft deletes behavior
     */
    public function test_deleting_event_with_soft_deletes()
    {
        $deleteEvents = [];

        // Use PostWithSoftDeletes model for soft delete testing
        $postClass = \Tests\Models\PostWithSoftDeletes::class;

        $postClass::deleting(function ($post) use (&$deleteEvents) {
            $deleteEvents[] = [
                'action' => 'deleting',
                'soft_deleted' => $post->trashed(),
            ];
        });

        $postClass::deleted(function ($post) use (&$deleteEvents) {
            $deleteEvents[] = [
                'action' => 'deleted',
                'soft_deleted' => $post->trashed(),
            ];
        });

        $post = $postClass::create(['title' => 'Test Post', 'content' => 'Test content']);

        // Soft delete
        $post->delete();

        expect($deleteEvents)->toHaveCount(2);
        expect($deleteEvents[0]['action'])->toBe('deleting');
        expect($deleteEvents[0]['soft_deleted'])->toBeFalse();
        expect($deleteEvents[1]['action'])->toBe('deleted');
        expect($deleteEvents[1]['soft_deleted'])->toBeTrue();

        // Force delete
        $deleteEvents = [];
        $post->forceDelete();

        expect($deleteEvents)->toHaveCount(2);
        expect($deleteEvents[0]['action'])->toBe('deleting');
        expect($deleteEvents[1]['action'])->toBe('deleted');
    }

    /**
     * Test event propagation in relationships
     */
    public function test_event_propagation_in_relationships()
    {
        $events = [];

        User::created(function ($user) use (&$events) {
            $events[] = ['model' => 'User', 'event' => 'created', 'id' => $user->id];
        });

        Post::created(function ($post) use (&$events) {
            $events[] = ['model' => 'Post', 'event' => 'created', 'id' => $post->id];
        });

        Comment::created(function ($comment) use (&$events) {
            $events[] = ['model' => 'Comment', 'event' => 'created', 'id' => $comment->id];
        });

        // Create related models
        $user = User::create(['name' => 'Test User', 'email' => 'test@example.com']);
        $post = $user->posts()->create(['title' => 'Test Post', 'content' => 'Content']);
        $comment = $post->comments()->create(['content' => 'Test comment', 'author_name' => 'Author']);

        expect($events)->toHaveCount(3);
        expect($events[0]['model'])->toBe('User');
        expect($events[1]['model'])->toBe('Post');
        expect($events[2]['model'])->toBe('Comment');
    }

    /**
     * Test event performance impact with large datasets
     */
    public function test_event_performance_impact()
    {
        $eventCallCount = 0;

        User::creating(function ($user) use (&$eventCallCount) {
            $eventCallCount++;
            // Simulate some processing
            $hash = md5($user->email);
            $user->email_hash = $hash;
        });

        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        // Create multiple users
        $users = [];
        for ($i = 0; $i < 50; $i++) {
            $users[] = User::create([
                'name' => "User $i",
                'email' => "user$i@example.com",
            ]);
        }

        $endTime = microtime(true);
        $endMemory = memory_get_usage();

        $executionTime = $endTime - $startTime;
        $memoryUsed = ($endMemory - $startMemory) / 1024 / 1024; // Convert to MB

        expect($eventCallCount)->toBe(50);
        expect($executionTime)->toBeLessThan(5); // Should complete within 5 seconds
        expect($memoryUsed)->toBeLessThan(10); // Should use less than 10MB

        // Verify event processing worked
        foreach ($users as $user) {
            expect($user->email_hash)->toBe(md5($user->email));
        }
    }

    /**
     * Test saving and saved events fire correctly
     */
    public function test_saving_and_saved_events_sequence()
    {
        $eventSequence = [];

        User::saving(function ($user) use (&$eventSequence) {
            $eventSequence[] = 'saving';
            expect($user->isDirty())->toBeTrue();
        });

        User::creating(function ($user) use (&$eventSequence) {
            $eventSequence[] = 'creating';
        });

        User::created(function ($user) use (&$eventSequence) {
            $eventSequence[] = 'created';
        });

        User::saved(function ($user) use (&$eventSequence) {
            $eventSequence[] = 'saved';
            expect($user->wasRecentlyCreated)->toBeTrue();
        });

        User::create(['name' => 'Test User', 'email' => 'test@example.com']);

        expect($eventSequence)->toBe(['saving', 'creating', 'created', 'saved']);
    }

    /**
     * Test event listeners can modify attributes during update
     */
    public function test_event_listeners_modify_attributes_during_update()
    {
        $user = User::create(['name' => 'Original', 'email' => 'original@example.com']);

        User::updating(function ($user) {
            // Add timestamp to name
            $user->name = $user->name.'_updated_'.date('Y-m-d');

            // Track original email
            if ($user->isDirty('email')) {
                $user->previous_email = $user->getOriginal('email');
            }
        });

        $user->update(['email' => 'new@example.com']);

        expect($user->name)->toContain('_updated_');
        expect($user->previous_email)->toBe('original@example.com');
    }

    /**
     * Test restoring event for soft deleted models
     */
    public function test_restoring_event_for_soft_deleted_models()
    {
        $restoreEvents = [];

        $userClass = \Tests\Models\UserWithSoftDeletes::class;

        $userClass::restoring(function ($user) use (&$restoreEvents) {
            $restoreEvents[] = 'restoring';
            expect($user->trashed())->toBeTrue();
        });

        $userClass::restored(function ($user) use (&$restoreEvents) {
            $restoreEvents[] = 'restored';
            expect($user->trashed())->toBeFalse();
        });

        $user = $userClass::create(['name' => 'Test User', 'email' => 'test@example.com']);
        $userId = $user->id;

        // Soft delete
        $user->delete();
        expect($user->trashed())->toBeTrue();

        // Restore
        $user->restore();

        expect($restoreEvents)->toBe(['restoring', 'restored']);
        expect($user->trashed())->toBeFalse();

        // Verify in database
        $found = $userClass::find($userId);
        expect($found)->not->toBeNull();
    }

    /**
     * Test forceDeleting event for permanent deletion
     */
    public function test_force_deleting_event()
    {
        $forceDeleteEvents = [];

        $userClass = \Tests\Models\UserWithSoftDeletes::class;

        $userClass::forceDeleting(function ($user) use (&$forceDeleteEvents) {
            $forceDeleteEvents[] = 'forceDeleting';
        });

        $userClass::forceDeleted(function ($user) use (&$forceDeleteEvents) {
            $forceDeleteEvents[] = 'forceDeleted';
        });

        $user = $userClass::create(['name' => 'Test User', 'email' => 'test@example.com']);
        $userId = $user->id;

        // Force delete directly
        $user->forceDelete();

        expect($forceDeleteEvents)->toBe(['forceDeleting', 'forceDeleted']);

        // Verify permanently deleted
        $found = $userClass::withTrashed()->find($userId);
        expect($found)->toBeNull();
    }

    /**
     * Test event exception handling and recovery
     */
    public function test_event_exception_handling()
    {
        $exceptionThrown = false;
        $modelSaved = false;

        User::creating(function ($user) use (&$exceptionThrown) {
            if ($user->email === 'error@example.com') {
                $exceptionThrown = true;
                throw new \Exception('Simulated error in event');
            }
        });

        // Test that exception prevents save
        try {
            $user = User::create(['name' => 'Error User', 'email' => 'error@example.com']);
            $modelSaved = true;
        } catch (\Exception $e) {
            expect($e->getMessage())->toBe('Simulated error in event');
        }

        expect($exceptionThrown)->toBeTrue();
        expect($modelSaved)->toBeFalse();

        // Verify not in database
        $found = User::where('email', 'error@example.com')->first();
        expect($found)->toBeNull();

        // Test that normal saves still work
        $user = User::create(['name' => 'Normal User', 'email' => 'normal@example.com']);
        expect($user->exists)->toBeTrue();
    }

    /**
     * Test touch events on timestamp updates
     */
    public function test_touch_events_on_timestamp_updates()
    {
        $touchEvents = [];

        User::updating(function ($user) use (&$touchEvents) {
            if ($user->isDirty('updated_at') && ! $user->isDirty(['name', 'email'])) {
                $touchEvents[] = 'touch_updating';
            }
        });

        User::updated(function ($user) use (&$touchEvents) {
            if ($user->wasChanged('updated_at') && ! $user->wasChanged(['name', 'email'])) {
                $touchEvents[] = 'touch_updated';
            }
        });

        $user = User::create(['name' => 'Test User', 'email' => 'test@example.com']);
        $originalUpdatedAt = $user->updated_at;

        // Wait a moment to ensure timestamp difference
        sleep(1);

        // Touch the model
        $user->touch();

        expect($touchEvents)->toBe(['touch_updating', 'touch_updated']);
        expect($user->updated_at)->toBeGreaterThan($originalUpdatedAt);
    }

    /**
     * Test event listener priority with stopPropagation
     */
    public function test_event_listener_priority_with_stop_propagation()
    {
        $listenersExecuted = [];

        // First listener (high priority)
        User::creating(function ($user) use (&$listenersExecuted) {
            $listenersExecuted[] = 'listener1';
            if ($user->email === 'stop@example.com') {
                return false; // Stop propagation
            }
        });

        // Second listener
        User::creating(function ($user) use (&$listenersExecuted) {
            $listenersExecuted[] = 'listener2';
        });

        // Third listener
        User::creating(function ($user) use (&$listenersExecuted) {
            $listenersExecuted[] = 'listener3';
        });

        // Test with stop propagation
        $user1 = User::create(['name' => 'Stop User', 'email' => 'stop@example.com']);
        expect($user1->exists)->toBeFalse();
        expect($listenersExecuted)->toBe(['listener1']);

        // Test without stop propagation
        $listenersExecuted = [];
        $user2 = User::create(['name' => 'Normal User', 'email' => 'normal@example.com']);
        expect($user2->exists)->toBeTrue();
        expect($listenersExecuted)->toBe(['listener1', 'listener2', 'listener3']);
    }

    /**
     * Test model events with bulk operations
     */
    public function test_model_events_with_bulk_operations()
    {
        $createCount = 0;

        User::created(function ($user) use (&$createCount) {
            $createCount++;
        });

        // Bulk insert using create
        $users = [];
        for ($i = 0; $i < 10; $i++) {
            $users[] = User::create([
                'name' => "Bulk User $i",
                'email' => "bulk$i@example.com",
            ]);
        }

        expect($createCount)->toBe(10);
        expect(count($users))->toBe(10);

        // Note: Direct bulk insert bypasses events
        // This is expected behavior to maintain performance
    }

    /**
     * Test event data persistence across handlers
     */
    public function test_event_data_persistence_across_handlers()
    {
        $sharedData = [];

        User::creating(function ($user) use (&$sharedData) {
            $sharedData['creating_time'] = microtime(true);
            $user->creation_metadata = json_encode(['step' => 'creating']);
        });

        User::created(function ($user) use (&$sharedData) {
            $sharedData['created_time'] = microtime(true);
            $metadata = json_decode($user->creation_metadata, true);
            $metadata['step'] = 'created';
            $metadata['processing_time'] = $sharedData['created_time'] - $sharedData['creating_time'];
            $user->creation_metadata = json_encode($metadata);
        });

        $user = User::create(['name' => 'Test User', 'email' => 'test@example.com']);

        $metadata = json_decode($user->creation_metadata, true);
        expect($metadata)->toHaveKey('step');
        expect($metadata['step'])->toBe('created');
        expect($metadata)->toHaveKey('processing_time');
        expect($metadata['processing_time'])->toBeGreaterThan(0);
    }

    /**
     * Test clearing all event listeners
     */
    public function test_clearing_all_event_listeners()
    {
        $eventFired = false;

        User::creating(function ($user) use (&$eventFired) {
            $eventFired = true;
        });

        // Clear all listeners
        User::flushEventListeners();

        // Create user - event should not fire
        $user = User::create(['name' => 'Test User', 'email' => 'test@example.com']);

        expect($eventFired)->toBeFalse();
        expect($user->exists)->toBeTrue();
    }

    /**
     * Test booting and booted events
     */
    public function test_booting_and_booted_events()
    {
        $bootingCalled = false;
        $bootedCalled = false;

        // Create a new model class to test boot events
        $modelClass = new class extends \Look\EloquentCypher\GraphModel
        {
            protected $fillable = ['name'];

            public static $bootingCalled = false;

            public static $bootedCalled = false;

            protected static function booting()
            {
                parent::booting();
                self::$bootingCalled = true;
            }

            protected static function booted()
            {
                parent::booted();
                self::$bootedCalled = true;
            }
        };

        // Clear state
        $modelClass::$bootingCalled = false;
        $modelClass::$bootedCalled = false;

        // Force model to boot
        $modelClass::clearBootedModels();
        $instance = new $modelClass;

        expect($modelClass::$bootingCalled)->toBeTrue();
        expect($modelClass::$bootedCalled)->toBeTrue();
    }

    /**
     * Test retrieved event fires for all query methods
     */
    public function test_retrieved_event_fires_for_all_query_methods()
    {
        // Clean up any existing users first
        User::query()->delete();

        $user = User::create(['name' => 'Test User', 'email' => 'test@example.com']);

        $retrievedCount = 0;
        $retrievedModels = [];

        User::retrieved(function ($model) use (&$retrievedCount, &$retrievedModels) {
            $retrievedCount++;
            $retrievedModels[] = $model->id;
        });

        // Test with find
        $initialCount = $retrievedCount;
        $found1 = User::find($user->id);
        expect($retrievedCount)->toBeGreaterThan($initialCount);
        $afterFindCount = $retrievedCount;

        // Test with where
        $found2 = User::where('email', 'test@example.com')->first();
        expect($retrievedCount)->toBeGreaterThan($afterFindCount);
        $afterWhereCount = $retrievedCount;

        // Test with all
        User::all();
        expect($retrievedCount)->toBeGreaterThan($afterWhereCount);

        // Verify we got the correct model at least once
        expect($retrievedModels)->toContain($user->id);
    }

    /**
     * Test model events with transactions
     */
    public function test_model_events_with_transactions()
    {
        $events = [];

        User::creating(function ($user) use (&$events) {
            $events[] = 'creating';
        });

        User::created(function ($user) use (&$events) {
            $events[] = 'created';
        });

        // Note: Neo4j doesn't support traditional transactions like SQL databases
        // But we can still test that events fire correctly

        try {
            $user = User::create(['name' => 'Transaction Test', 'email' => 'transaction@example.com']);

            expect($events)->toBe(['creating', 'created']);
            expect($user->exists)->toBeTrue();

            // Simulate rollback scenario
            if ($user->email === 'transaction@example.com') {
                throw new \Exception('Simulated transaction failure');
            }
        } catch (\Exception $e) {
            // In a real transaction, the user would be rolled back
            // With Neo4j, we need to manually clean up
            $user->delete();
        }

        // Verify cleanup
        $found = User::where('email', 'transaction@example.com')->first();
        expect($found)->toBeNull();
    }

    /**
     * Test custom event names and dispatching
     */
    public function test_custom_event_dispatching()
    {
        // Don't fake events - we need the real event dispatcher for model events
        $customEventsFired = [];

        // Create custom event handler
        User::creating(function ($user) use (&$customEventsFired) {
            $customEventsFired[] = 'user.validating';
        });

        User::created(function ($user) use (&$customEventsFired) {
            $customEventsFired[] = 'user.welcome';
        });

        $user = User::create(['name' => 'Custom Event User', 'email' => 'custom@example.com']);

        expect($customEventsFired)->toContain('user.validating');
        expect($customEventsFired)->toContain('user.welcome');
        expect($user->exists)->toBeTrue();
    }

    /**
     * Test model events with attribute casting
     */
    public function test_model_events_with_attribute_casting()
    {
        $castingEvents = [];

        User::creating(function ($user) use (&$castingEvents) {
            // Test that casting happens before creating event
            if (isset($user->preferences) && is_array($user->preferences)) {
                $castingEvents[] = 'array_cast_in_creating';
            }
        });

        $user = User::create([
            'name' => 'Casting User',
            'email' => 'casting@example.com',
            'preferences' => ['theme' => 'dark', 'language' => 'en'],
        ]);

        expect($castingEvents)->toContain('array_cast_in_creating');
        expect($user->preferences)->toBeArray();
        expect($user->preferences['theme'])->toBe('dark');
    }

    /**
     * Test event listening for specific attributes
     */
    public function test_event_listening_for_specific_attributes()
    {
        $emailChanges = [];

        User::updating(function ($user) use (&$emailChanges) {
            if ($user->isDirty('email')) {
                $emailChanges[] = [
                    'old' => $user->getOriginal('email'),
                    'new' => $user->email,
                ];
            }
        });

        $user = User::create(['name' => 'Test User', 'email' => 'original@example.com']);

        // Update name only
        $user->update(['name' => 'New Name']);
        expect($emailChanges)->toBeEmpty();

        // Update email
        $user->update(['email' => 'new@example.com']);
        expect($emailChanges)->toHaveCount(1);
        expect($emailChanges[0]['old'])->toBe('original@example.com');
        expect($emailChanges[0]['new'])->toBe('new@example.com');
    }

    /**
     * Test nested model events
     */
    public function test_nested_model_events()
    {
        $nestedEvents = [];

        User::created(function ($user) use (&$nestedEvents) {
            $nestedEvents[] = 'user_created';

            // Create related profile in user created event
            $profile = $user->profile()->create([
                'bio' => 'Auto-generated profile',
                'website' => 'https://example.com',
            ]);

            $nestedEvents[] = 'profile_created_in_user_event';
        });

        Profile::created(function ($profile) use (&$nestedEvents) {
            $nestedEvents[] = 'profile_created';
        });

        $user = User::create(['name' => 'Nested Events User', 'email' => 'nested@example.com']);

        expect($nestedEvents)->toBe([
            'user_created',
            'profile_created',
            'profile_created_in_user_event',
        ]);

        expect($user->profile)->not->toBeNull();
        expect($user->profile->bio)->toBe('Auto-generated profile');
    }

    /**
     * Test model events with quietlySave
     */
    public function test_model_events_with_quietly_save()
    {
        $eventsFired = [];

        User::saving(function ($user) use (&$eventsFired) {
            $eventsFired[] = 'saving';
        });

        User::saved(function ($user) use (&$eventsFired) {
            $eventsFired[] = 'saved';
        });

        $user = User::create(['name' => 'Test User', 'email' => 'test@example.com']);

        // Reset events
        $eventsFired = [];

        // Update quietly
        $user->name = 'Updated Quietly';
        $user->saveQuietly();

        // Events should not fire
        expect($eventsFired)->toBeEmpty();

        // But data should be saved
        $fresh = User::find($user->id);
        expect($fresh->name)->toBe('Updated Quietly');
    }

    /**
     * Test event performance with many listeners
     */
    public function test_event_performance_with_many_listeners()
    {
        $listenerCalls = [];

        // Register 20 listeners
        for ($i = 0; $i < 20; $i++) {
            User::creating(function ($user) use ($i, &$listenerCalls) {
                $listenerCalls[] = "listener_$i";
                // Simulate some processing
                usleep(1000); // 1ms delay
            });
        }

        $startTime = microtime(true);

        $user = User::create(['name' => 'Performance Test', 'email' => 'perf@example.com']);

        $endTime = microtime(true);
        $executionTime = $endTime - $startTime;

        expect(count($listenerCalls))->toBe(20);
        expect($executionTime)->toBeLessThan(1); // Should complete within 1 second
        expect($user->exists)->toBeTrue();
    }
}
