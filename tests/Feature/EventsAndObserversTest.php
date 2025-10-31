<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Event;
use Tests\Models\User;
use Tests\TestCase\GraphTestCase;

class EventsAndObserversTest extends GraphTestCase
{
    /**
     * Test that creating event fires before model is saved
     */
    public function test_creating_event_fires_before_save()
    {
        $eventFired = false;
        $eventModel = null;

        User::creating(function ($user) use (&$eventFired, &$eventModel) {
            $eventFired = true;
            $eventModel = $user;
            // Model should not have ID yet since it's not saved
            expect($user->id)->toBeNull();
            expect($user->exists)->toBeFalse();
        });

        $user = User::create(['name' => 'John Doe', 'email' => 'john@example.com']);

        expect($eventFired)->toBeTrue();
        expect($eventModel)->not->toBeNull();
        expect($eventModel->name)->toBe('John Doe');
        expect($user->id)->not->toBeNull();
    }

    /**
     * Test that created event fires after model is saved
     */
    public function test_created_event_fires_after_save()
    {
        $eventFired = false;
        $eventModel = null;

        User::created(function ($user) use (&$eventFired, &$eventModel) {
            $eventFired = true;
            $eventModel = $user;
            // Model should have ID since it's already saved
            expect($user->id)->not->toBeNull();
            expect($user->exists)->toBeTrue();
        });

        $user = User::create(['name' => 'Jane Doe', 'email' => 'jane@example.com']);

        expect($eventFired)->toBeTrue();
        expect($eventModel)->not->toBeNull();
        expect($eventModel->id)->toBe($user->id);
    }

    /**
     * Test that updating event fires before update
     */
    public function test_updating_event_fires_before_update()
    {
        $user = User::create(['name' => 'Original Name', 'email' => 'original@example.com']);

        $eventFired = false;
        $originalName = null;

        User::updating(function ($user) use (&$eventFired, &$originalName) {
            $eventFired = true;
            // Should still have original values in database
            $originalName = $user->getOriginal('name');
        });

        $user->update(['name' => 'Updated Name']);

        expect($eventFired)->toBeTrue();
        expect($originalName)->toBe('Original Name');
        expect($user->name)->toBe('Updated Name');
    }

    /**
     * Test that updated event fires after update
     */
    public function test_updated_event_fires_after_update()
    {
        $user = User::create(['name' => 'Original Name', 'email' => 'original@example.com']);

        $eventFired = false;
        $updatedName = null;

        User::updated(function ($user) use (&$eventFired, &$updatedName) {
            $eventFired = true;
            // Should have new values
            $updatedName = $user->name;
        });

        $user->update(['name' => 'New Name']);

        expect($eventFired)->toBeTrue();
        expect($updatedName)->toBe('New Name');
    }

    /**
     * Test that deleting event fires before delete
     */
    public function test_deleting_event_fires_before_delete()
    {
        $user = User::create(['name' => 'To Delete', 'email' => 'delete@example.com']);

        $eventFired = false;
        $modelStillExists = null;

        User::deleting(function ($user) use (&$eventFired, &$modelStillExists) {
            $eventFired = true;
            // Model should still exist in database
            $modelStillExists = $user->exists;
        });

        $user->delete();

        expect($eventFired)->toBeTrue();
        expect($modelStillExists)->toBeTrue();
        expect($user->exists)->toBeFalse();
    }

    /**
     * Test that deleted event fires after delete
     */
    public function test_deleted_event_fires_after_delete()
    {
        $user = User::create(['name' => 'To Delete', 'email' => 'delete@example.com']);

        $eventFired = false;
        $modelExists = null;

        User::deleted(function ($user) use (&$eventFired, &$modelExists) {
            $eventFired = true;
            // Model should no longer exist
            $modelExists = $user->exists;
        });

        $user->delete();

        expect($eventFired)->toBeTrue();
        expect($modelExists)->toBeFalse();
    }

    /**
     * Test that saving event fires for both create and update
     */
    public function test_saving_event_fires_for_create_and_update()
    {
        $createEventFired = false;
        $updateEventFired = false;

        User::saving(function ($user) use (&$createEventFired, &$updateEventFired) {
            if ($user->exists) {
                $updateEventFired = true;
            } else {
                $createEventFired = true;
            }
        });

        // Test on create
        $user = User::create(['name' => 'Test User', 'email' => 'test@example.com']);
        expect($createEventFired)->toBeTrue();
        expect($updateEventFired)->toBeFalse();

        // Reset and test on update
        $createEventFired = false;
        $user->update(['name' => 'Updated User']);
        expect($createEventFired)->toBeFalse();
        expect($updateEventFired)->toBeTrue();
    }

    /**
     * Test that saved event fires after both create and update
     */
    public function test_saved_event_fires_after_create_and_update()
    {
        $savedCount = 0;

        User::saved(function ($user) use (&$savedCount) {
            $savedCount++;
            expect($user->exists)->toBeTrue();
        });

        // Test on create
        $user = User::create(['name' => 'Test User', 'email' => 'test@example.com']);
        expect($savedCount)->toBe(1);

        // Test on update
        $user->update(['name' => 'Updated User']);
        expect($savedCount)->toBe(2);
    }

    /**
     * Test that returning false from creating event prevents save
     */
    public function test_returning_false_from_creating_prevents_save()
    {
        User::creating(function ($user) {
            // Prevent save by returning false
            return false;
        });

        $user = User::create(['name' => 'Should Not Save', 'email' => 'nosave@example.com']);

        expect($user->exists)->toBeFalse();
        expect($user->id)->toBeNull();

        // Verify not in database
        $found = User::where('email', 'nosave@example.com')->first();
        expect($found)->toBeNull();
    }

    /**
     * Test that returning false from updating event prevents update
     */
    public function test_returning_false_from_updating_prevents_update()
    {
        $user = User::create(['name' => 'Original', 'email' => 'original@example.com']);

        User::updating(function ($user) {
            // Prevent update by returning false
            return false;
        });

        $result = $user->update(['name' => 'Should Not Update']);

        expect($result)->toBeFalse();
        expect($user->name)->toBe('Should Not Update'); // Local change

        // Verify database still has original
        $fresh = User::find($user->id);
        expect($fresh->name)->toBe('Original');
    }

    /**
     * Test that returning false from deleting event prevents delete
     */
    public function test_returning_false_from_deleting_prevents_delete()
    {
        $user = User::create(['name' => 'Cannot Delete', 'email' => 'nodelete@example.com']);

        User::deleting(function ($user) {
            // Prevent delete by returning false
            return false;
        });

        $result = $user->delete();

        expect($result)->toBeFalse();
        expect($user->exists)->toBeTrue();

        // Verify still in database
        $found = User::find($user->id);
        expect($found)->not->toBeNull();
    }

    /**
     * Test multiple event listeners can be registered
     */
    public function test_multiple_event_listeners_can_be_registered()
    {
        $listener1Called = false;
        $listener2Called = false;

        User::creating(function ($user) use (&$listener1Called) {
            $listener1Called = true;
        });

        User::creating(function ($user) use (&$listener2Called) {
            $listener2Called = true;
        });

        User::create(['name' => 'Test Multiple', 'email' => 'multiple@example.com']);

        expect($listener1Called)->toBeTrue();
        expect($listener2Called)->toBeTrue();
    }

    /**
     * Test model observer-like behavior using event listeners
     */
    public function test_model_observer_registration()
    {
        // Track which events were called
        $eventCalls = [];

        // Register event listeners for all lifecycle events
        User::creating(function ($model) use (&$eventCalls) {
            $eventCalls[] = 'creating';
        });

        User::created(function ($model) use (&$eventCalls) {
            $eventCalls[] = 'created';
        });

        User::updating(function ($model) use (&$eventCalls) {
            $eventCalls[] = 'updating';
        });

        User::updated(function ($model) use (&$eventCalls) {
            $eventCalls[] = 'updated';
        });

        User::deleting(function ($model) use (&$eventCalls) {
            $eventCalls[] = 'deleting';
        });

        User::deleted(function ($model) use (&$eventCalls) {
            $eventCalls[] = 'deleted';
        });

        // Test create events
        $user = User::create(['name' => 'Observer Test', 'email' => 'observer@example.com']);
        expect($eventCalls)->toContain('creating');
        expect($eventCalls)->toContain('created');
        expect(count($eventCalls))->toBe(2);

        // Reset and test update events
        $eventCalls = [];
        $user->update(['name' => 'Updated Observer']);
        expect($eventCalls)->toContain('updating');
        expect($eventCalls)->toContain('updated');
        expect(count($eventCalls))->toBe(2);

        // Reset and test delete events
        $eventCalls = [];
        $user->delete();
        expect($eventCalls)->toContain('deleting');
        expect($eventCalls)->toContain('deleted');
        expect(count($eventCalls))->toBe(2);
    }

    /**
     * Test that events can modify model attributes before save
     */
    public function test_events_can_modify_attributes_before_save()
    {
        User::creating(function ($user) {
            // Automatically uppercase the name
            $user->name = strtoupper($user->name);
            // Add a prefix to email
            $user->email = 'prefix_'.$user->email;
        });

        $user = User::create(['name' => 'john doe', 'email' => 'john@example.com']);

        expect($user->name)->toBe('JOHN DOE');
        expect($user->email)->toBe('prefix_john@example.com');

        // Verify in database
        $fresh = User::find($user->id);
        expect($fresh->name)->toBe('JOHN DOE');
        expect($fresh->email)->toBe('prefix_john@example.com');
    }

    /**
     * Test global event dispatcher integration
     */
    public function test_global_event_dispatcher_integration()
    {
        Event::fake();

        $user = User::create(['name' => 'Event Test', 'email' => 'event@example.com']);

        // Laravel fires eloquent.creating and eloquent.created events
        Event::assertDispatched('eloquent.creating: '.User::class);
        Event::assertDispatched('eloquent.created: '.User::class);

        $user->update(['name' => 'Updated Event']);

        Event::assertDispatched('eloquent.updating: '.User::class);
        Event::assertDispatched('eloquent.updated: '.User::class);

        $user->delete();

        Event::assertDispatched('eloquent.deleting: '.User::class);
        Event::assertDispatched('eloquent.deleted: '.User::class);
    }

    /**
     * Test retrieved event (skip for now as it requires query builder integration)
     */
    public function test_retrieved_event()
    {
        // Create a user first
        $user = User::create(['name' => 'Test User', 'email' => 'test@example.com']);

        $retrievedEventFired = false;

        // Listen for the retrieved event
        User::retrieved(function ($model) use (&$retrievedEventFired) {
            $retrievedEventFired = true;
        });

        // Find the user (this should trigger the retrieved event)
        $foundUser = User::find($user->id);

        expect($retrievedEventFired)->toBeTrue();
        expect($foundUser->name)->toBe('Test User');
    }

    /**
     * Test that booted method is called for trait initialization
     */
    public function test_booted_method_called_for_trait_initialization()
    {
        // Reset trait state first
        TestEventTrait::$traitBooted = false;

        // Create a test model with a trait that uses booted method
        $model = new class extends User
        {
            use TestEventTrait;
        };

        // The trait's booted method should have been called
        expect($model::$traitBooted)->toBeTrue();
    }

    /**
     * Test event priority and order
     */
    public function test_event_priority_and_order()
    {
        $callOrder = [];

        User::saving(function ($user) use (&$callOrder) {
            $callOrder[] = 'saving';
        });

        User::creating(function ($user) use (&$callOrder) {
            $callOrder[] = 'creating';
        });

        User::created(function ($user) use (&$callOrder) {
            $callOrder[] = 'created';
        });

        User::saved(function ($user) use (&$callOrder) {
            $callOrder[] = 'saved';
        });

        User::create(['name' => 'Order Test', 'email' => 'order@example.com']);

        // Events should fire in this order
        expect($callOrder)->toBe(['saving', 'creating', 'created', 'saved']);
    }

    /**
     * Test replicating event when model is replicated
     */
    public function test_replicating_event_on_model_replication()
    {
        $eventFired = false;
        $eventModel = null;

        User::replicating(function ($user) use (&$eventFired, &$eventModel) {
            $eventFired = true;
            $eventModel = $user;
        });

        $original = User::create(['name' => 'Original User', 'email' => 'original@example.com']);
        $replicated = $original->replicate();

        expect($eventFired)->toBe(true);
        expect($eventModel)->toBe($replicated); // Event fires on the new instance
        expect($replicated->name)->toBe('Original User');
        expect($replicated->email)->toBe('original@example.com');
        expect($replicated->id)->toBeNull(); // ID should be excluded
        expect($replicated->created_at)->toBeNull(); // Timestamps should be excluded
        expect($replicated->updated_at)->toBeNull();
        expect($replicated->exists)->toBe(false); // Should be a new instance
    }
}

/**
 * Test trait for booted method testing
 */
trait TestEventTrait
{
    public static $traitBooted = false;

    protected static function bootTestEventTrait()
    {
        static::$traitBooted = true;
    }
}
