<?php

use Tests\Models\User;
use Tests\Models\UserWithSoftDeletes;

// TEST: Observer Pattern via observe() method
// Focus: Test the newly implemented observe() method

test('observer method mapping to model events', function () {
    $observer = new class
    {
        public array $calls = [];

        public function creating($model)
        {
            $this->calls[] = 'creating';
        }

        public function created($model)
        {
            $this->calls[] = 'created';
        }

        public function updating($model)
        {
            $this->calls[] = 'updating';
        }

        public function updated($model)
        {
            $this->calls[] = 'updated';
        }

        public function saving($model)
        {
            $this->calls[] = 'saving';
        }

        public function saved($model)
        {
            $this->calls[] = 'saved';
        }

        public function deleting($model)
        {
            $this->calls[] = 'deleting';
        }

        public function deleted($model)
        {
            $this->calls[] = 'deleted';
        }
    };

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
});

test('observer can register multiple observers', function () {
    $observer1 = new class
    {
        public array $events = [];

        public function created($model)
        {
            $this->events[] = 'observer1:created';
        }

        public function updated($model)
        {
            $this->events[] = 'observer1:updated';
        }
    };

    $observer2 = new class
    {
        public array $events = [];

        public function created($model)
        {
            $this->events[] = 'observer2:created';
        }

        public function deleted($model)
        {
            $this->events[] = 'observer2:deleted';
        }
    };

    User::observe($observer1);
    User::observe($observer2);

    $user = User::create(['name' => 'Multi Observer', 'email' => 'multi@example.com']);
    expect($observer1->events)->toContain('observer1:created');
    expect($observer2->events)->toContain('observer2:created');

    $user->update(['name' => 'Updated']);
    expect($observer1->events)->toContain('observer1:updated');

    $user->delete();
    expect($observer2->events)->toContain('observer2:deleted');
});

test('observer can prevent operations by returning false', function () {
    $observer = new class
    {
        public function creating($model)
        {
            if ($model->email === 'blocked@example.com') {
                return false;
            }
        }

        public function updating($model)
        {
            if ($model->isDirty('name') && $model->name === 'Forbidden') {
                return false;
            }
        }

        public function deleting($model)
        {
            if ($model->email === 'protected@example.com') {
                return false;
            }
        }
    };

    User::observe($observer);

    // Test blocked creation
    $blockedUser = User::create(['name' => 'Blocked', 'email' => 'blocked@example.com']);
    expect($blockedUser->exists)->toBeFalse();

    // Test allowed creation
    $user = User::create(['name' => 'Allowed', 'email' => 'allowed@example.com']);
    expect($user->exists)->toBeTrue();

    // Test blocked update
    $result = $user->update(['name' => 'Forbidden']);
    expect($result)->toBeFalse();
    $user->refresh();
    expect($user->name)->toBe('Allowed');

    // Test blocked deletion
    $protectedUser = User::create(['name' => 'Protected', 'email' => 'protected@example.com']);
    $result = $protectedUser->delete();
    expect($result)->toBeFalse();
    expect(User::find($protectedUser->id))->not->toBeNull();
});

test('observer can modify attributes during events', function () {
    $observer = new class
    {
        public function creating($model)
        {
            $model->slug = str_replace(' ', '-', strtolower($model->name));
            $model->created_by = 'observer';
        }

        public function updating($model)
        {
            if ($model->isDirty('name')) {
                $model->slug = str_replace(' ', '-', strtolower($model->name));
                $model->updated_by = 'observer';
            }
        }

        public function saving($model)
        {
            $model->email = strtolower($model->email);
        }
    };

    User::observe($observer);

    // Test on creation
    $user = User::create(['name' => 'John Doe', 'email' => 'JOHN@EXAMPLE.COM']);
    expect($user->slug)->toBe('john-doe');
    expect($user->created_by)->toBe('observer');
    expect($user->email)->toBe('john@example.com');

    // Test on update
    $user->update(['name' => 'Jane Smith']);
    expect($user->slug)->toBe('jane-smith');
    expect($user->updated_by)->toBe('observer');
});

test('observer works with soft deletes', function () {
    $observer = new class
    {
        public array $events = [];

        public function deleting($model)
        {
            $this->events[] = 'deleting';
        }

        public function deleted($model)
        {
            $this->events[] = 'deleted';
        }

        public function restoring($model)
        {
            $this->events[] = 'restoring';
        }

        public function restored($model)
        {
            $this->events[] = 'restored';
        }

        public function forceDeleting($model)
        {
            $this->events[] = 'forceDeleting';
        }

        public function forceDeleted($model)
        {
            $this->events[] = 'forceDeleted';
        }
    };

    UserWithSoftDeletes::observe($observer);

    $user = UserWithSoftDeletes::create(['name' => 'Soft Delete', 'email' => 'soft@example.com']);

    // Soft delete
    $observer->events = [];
    $user->delete();
    expect($observer->events)->toBe(['deleting', 'deleted']);

    // Restore
    $observer->events = [];
    $user->restore();
    expect($observer->events)->toBe(['restoring', 'restored']);

    // Force delete
    $observer->events = [];
    $user->forceDelete();
    expect($observer->events)->toContain('forceDeleting');
    expect($observer->events)->toContain('forceDeleted');
});

test('observer can track retrieved events', function () {
    $observer = new class
    {
        public array $retrievedModels = [];

        public function retrieved($model)
        {
            $this->retrievedModels[] = $model->id;
            $model->retrieved_at = now()->timestamp;
        }
    };

    User::observe($observer);

    $user = User::create(['name' => 'Retrieved Test', 'email' => 'retrieved@example.com']);

    // Reset tracker
    $observer->retrievedModels = [];

    // Retrieve the model
    $found = User::find($user->id);
    expect($observer->retrievedModels)->toContain($user->id);
    expect($found->retrieved_at)->not->toBeNull();
});

test('observer works with class name strings', function () {
    // Create a simple observer class
    $observerClass = new class
    {
        public static array $staticEvents = [];

        public function created($model)
        {
            self::$staticEvents[] = 'created:'.$model->id;
        }

        public function updated($model)
        {
            self::$staticEvents[] = 'updated:'.$model->id;
        }
    };

    // Register using the observer instance (since we're using anonymous class)
    User::observe($observerClass);

    $user = User::create(['name' => 'String Observer', 'email' => 'string@example.com']);
    expect($observerClass::$staticEvents)->toContain('created:'.$user->id);

    $user->update(['name' => 'Updated String']);
    expect($observerClass::$staticEvents)->toContain('updated:'.$user->id);
});

test('observer with replicating event', function () {
    $observer = new class
    {
        public array $events = [];

        public function replicating($model)
        {
            $this->events[] = 'replicating';
            $model->is_replica = true;
        }
    };

    User::observe($observer);

    $original = User::create(['name' => 'Original', 'email' => 'original@example.com']);

    $replica = $original->replicate();

    expect($observer->events)->toContain('replicating');
    expect($replica->is_replica)->toBeTrue();
    expect($replica->exists)->toBeFalse();
});

test('multiple observers can be registered at once', function () {
    $observer1 = new class
    {
        public array $calls = [];

        public function created($model)
        {
            $this->calls[] = 'obs1:created';
        }
    };

    $observer2 = new class
    {
        public array $calls = [];

        public function created($model)
        {
            $this->calls[] = 'obs2:created';
        }
    };

    // Register multiple observers at once
    User::observe([$observer1, $observer2]);

    $user = User::create(['name' => 'Multiple', 'email' => 'multiple@example.com']);

    expect($observer1->calls)->toContain('obs1:created');
    expect($observer2->calls)->toContain('obs2:created');
});

test('observer methods receive the correct model instance', function () {
    $capturedModel = null;

    $observer = new class
    {
        public $capturedModel = null;

        public function created($model)
        {
            $this->capturedModel = $model;
        }
    };

    User::observe($observer);

    $user = User::create(['name' => 'Capture Test', 'email' => 'capture@example.com']);

    expect($observer->capturedModel)->toBeInstanceOf(User::class);
    expect($observer->capturedModel->id)->toBe($user->id);
    expect($observer->capturedModel->name)->toBe('Capture Test');
    expect($observer->capturedModel->email)->toBe('capture@example.com');
});
