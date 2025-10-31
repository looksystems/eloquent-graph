<?php

use Illuminate\Support\Facades\Event;
use Tests\Models\Post;
use Tests\Models\User;
use Tests\Models\UserWithSoftDeletes;

// TEST: Enhanced Observer Pattern Tests
// Focus: Complete observer pattern implementation using event listeners

beforeEach(function () {
    // Clear any observers and event listeners
    User::flushEventListeners();
    Post::flushEventListeners();
    UserWithSoftDeletes::flushEventListeners();
});

test('observer pattern can be simulated with event listeners', function () {
    // Create an observer-like class
    $observer = new class
    {
        public array $calls = [];

        public function observe($modelClass)
        {
            $modelClass::creating(fn ($model) => $this->creating($model));
            $modelClass::created(fn ($model) => $this->created($model));
            $modelClass::updating(fn ($model) => $this->updating($model));
            $modelClass::updated(fn ($model) => $this->updated($model));
            $modelClass::saving(fn ($model) => $this->saving($model));
            $modelClass::saved(fn ($model) => $this->saved($model));
            $modelClass::deleting(fn ($model) => $this->deleting($model));
            $modelClass::deleted(fn ($model) => $this->deleted($model));
        }

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

    // Attach observer to User model
    $observer->observe(User::class);

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

test('observer can prevent model operations by returning false', function () {
    $observer = new class
    {
        public function observe($modelClass)
        {
            $modelClass::creating(fn ($model) => $this->creating($model));
            $modelClass::updating(fn ($model) => $this->updating($model));
            $modelClass::deleting(fn ($model) => $this->deleting($model));
        }

        public function creating($model)
        {
            // Block creation of test@blocked.com
            if ($model->email === 'test@blocked.com') {
                return false;
            }
        }

        public function updating($model)
        {
            // Prevent name changes to 'Forbidden'
            if ($model->isDirty('name') && $model->name === 'Forbidden') {
                return false;
            }
        }

        public function deleting($model)
        {
            // Prevent deletion of admin users
            if ($model->email === 'admin@example.com') {
                return false;
            }
        }
    };

    $observer->observe(User::class);

    // Test blocked creation
    $blockedUser = User::create(['name' => 'Blocked', 'email' => 'test@blocked.com']);
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
    $adminUser = User::create(['name' => 'Admin', 'email' => 'admin@example.com']);
    $result = $adminUser->delete();
    expect($result)->toBeFalse();
    expect(User::find($adminUser->id))->not->toBeNull();
});

test('observer can modify model attributes during events', function () {
    $observer = new class
    {
        public function observe($modelClass)
        {
            $modelClass::creating(fn ($model) => $this->creating($model));
            $modelClass::updating(fn ($model) => $this->updating($model));
            $modelClass::saving(fn ($model) => $this->saving($model));
        }

        public function creating($model)
        {
            // Generate slug from name
            $model->slug = $this->str_slug($model->name);
            // Add creation metadata
            $model->created_by = 'system';
        }

        public function updating($model)
        {
            // Track who modified
            $model->modified_by = 'system';
            // Update slug if name changed
            if ($model->isDirty('name')) {
                $model->slug = $this->str_slug($model->name);
            }
        }

        public function saving($model)
        {
            // Ensure email is lowercase
            $model->email = strtolower($model->email);
            // Add hash
            $model->email_hash = md5($model->email);
        }

        private function str_slug($value)
        {
            return str_replace(' ', '-', strtolower($value));
        }
    };

    $observer->observe(User::class);

    // Test on creation
    $user = User::create(['name' => 'John Doe', 'email' => 'JOHN@EXAMPLE.COM']);
    expect($user->slug)->toBe('john-doe');
    expect($user->created_by)->toBe('system');
    expect($user->email)->toBe('john@example.com');
    expect($user->email_hash)->toBe(md5('john@example.com'));

    // Test on update
    $user->update(['name' => 'Jane Smith']);
    expect($user->slug)->toBe('jane-smith');
    expect($user->modified_by)->toBe('system');
});

test('multiple observers can be attached to same model', function () {
    $auditObserver = new class
    {
        public array $audits = [];

        public function observe($modelClass)
        {
            $modelClass::created(fn ($model) => $this->audit('created', $model));
            $modelClass::updated(fn ($model) => $this->audit('updated', $model));
            $modelClass::deleted(fn ($model) => $this->audit('deleted', $model));
        }

        private function audit($action, $model)
        {
            $this->audits[] = [
                'action' => $action,
                'model' => get_class($model),
                'id' => $model->id,
                'timestamp' => now()->timestamp,
            ];
        }
    };

    $cacheObserver = new class
    {
        public array $cacheInvalidations = [];

        public function observe($modelClass)
        {
            $modelClass::saved(fn ($model) => $this->invalidateCache($model));
            $modelClass::deleted(fn ($model) => $this->invalidateCache($model));
        }

        private function invalidateCache($model)
        {
            $this->cacheInvalidations[] = [
                'key' => get_class($model).':'.$model->id,
                'timestamp' => now()->timestamp,
            ];
        }
    };

    // Attach both observers
    $auditObserver->observe(User::class);
    $cacheObserver->observe(User::class);

    // Create user
    $user = User::create(['name' => 'Multi Observer', 'email' => 'multi@example.com']);

    // Check audit observer
    expect($auditObserver->audits)->toHaveCount(1);
    expect($auditObserver->audits[0]['action'])->toBe('created');

    // Check cache observer
    expect($cacheObserver->cacheInvalidations)->toHaveCount(1);
    expect($cacheObserver->cacheInvalidations[0]['key'])->toContain($user->id);

    // Update user
    $user->update(['name' => 'Updated Multi']);

    expect($auditObserver->audits)->toHaveCount(2);
    expect($auditObserver->audits[1]['action'])->toBe('updated');
    expect($cacheObserver->cacheInvalidations)->toHaveCount(2);
});

test('observer works with soft deletes', function () {
    $observer = new class
    {
        public array $events = [];

        public function observe($modelClass)
        {
            $modelClass::deleting(fn ($model) => $this->events[] = 'deleting');
            $modelClass::deleted(fn ($model) => $this->events[] = 'deleted');
            $modelClass::restoring(fn ($model) => $this->events[] = 'restoring');
            $modelClass::restored(fn ($model) => $this->events[] = 'restored');
            $modelClass::forceDeleting(fn ($model) => $this->events[] = 'forceDeleting');
            $modelClass::forceDeleted(fn ($model) => $this->events[] = 'forceDeleted');
        }
    };

    $observer->observe(UserWithSoftDeletes::class);

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

test('observer can handle batch operations', function () {
    $observer = new class
    {
        public int $createCount = 0;

        public int $updateCount = 0;

        public array $processedIds = [];

        public function observe($modelClass)
        {
            $modelClass::created(fn ($model) => $this->handleCreated($model));
            $modelClass::updated(fn ($model) => $this->handleUpdated($model));
        }

        private function handleCreated($model)
        {
            $this->createCount++;
            $this->processedIds[] = $model->id;
        }

        private function handleUpdated($model)
        {
            $this->updateCount++;
        }
    };

    $observer->observe(User::class);

    // Batch create
    $users = [];
    for ($i = 1; $i <= 10; $i++) {
        $users[] = User::create([
            'name' => "Batch User $i",
            'email' => "batch$i@example.com",
        ]);
    }

    expect($observer->createCount)->toBe(10);
    expect($observer->processedIds)->toHaveCount(10);

    // Batch update
    foreach ($users as $user) {
        $user->update(['status' => 'processed']);
    }

    expect($observer->updateCount)->toBe(10);
});

test('observer can track model relationships', function () {
    $observer = new class
    {
        public array $relationshipEvents = [];

        public function observeUser($modelClass)
        {
            $modelClass::created(fn ($model) => $this->trackRelationship('user_created', $model));
        }

        public function observePost($modelClass)
        {
            $modelClass::created(fn ($model) => $this->trackPostCreation($model));
        }

        private function trackRelationship($event, $model)
        {
            $this->relationshipEvents[] = [
                'event' => $event,
                'model_id' => $model->id,
            ];
        }

        private function trackPostCreation($post)
        {
            $this->relationshipEvents[] = [
                'event' => 'post_created',
                'post_id' => $post->id,
                'user_id' => $post->user_id,
            ];

            // Auto-create related data
            if ($post->user_id) {
                $post->comments()->create([
                    'content' => 'Auto-generated welcome comment',
                    'user_id' => $post->user_id,
                ]);
            }
        }
    };

    $observer->observeUser(User::class);
    $observer->observePost(Post::class);

    $user = User::create(['name' => 'Relationship Test', 'email' => 'rel@example.com']);
    $post = $user->posts()->create(['title' => 'Test Post']);

    expect($observer->relationshipEvents)->toHaveCount(2);
    expect($observer->relationshipEvents[0]['event'])->toBe('user_created');
    expect($observer->relationshipEvents[1]['event'])->toBe('post_created');
    expect($observer->relationshipEvents[1]['user_id'])->toBe($user->id);

    // Check auto-created comment
    expect($post->comments)->toHaveCount(1);
    expect($post->comments->first()->content)->toContain('welcome');
});

test('observer exception handling and recovery', function () {
    $observer = new class
    {
        public int $attempts = 0;

        public function observe($modelClass)
        {
            $modelClass::creating(fn ($model) => $this->handleCreating($model));
        }

        private function handleCreating($model)
        {
            $this->attempts++;

            // Fail on first attempt for specific email
            if ($model->email === 'retry@example.com' && $this->attempts === 1) {
                throw new \Exception('Temporary failure');
            }

            // Add processing flag
            $model->processed = true;
        }
    };

    $observer->observe(User::class);

    // First attempt should fail
    $exceptionCaught = false;
    try {
        User::create(['name' => 'Retry Test', 'email' => 'retry@example.com']);
    } catch (\Exception $e) {
        $exceptionCaught = true;
        expect($e->getMessage())->toBe('Temporary failure');
    }
    expect($exceptionCaught)->toBeTrue();
    expect($observer->attempts)->toBe(1);

    // Second attempt should succeed
    $user = User::create(['name' => 'Retry Test', 'email' => 'retry@example.com']);
    expect($user->exists)->toBeTrue();
    expect($user->processed)->toBeTrue();
    expect($observer->attempts)->toBe(2);
});

test('observer performance with many listeners', function () {
    // Register 50 lightweight observers
    for ($i = 1; $i <= 50; $i++) {
        User::creating(function ($model) use ($i) {
            $model->setAttribute("observer_{$i}_processed", true);
        });
    }

    $startTime = microtime(true);
    $startMemory = memory_get_usage();

    $user = User::create(['name' => 'Performance Test', 'email' => 'perf@example.com']);

    $executionTime = microtime(true) - $startTime;
    $memoryUsed = (memory_get_usage() - $startMemory) / 1024 / 1024; // MB

    // Verify all observers ran
    for ($i = 1; $i <= 50; $i++) {
        expect($user->getAttribute("observer_{$i}_processed"))->toBeTrue();
    }

    // Performance assertions
    expect($executionTime)->toBeLessThan(1); // Should complete within 1 second
    expect($memoryUsed)->toBeLessThan(10); // Should use less than 10MB
});
