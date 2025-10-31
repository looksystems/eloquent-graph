<?php

use Carbon\Carbon;
use Tests\Models\User;
use Tests\Models\UserWithCasting;

// TEST FIRST: tests/Feature/TimestampsAndCastingTest.php
// Focus: Laravel Eloquent timestamp and casting compatibility

test('model automatically sets created at timestamp', function () {
    $before = now()->subSecond();

    $user = User::create(['name' => 'John Doe', 'email' => 'john@example.com']);

    $after = now()->addSecond();

    expect($user->created_at)->not->toBeNull();
    expect($user->created_at)->toBeInstanceOf(Carbon::class);
    expect($user->created_at->between($before, $after))->toBeTrue();
});

test('model automatically sets updated at timestamp', function () {
    $before = now()->subSecond();

    $user = User::create(['name' => 'John Doe', 'email' => 'john@example.com']);

    $after = now()->addSecond();

    expect($user->updated_at)->not->toBeNull();
    expect($user->updated_at)->toBeInstanceOf(Carbon::class);
    expect($user->updated_at->between($before, $after))->toBeTrue();
});

test('model updates updated at timestamp on save', function () {
    $user = User::create(['name' => 'John Doe', 'email' => 'john@example.com']);
    $originalUpdatedAt = $user->updated_at;

    sleep(1); // Ensure timestamp difference

    $user->name = 'Jane Doe';
    $user->save();

    expect($user->updated_at)->not->toEqual($originalUpdatedAt);
    expect($user->updated_at->greaterThan($originalUpdatedAt))->toBeTrue();
});

test('model does not update created at on save', function () {
    $user = User::create(['name' => 'John Doe', 'email' => 'john@example.com']);
    $originalCreatedAt = $user->created_at;

    sleep(1);

    $user->name = 'Jane Doe';
    $user->save();

    expect($user->created_at)->toEqual($originalCreatedAt);
});

test('model can disable timestamps', function () {
    $user = new User;
    $user->timestamps = false;
    $user->name = 'John Doe';
    $user->email = 'john@example.com';
    $user->save();

    expect($user->created_at)->toBeNull();
    expect($user->updated_at)->toBeNull();
});

test('model can touch timestamps', function () {
    $user = User::create(['name' => 'John Doe', 'email' => 'john@example.com']);
    $originalUpdatedAt = $user->updated_at;

    sleep(1);

    $user->touch();

    expect($user->updated_at->greaterThan($originalUpdatedAt))->toBeTrue();
});

test('model persists timestamps to database', function () {
    $user = User::create(['name' => 'John Doe', 'email' => 'john@example.com']);

    $freshUser = User::find($user->id);

    expect($freshUser->created_at)->toEqual($user->created_at);
    expect($freshUser->updated_at)->toEqual($user->updated_at);
    expect($freshUser->created_at)->toBeInstanceOf(Carbon::class);
    expect($freshUser->updated_at)->toBeInstanceOf(Carbon::class);
});

test('model can cast attributes to array', function () {
    $settings = ['theme' => 'dark', 'notifications' => true];

    $user = UserWithCasting::create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'settings' => $settings,
    ]);

    expect($user->settings)->toEqual($settings);
    expect($user->settings)->toBeArray();

    // Test persistence
    $freshUser = UserWithCasting::find($user->id);
    expect($freshUser->settings)->toEqual($settings);
    expect($freshUser->settings)->toBeArray();
});

test('model can cast attributes to json', function () {
    $metadata = ['version' => '1.0', 'features' => ['a', 'b', 'c']];

    $user = UserWithCasting::create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'metadata' => $metadata,
    ]);

    expect($user->metadata)->toEqual($metadata);

    // Test persistence
    $freshUser = UserWithCasting::find($user->id);
    expect($freshUser->metadata)->toEqual($metadata);
});

test('model can cast attributes to boolean', function () {
    $user = UserWithCasting::create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'is_active' => '1',
    ]);

    expect($user->is_active)->toBeTrue();
    expect($user->is_active)->toBeBool();

    // Test persistence
    $freshUser = UserWithCasting::find($user->id);
    expect($freshUser->is_active)->toBeTrue();
    expect($freshUser->is_active)->toBeBool();
});

test('model can cast attributes to integer', function () {
    $user = UserWithCasting::create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'age' => '25',
    ]);

    expect($user->age)->toBe(25);
    expect($user->age)->toBeInt();

    // Test persistence
    $freshUser = UserWithCasting::find($user->id);
    expect($freshUser->age)->toBe(25);
    expect($freshUser->age)->toBeInt();
});

test('model can cast attributes to float', function () {
    $user = UserWithCasting::create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'rating' => '4.5',
    ]);

    expect($user->rating)->toBe(4.5);
    expect($user->rating)->toBeFloat();

    // Test persistence
    $freshUser = UserWithCasting::find($user->id);
    expect($freshUser->rating)->toBe(4.5);
    expect($freshUser->rating)->toBeFloat();
});

test('model can cast attributes to datetime', function () {
    $birthDate = '1990-01-15 10:30:00';

    $user = UserWithCasting::create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'birth_date' => $birthDate,
    ]);

    expect($user->birth_date)->toBeInstanceOf(Carbon::class);
    expect($user->birth_date->format('Y-m-d H:i:s'))->toBe('1990-01-15 10:30:00');

    // Test persistence
    $freshUser = UserWithCasting::find($user->id);
    expect($freshUser->birth_date)->toBeInstanceOf(Carbon::class);
    expect($freshUser->birth_date->format('Y-m-d H:i:s'))->toBe('1990-01-15 10:30:00');
});

test('model can cast attributes to date', function () {
    $joinDate = '2023-01-15';

    $user = UserWithCasting::create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'join_date' => $joinDate,
    ]);

    expect($user->join_date)->toBeInstanceOf(Carbon::class);
    expect($user->join_date->format('Y-m-d'))->toBe('2023-01-15');

    // Test persistence
    $freshUser = UserWithCasting::find($user->id);
    expect($freshUser->join_date)->toBeInstanceOf(Carbon::class);
    expect($freshUser->join_date->format('Y-m-d'))->toBe('2023-01-15');
});

test('model handles null cast values gracefully', function () {
    $user = UserWithCasting::create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'settings' => null,
        'is_active' => null,
        'age' => null,
    ]);

    expect($user->settings)->toBeNull();
    expect($user->is_active)->toBeNull();
    expect($user->age)->toBeNull();

    // Test persistence
    $freshUser = UserWithCasting::find($user->id);
    expect($freshUser->settings)->toBeNull();
    expect($freshUser->is_active)->toBeNull();
    expect($freshUser->age)->toBeNull();
});

test('model can use mutator to transform attribute', function () {
    $user = UserWithCasting::create([
        'name' => 'john doe',
        'email' => 'john@example.com',
    ]);

    // Name should be automatically title-cased by mutator
    expect($user->name)->toBe('John Doe');

    // Test persistence
    $freshUser = UserWithCasting::find($user->id);
    expect($freshUser->name)->toBe('John Doe');
});

test('model can use accessor to transform attribute', function () {
    $user = UserWithCasting::create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
    ]);

    // Email should be returned in uppercase by accessor
    expect($user->email_upper)->toBe('JOHN@EXAMPLE.COM');
});

test('model can serialize dates for json', function () {
    $user = User::create(['name' => 'John Doe', 'email' => 'john@example.com']);

    $json = $user->toJson();
    $data = json_decode($json, true);

    expect($data)->toHaveKey('created_at');
    expect($data)->toHaveKey('updated_at');
    expect($data['created_at'])->toBeString();
    expect($data['updated_at'])->toBeString();
});

test('model can specify custom date format', function () {
    $user = UserWithCasting::create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
    ]);

    $json = $user->toJson();
    $data = json_decode($json, true);

    // Should use custom date format defined in UserWithCasting
    expect($data['created_at'])->toMatch('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/');
});
