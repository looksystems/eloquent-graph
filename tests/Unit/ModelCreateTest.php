<?php

use Tests\Models\User;

// Unit tests for create operations - focusing on model state and method signatures

test('model has correct initial state', function () {
    $user = new User;

    expect($user->exists)->toBeFalse();
    expect($user->wasRecentlyCreated)->toBeFalse();
    expect($user->getKey())->toBeNull();
});

test('model accepts fillable attributes', function () {
    $user = new User;
    $user->fill([
        'name' => 'John',
        'email' => 'john@example.com',
        'age' => 30,
    ]);

    expect($user->name)->toBe('John');
    expect($user->email)->toBe('john@example.com');
    expect($user->age)->toBe(30);
});

test('model respects fillable property', function () {
    $user = new User;
    $user->fill([
        'name' => 'John',
        'non_fillable' => 'value',
    ]);

    expect($user->name)->toBe('John');
    expect($user->non_fillable)->toBeNull();
});

test('model tracks dirty attributes', function () {
    $user = new User;
    $user->name = 'John';
    $user->email = 'john@example.com';

    expect($user->isDirty())->toBeTrue();
    expect($user->isDirty('name'))->toBeTrue();
    expect($user->isDirty('email'))->toBeTrue();
    expect($user->getDirty())->toBe([
        'name' => 'John',
        'email' => 'john@example.com',
    ]);
});
