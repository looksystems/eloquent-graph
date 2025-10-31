<?php

namespace Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Tests\Models\Comment;
use Tests\Models\Post;
use Tests\Models\User;
use Tests\TestCase\GraphTestCase;

class ModelObserversTest extends GraphTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Clear any observers and event listeners
        User::flushEventListeners();
        Post::flushEventListeners();
        Comment::flushEventListeners();
    }

    /**
     * Test observer method mapping to model events
     */
    public function test_observer_method_mapping()
    {
        // Observer pattern now implemented!
        $observer = new UserTestObserver;
        User::observe($observer);

        // Test creating/created
        $user = User::create(['name' => 'Observer Test', 'email' => 'observer@example.com']);
        expect($observer->calls)->toContain('creating');
        expect($observer->calls)->toContain('created');
        expect($observer->calls)->toContain('saving');
        expect($observer->calls)->toContain('saved');

        // Reset observer
        $observer->calls = [];

        // Test updating/updated
        $user->update(['name' => 'Updated Observer']);
        expect($observer->calls)->toContain('updating');
        expect($observer->calls)->toContain('updated');
        expect($observer->calls)->toContain('saving');
        expect($observer->calls)->toContain('saved');

        // Reset observer
        $observer->calls = [];

        // Test deleting/deleted
        $user->delete();
        expect($observer->calls)->toContain('deleting');
        expect($observer->calls)->toContain('deleted');
    }

    /**
     * Test observer priority handling with multiple observers
     */
    public function test_observer_priority_handling()
    {
        $callOrder = [];

        // Use event listeners instead of observers for testing order
        User::creating(function ($model) use (&$callOrder) {
            $callOrder[] = 'listener1';
        });

        User::creating(function ($model) use (&$callOrder) {
            $callOrder[] = 'listener2';
        });

        User::create(['name' => 'Priority Test', 'email' => 'priority@example.com']);

        expect($callOrder)->toBe(['listener1', 'listener2']);
    }

    /**
     * Test observer with relationship events
     */
    public function test_observer_with_relationship_events()
    {
        $relationshipEvents = [];

        User::created(function ($user) use (&$relationshipEvents) {
            $relationshipEvents[] = ['model' => 'user', 'event' => 'created', 'id' => $user->id];
        });

        Post::created(function ($post) use (&$relationshipEvents) {
            $relationshipEvents[] = ['model' => 'post', 'event' => 'created', 'id' => $post->id];
            // Automatically create a comment when post is created
            $post->comments()->create([
                'content' => 'Auto comment',
                'author_name' => 'System',
            ]);
        });

        Comment::created(function ($comment) use (&$relationshipEvents) {
            $relationshipEvents[] = ['model' => 'comment', 'event' => 'created', 'id' => $comment->id];
        });

        $user = User::create(['name' => 'Relationship Test', 'email' => 'rel@example.com']);
        $post = $user->posts()->create(['title' => 'Test Post', 'content' => 'Content']);

        expect($relationshipEvents)->toHaveCount(3);
        expect($relationshipEvents[0]['model'])->toBe('user');
        expect($relationshipEvents[1]['model'])->toBe('post');
        expect($relationshipEvents[2]['model'])->toBe('comment');

        // Verify auto-created comment
        expect($post->comments)->toHaveCount(1);
        expect($post->comments->first()->content)->toBe('Auto comment');
    }

    /**
     * Test observer exception handling
     */
    public function test_observer_exception_handling()
    {
        User::creating(function ($model) {
            if ($model->email === 'error@example.com') {
                throw new \Exception('Observer exception');
            }
        });

        $exceptionCaught = false;
        try {
            User::create(['name' => 'Error Test', 'email' => 'error@example.com']);
        } catch (\Exception $e) {
            $exceptionCaught = true;
            expect($e->getMessage())->toBe('Observer exception');
        }

        expect($exceptionCaught)->toBeTrue();

        // Verify model not saved
        $found = User::where('email', 'error@example.com')->first();
        expect($found)->toBeNull();

        // Normal operations should still work
        $user = User::create(['name' => 'Normal Test', 'email' => 'normal@example.com']);
        expect($user->exists)->toBeTrue();
    }

    /**
     * Test multiple event listeners on single model
     */
    public function test_multiple_event_listeners_on_single_model()
    {
        $calls = [];

        User::creating(function ($model) use (&$calls) {
            $calls[] = 'log:creating';
        });

        User::creating(function ($model) use (&$calls) {
            $calls[] = 'validation:creating';
            // Perform validation
            if (strlen($model->name) < 3) {
                return false;
            }
        });

        User::created(function ($model) use (&$calls) {
            $calls[] = 'audit:created';
            $model->audit_timestamp = now()->timestamp;
        });

        // Test with valid data
        $user = User::create(['name' => 'Valid User', 'email' => 'valid@example.com']);

        expect($calls)->toContain('log:creating');
        expect($calls)->toContain('validation:creating');
        expect($calls)->toContain('audit:created');
        expect($user->audit_timestamp)->not->toBeNull();

        // Test with invalid data
        $calls = [];
        $invalidUser = User::create(['name' => 'AB', 'email' => 'short@example.com']);

        expect($calls)->toContain('log:creating');
        expect($calls)->toContain('validation:creating');
        expect($calls)->not->toContain('audit:created'); // Should not reach created
        expect($invalidUser->exists)->toBeFalse();
    }

    /**
     * Test event listener cancellation with return false
     */
    public function test_event_listener_cancellation_with_return_false()
    {
        User::creating(function ($model) {
            // Cancel creation for blacklisted emails
            $blacklist = ['spam@example.com', 'blocked@example.com'];
            if (in_array($model->email, $blacklist)) {
                return false;
            }
        });

        User::updating(function ($model) {
            // Prevent changing to blacklisted email
            if ($model->isDirty('email')) {
                $blacklist = ['spam@example.com', 'blocked@example.com'];
                if (in_array($model->email, $blacklist)) {
                    return false;
                }
            }
        });

        // Test blocked creation
        $blockedUser = User::create(['name' => 'Blocked', 'email' => 'spam@example.com']);
        expect($blockedUser->exists)->toBeFalse();

        // Test allowed creation
        $allowedUser = User::create(['name' => 'Allowed', 'email' => 'allowed@example.com']);
        expect($allowedUser->exists)->toBeTrue();

        // Test blocked update
        $result = $allowedUser->update(['email' => 'blocked@example.com']);
        expect($result)->toBeFalse();
        expect($allowedUser->email)->toBe('blocked@example.com'); // Local change

        // Verify database still has original
        $fresh = User::find($allowedUser->id);
        expect($fresh->email)->toBe('allowed@example.com');
    }

    /**
     * Test event listeners with soft deletes
     */
    public function test_event_listeners_with_soft_deletes()
    {
        $events = [];

        $userClass = \Tests\Models\UserWithSoftDeletes::class;

        $userClass::deleting(function ($model) use (&$events) {
            $events[] = 'deleting';
        });

        $userClass::deleted(function ($model) use (&$events) {
            $events[] = 'deleted';
        });

        $userClass::restoring(function ($model) use (&$events) {
            $events[] = 'restoring';
        });

        $userClass::restored(function ($model) use (&$events) {
            $events[] = 'restored';
        });

        $userClass::forceDeleting(function ($model) use (&$events) {
            $events[] = 'forceDeleting';
        });

        $userClass::forceDeleted(function ($model) use (&$events) {
            $events[] = 'forceDeleted';
        });

        $user = $userClass::create(['name' => 'Soft Delete Test', 'email' => 'soft@example.com']);

        // Soft delete
        $events = [];
        $user->delete();
        expect($events)->toBe(['deleting', 'deleted']);

        // Restore
        $events = [];
        $user->restore();
        expect($events)->toBe(['restoring', 'restored']);

        // Force delete - may also trigger deleting/deleted before force delete
        $events = [];
        $user->forceDelete();
        // Just check that forceDeleting and forceDeleted are present
        expect($events)->toContain('forceDeleting');
        expect($events)->toContain('forceDeleted');
    }

    /**
     * Test event listener data modification
     */
    public function test_event_listener_data_modification()
    {
        User::creating(function ($model) {
            // Add computed fields
            $model->slug = str_replace(' ', '-', strtolower($model->name));
            $model->name_length = strlen($model->name);
        });

        User::updating(function ($model) {
            // Update computed fields
            if ($model->isDirty('name')) {
                $model->slug = str_replace(' ', '-', strtolower($model->name));
                $model->name_length = strlen($model->name);
            }
        });

        User::saving(function ($model) {
            // Ensure email is lowercase
            $model->email = strtolower($model->email);
        });

        // Test on creation
        $user = User::create(['name' => 'John Doe', 'email' => 'JOHN@EXAMPLE.COM']);
        expect($user->slug)->toBe('john-doe');
        expect($user->name_length)->toBe(8);
        expect($user->email)->toBe('john@example.com');

        // Test on update
        $user->update(['name' => 'Jane Smith']);
        expect($user->slug)->toBe('jane-smith');
        expect($user->name_length)->toBe(10);
    }

    /**
     * Test multiple event listener registration
     */
    public function test_multiple_event_listener_registration()
    {
        $calls = [];

        // Register multiple listeners for created event
        User::created(function ($model) use (&$calls) {
            $calls[] = 'listener1:created';
        });

        User::created(function ($model) use (&$calls) {
            $calls[] = 'listener2:created';
        });

        User::updated(function ($model) use (&$calls) {
            $calls[] = 'listener1:updated';
        });

        $user = User::create(['name' => 'Multiple Listeners', 'email' => 'multiple@example.com']);
        expect($calls)->toContain('listener1:created');
        expect($calls)->toContain('listener2:created');

        $calls = [];
        $user->update(['name' => 'Updated Multiple']);
        expect($calls)->toContain('listener1:updated');
    }

    /**
     * Test event listener with retrieved event
     */
    public function test_event_listener_with_retrieved_event()
    {
        $retrievedCount = 0;
        $retrievedModels = [];

        User::retrieved(function ($model) use (&$retrievedCount, &$retrievedModels) {
            $retrievedCount++;
            $retrievedModels[] = $model->id;
            // Add runtime property
            $model->retrieved_at = now()->timestamp;
        });

        $user = User::create(['name' => 'Retrieved Test', 'email' => 'retrieved@example.com']);

        // Reset counters
        $retrievedCount = 0;
        $retrievedModels = [];

        // Retrieve the model
        $found = User::find($user->id);
        expect($retrievedCount)->toBe(1);
        expect($retrievedModels[0])->toBe($user->id);
        expect($found->retrieved_at)->not->toBeNull();

        // Retrieve multiple may trigger multiple times
        User::where('email', 'retrieved@example.com')->get();
        expect($retrievedCount)->toBeGreaterThanOrEqual(2);
    }

    /**
     * Test event listener performance impact
     */
    public function test_event_listener_performance_impact()
    {
        $processedCount = 0;

        User::creating(function ($model) use (&$processedCount) {
            $processedCount++;
            // Simulate heavy processing
            $hash = password_hash($model->email, PASSWORD_DEFAULT);
            $model->email_hash = md5($hash);
            usleep(10000); // 10ms delay
        });

        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        // Create multiple users
        for ($i = 0; $i < 20; $i++) {
            User::create([
                'name' => "Performance User $i",
                'email' => "perf$i@example.com",
            ]);
        }

        $endTime = microtime(true);
        $endMemory = memory_get_usage();

        $executionTime = $endTime - $startTime;
        $memoryUsed = ($endMemory - $startMemory) / 1024 / 1024; // MB

        expect($processedCount)->toBe(20);
        expect($executionTime)->toBeLessThan(5); // Should complete within 5 seconds
        expect($memoryUsed)->toBeLessThan(10); // Should use less than 10MB
    }

    /**
     * Test event listener with model replication
     */
    public function test_event_listener_with_model_replication()
    {
        $events = [];

        User::replicating(function ($model) use (&$events) {
            $events[] = 'replicating';
            // Modify replica before it's returned
            $model->is_replica = true;
        });

        $original = User::create(['name' => 'Original User', 'email' => 'original@example.com']);

        $events = [];
        $replica = $original->replicate();

        expect($events)->toContain('replicating');
        expect($replica->is_replica)->toBeTrue();
        expect($replica->exists)->toBeFalse();
        expect($replica->id)->toBeNull();
    }

    /**
     * Test clearing event listeners
     */
    public function test_clearing_event_listeners()
    {
        $listenerCalled = false;

        User::creating(function ($model) use (&$listenerCalled) {
            $listenerCalled = true;
        });

        // Clear all event listeners
        User::flushEventListeners();

        // Listener should not be called
        User::create(['name' => 'No Listener', 'email' => 'nolistener@example.com']);

        expect($listenerCalled)->toBeFalse();
    }

    /**
     * Test event listener with touch events
     */
    public function test_event_listener_with_touch_events()
    {
        $touchEvents = [];

        User::updating(function ($model) use (&$touchEvents) {
            if ($model->isDirty('updated_at') && count($model->getDirty()) === 1) {
                $touchEvents[] = 'touch:updating';
            }
        });

        User::updated(function ($model) use (&$touchEvents) {
            $changes = $model->getChanges();
            // Check if only updated_at was changed (and possibly id)
            if (isset($changes['updated_at']) && count(array_diff(array_keys($changes), ['updated_at', 'id'])) === 0) {
                $touchEvents[] = 'touch:updated';
            }
        });

        $user = User::create(['name' => 'Touch Test', 'email' => 'touch@example.com']);

        // Reset events
        $touchEvents = [];

        // Wait to ensure timestamp difference
        sleep(1);

        // Touch the model
        $user->touch();

        expect($touchEvents)->toContain('touch:updating');
        expect($touchEvents)->toContain('touch:updated');
    }
}

/**
 * Test observer class for testing
 */
class UserTestObserver
{
    public $calls = [];

    public function creating($user)
    {
        $this->calls[] = 'creating';
    }

    public function created($user)
    {
        $this->calls[] = 'created';
    }

    public function updating($user)
    {
        $this->calls[] = 'updating';
    }

    public function updated($user)
    {
        $this->calls[] = 'updated';
    }

    public function saving($user)
    {
        $this->calls[] = 'saving';
    }

    public function saved($user)
    {
        $this->calls[] = 'saved';
    }

    public function deleting($user)
    {
        $this->calls[] = 'deleting';
    }

    public function deleted($user)
    {
        $this->calls[] = 'deleted';
    }
}
