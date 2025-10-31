<?php

use Illuminate\Database\Eloquent\Model;
use Tests\Models\User;

// TEST FIRST: tests/Unit/Neo4JModelTest.php
// Focus: PUBLIC API that users will interact with

test('user can instantiate model normally', function () {
    $user = new User;
    expect($user)->toBeInstanceOf(Model::class);
});

test('user can set attributes via constructor', function () {
    $user = new User(['name' => 'John', 'email' => 'john@example.com']);
    expect($user->name)->toBe('John');
    expect($user->email)->toBe('john@example.com');
});
