<?php

use Illuminate\Database\Eloquent\MassAssignmentException;
use Tests\Models\User;

// TEST SUITE: Mass Assignment Security and Protection
// Focus: Fillable/guarded attributes, mass assignment exceptions, forceFill behavior

test('fillable attributes are allowed during mass assignment', function () {
    $user = User::create([
        'name' => 'John Doe',        // in fillable
        'email' => 'john@example.com', // in fillable
        'age' => 30,                 // in fillable
        'status' => 'active',         // in fillable
    ]);

    expect($user->name)->toBe('John Doe');
    expect($user->email)->toBe('john@example.com');
    expect($user->age)->toBe(30);
    expect($user->status)->toBe('active');
});

test('non-fillable attributes are ignored during mass assignment', function () {
    $user = User::create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'non_fillable_field' => 'should_be_ignored',
        'another_non_fillable' => 'also_ignored',
    ]);

    expect($user->name)->toBe('John Doe');
    expect($user->email)->toBe('john@example.com');
    expect($user->getAttribute('non_fillable_field'))->toBeNull();
    expect($user->getAttribute('another_non_fillable'))->toBeNull();
});

test('fillable attributes work with update mass assignment', function () {
    $user = User::create([
        'name' => 'Original Name',
        'email' => 'original@example.com',
    ]);

    $user->update([
        'name' => 'Updated Name',    // fillable
        'age' => 25,                 // fillable
        'non_fillable' => 'ignored',  // not fillable
    ]);

    expect($user->name)->toBe('Updated Name');
    expect($user->age)->toBe(25);
    expect($user->getAttribute('non_fillable'))->toBeNull();
});

test('fill method respects fillable attributes', function () {
    $user = new User;
    $user->fill([
        'name' => 'Filled Name',
        'email' => 'filled@example.com',
        'age' => 35,
        'restricted_field' => 'should_not_be_set',
    ]);

    expect($user->name)->toBe('Filled Name');
    expect($user->email)->toBe('filled@example.com');
    expect($user->age)->toBe(35);
    expect($user->getAttribute('restricted_field'))->toBeNull();
});

test('forceFill bypasses fillable protection', function () {
    $user = new User;
    $user->forceFill([
        'name' => 'Force Filled',
        'email' => 'force@example.com',
        'restricted_field' => 'this_should_be_set',
        'another_restricted' => 'this_too',
    ]);

    expect($user->name)->toBe('Force Filled');
    expect($user->email)->toBe('force@example.com');
    expect($user->getAttribute('restricted_field'))->toBe('this_should_be_set');
    expect($user->getAttribute('another_restricted'))->toBe('this_too');
});

test('guarded attributes are protected during mass assignment', function () {
    // Create a test model with guarded attributes
    $model = new class extends \Look\EloquentCypher\GraphModel
    {
        protected $table = 'test_models';

        protected $guarded = ['admin_field', 'secret_key'];
    };

    $instance = new $model;
    $instance->fill([
        'name' => 'Test Name',
        'email' => 'test@example.com',
        'admin_field' => 'should_be_blocked',
        'secret_key' => 'should_also_be_blocked',
    ]);

    expect($instance->getAttribute('name'))->toBe('Test Name');
    expect($instance->getAttribute('email'))->toBe('test@example.com');
    expect($instance->getAttribute('admin_field'))->toBeNull();
    expect($instance->getAttribute('secret_key'))->toBeNull();
});

test('empty fillable array blocks all mass assignment', function () {
    // Create a test model with empty fillable (all guarded)
    $model = new class extends \Look\EloquentCypher\GraphModel
    {
        protected $table = 'restricted_models';

        protected $fillable = [];
    };

    $instance = new $model;

    // Laravel now throws MassAssignmentException by default instead of silently ignoring
    expect(function () use ($instance) {
        $instance->fill([
            'name' => 'Should be blocked',
            'email' => 'also@blocked.com',
            'any_field' => 'all blocked',
        ]);
    })->toThrow(\Illuminate\Database\Eloquent\MassAssignmentException::class);
});

test('wildcard fillable allows all attributes', function () {
    // Create a test model with empty guarded (allows all attributes)
    $model = new class extends \Look\EloquentCypher\GraphModel
    {
        protected $table = 'open_models';

        protected $guarded = []; // Empty guarded means all attributes are fillable
    };

    $instance = new $model;
    $instance->fill([
        'name' => 'Allowed',
        'email' => 'allowed@example.com',
        'any_field' => 'also_allowed',
        'custom_attribute' => 'everything_allowed',
    ]);

    expect($instance->getAttribute('name'))->toBe('Allowed');
    expect($instance->getAttribute('email'))->toBe('allowed@example.com');
    expect($instance->getAttribute('any_field'))->toBe('also_allowed');
    expect($instance->getAttribute('custom_attribute'))->toBe('everything_allowed');
});

test('mass assignment works with array and json cast attributes', function () {
    $user = User::create([
        'name' => 'Array User',
        'email' => 'array@example.com',
        'preferences' => [
            'theme' => 'dark',
            'language' => 'en',
        ],
        'metadata' => [
            'source' => 'api',
            'version' => 2,
        ],
        'tags' => ['developer', 'premium'],
    ]);

    expect($user->preferences)->toBeArray();
    expect($user->preferences['theme'])->toBe('dark');
    expect($user->metadata)->toBeArray();
    expect($user->metadata['source'])->toBe('api');
    expect($user->tags)->toBeArray();
    expect($user->tags)->toContain('developer');
});

test('individual attribute assignment bypasses fillable protection', function () {
    $user = new User;

    // Direct assignment should work regardless of fillable
    $user->name = 'Direct Name';
    $user->email = 'direct@example.com';
    $user->non_fillable_field = 'direct_assignment_works';

    expect($user->name)->toBe('Direct Name');
    expect($user->email)->toBe('direct@example.com');
    expect($user->getAttribute('non_fillable_field'))->toBe('direct_assignment_works');
});

test('dynamic fillable modification affects mass assignment', function () {
    // Use a field that's not in the User model's fillable array
    $user = new User;
    $user->fillable([]); // Start with empty fillable

    // Initially restricted
    try {
        $user->fill(['non_fillable_test_field' => 'should_be_blocked']);
        // If no exception, the field was silently ignored
        expect($user->getAttribute('non_fillable_test_field'))->toBeNull();
    } catch (\Illuminate\Database\Eloquent\MassAssignmentException $e) {
        // Exception is expected behavior
        expect(true)->toBeTrue();
    }

    // Add to fillable dynamically
    $user->fillable(['non_fillable_test_field']);
    $user->fill(['non_fillable_test_field' => 'now_allowed']);

    expect($user->getAttribute('non_fillable_test_field'))->toBe('now_allowed');
});

test('mass assignment with nested fillable arrays', function () {
    $user = User::create([
        'name' => 'Nested User',
        'email' => 'nested@example.com',
        'preferences' => [
            'ui' => [
                'theme' => 'dark',
                'sidebar' => 'collapsed',
                'animations' => true,
            ],
            'notifications' => [
                'email' => true,
                'push' => false,
                'sms' => null,
            ],
        ],
    ]);

    expect($user->preferences['ui']['theme'])->toBe('dark');
    expect($user->preferences['ui']['animations'])->toBeTrue();
    expect($user->preferences['notifications']['email'])->toBeTrue();
    expect($user->preferences['notifications']['push'])->toBeFalse();
});

test('mass assignment preserves attribute casting', function () {
    $user = User::create([
        'name' => 'Cast User',
        'email' => 'cast@example.com',
        'age' => '30',           // String that should be cast to int
        'is_active' => 'true',   // String that should be cast to bool
        'score' => '95.5',       // String that should be cast to float
        'verified' => 1,          // Integer that should be cast to bool
    ]);

    expect($user->age)->toBeInt();
    expect($user->age)->toBe(30);
    expect($user->is_active)->toBeBool();
    expect($user->is_active)->toBeTrue();
    expect($user->score)->toBeFloat();
    expect($user->score)->toBe(95.5);
    expect($user->verified)->toBeBool();
    expect($user->verified)->toBeTrue();
});

test('mass assignment with null and empty values', function () {
    $user = User::create([
        'name' => null,
        'email' => 'null@example.com',
        'age' => null,
        'preferences' => null,
        'metadata' => [],
        'tags' => null,
    ]);

    expect($user->name)->toBeNull();
    expect($user->email)->toBe('null@example.com');
    expect($user->age)->toBeNull();
    expect($user->preferences)->toBeNull();
    expect($user->metadata)->toBe([]);
    expect($user->tags)->toBeNull();
});

test('mass assignment security with deeply nested data', function () {
    $user = User::create([
        'name' => 'Deep User',
        'email' => 'deep@example.com',
        'metadata' => [
            'profile' => [
                'personal' => [
                    'first_name' => 'John',
                    'last_name' => 'Doe',
                    'age' => 30,
                ],
                'professional' => [
                    'title' => 'Developer',
                    'experience' => [
                        'years' => 5,
                        'skills' => ['PHP', 'JavaScript', 'Neo4j'],
                    ],
                ],
            ],
            'security' => [
                'last_login' => '2023-12-01',
                'failed_attempts' => 0,
            ],
        ],
    ]);

    expect($user->metadata['profile']['personal']['first_name'])->toBe('John');
    expect($user->metadata['profile']['professional']['experience']['years'])->toBe(5);
    expect($user->metadata['profile']['professional']['experience']['skills'])->toContain('Neo4j');
    expect($user->metadata['security']['failed_attempts'])->toBe(0);
});
