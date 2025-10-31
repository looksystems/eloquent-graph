<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Tests\Models\Post;
use Tests\Models\User;

test('whereDate filters records by date', function () {
    // Create users with different created_at dates
    $yesterday = Carbon::yesterday();
    $today = Carbon::today();
    $tomorrow = Carbon::tomorrow();

    User::create(['name' => 'Yesterday User', 'created_at' => $yesterday]);
    User::create(['name' => 'Today User 1', 'created_at' => $today->copy()->setTime(10, 0, 0)]);
    User::create(['name' => 'Today User 2', 'created_at' => $today->copy()->setTime(15, 30, 0)]);
    User::create(['name' => 'Tomorrow User', 'created_at' => $tomorrow]);

    // Query users created today (regardless of time)
    $todayUsers = User::whereDate('created_at', $today)->get();

    expect($todayUsers)->toHaveCount(2);
    expect($todayUsers->pluck('name')->toArray())->toContain('Today User 1', 'Today User 2');
});

test('whereDate works with operators', function () {
    $dates = [
        Carbon::create(2024, 1, 15),
        Carbon::create(2024, 2, 20),
        Carbon::create(2024, 3, 25),
    ];

    foreach ($dates as $index => $date) {
        User::create(['name' => "User $index", 'created_at' => $date]);
    }

    // Greater than date
    $afterFeb = User::whereDate('created_at', '>', '2024-02-01')->count();

    // Less than or equal to date
    $beforeMarch = User::whereDate('created_at', '<=', '2024-02-28')->count();

    // Between dates (using two whereDate clauses)
    $inFeb = User::whereDate('created_at', '>=', '2024-02-01')
        ->whereDate('created_at', '<=', '2024-02-28')
        ->count();

    expect($afterFeb)->toBe(2);
    expect($beforeMarch)->toBe(2);
    expect($inFeb)->toBe(1);
});

test('whereMonth filters records by month', function () {
    // Create users in different months
    User::create(['name' => 'January User', 'created_at' => Carbon::create(2024, 1, 15)]);
    User::create(['name' => 'February User 1', 'created_at' => Carbon::create(2024, 2, 10)]);
    User::create(['name' => 'February User 2', 'created_at' => Carbon::create(2024, 2, 20)]);
    User::create(['name' => 'March User', 'created_at' => Carbon::create(2024, 3, 5)]);

    // Query users created in February
    $februaryUsers = User::whereMonth('created_at', 2)->get();

    expect($februaryUsers)->toHaveCount(2);
    expect($februaryUsers->pluck('name')->toArray())->toContain('February User 1', 'February User 2');
});

test('whereMonth works with operators', function () {
    $dates = [
        Carbon::create(2024, 3, 15),
        Carbon::create(2024, 6, 20),
        Carbon::create(2024, 9, 25),
        Carbon::create(2024, 12, 30),
    ];

    foreach ($dates as $index => $date) {
        User::create(['name' => "User $index", 'created_at' => $date]);
    }

    // Months greater than 6 (July-December)
    $secondHalf = User::whereMonth('created_at', '>', 6)->count();

    // Months less than or equal to 6
    $firstHalf = User::whereMonth('created_at', '<=', 6)->count();

    expect($secondHalf)->toBe(2);
    expect($firstHalf)->toBe(2);
});

test('whereYear filters records by year', function () {
    // Create users in different years
    User::create(['name' => 'User 2022', 'created_at' => Carbon::create(2022, 6, 15)]);
    User::create(['name' => 'User 2023-1', 'created_at' => Carbon::create(2023, 3, 10)]);
    User::create(['name' => 'User 2023-2', 'created_at' => Carbon::create(2023, 9, 20)]);
    User::create(['name' => 'User 2024', 'created_at' => Carbon::create(2024, 1, 5)]);

    // Query users created in 2023
    $users2023 = User::whereYear('created_at', 2023)->get();

    expect($users2023)->toHaveCount(2);
    expect($users2023->pluck('name')->toArray())->toContain('User 2023-1', 'User 2023-2');
});

test('whereYear works with operators', function () {
    $years = [2020, 2021, 2022, 2023, 2024];

    foreach ($years as $year) {
        User::create(['name' => "User $year", 'created_at' => Carbon::create($year, 6, 15)]);
    }

    // Years greater than or equal to 2022
    $recent = User::whereYear('created_at', '>=', 2022)->count();

    // Years before 2023
    $older = User::whereYear('created_at', '<', 2023)->count();

    expect($recent)->toBe(3);
    expect($older)->toBe(3);
});

test('whereTime filters records by time', function () {
    $baseDate = Carbon::today();

    // Create users at different times
    User::create(['name' => 'Morning User', 'created_at' => $baseDate->copy()->setTime(8, 30, 0)]);
    User::create(['name' => 'Noon User', 'created_at' => $baseDate->copy()->setTime(12, 0, 0)]);
    User::create(['name' => 'Afternoon User', 'created_at' => $baseDate->copy()->setTime(15, 45, 0)]);
    User::create(['name' => 'Evening User', 'created_at' => $baseDate->copy()->setTime(20, 15, 0)]);

    // Query users created at noon
    $noonUsers = User::whereTime('created_at', '12:00:00')->get();

    // Query users created after 3 PM
    $afternoonUsers = User::whereTime('created_at', '>', '15:00:00')->get();

    expect($noonUsers)->toHaveCount(1);
    expect($noonUsers->first()->name)->toBe('Noon User');
    expect($afternoonUsers)->toHaveCount(2);
});

test('whereTime works with operators', function () {
    $baseDate = Carbon::today();

    $times = [
        ['name' => 'Early', 'time' => [6, 0, 0]],
        ['name' => 'Morning', 'time' => [9, 30, 0]],
        ['name' => 'Lunch', 'time' => [12, 30, 0]],
        ['name' => 'Afternoon', 'time' => [16, 0, 0]],
        ['name' => 'Evening', 'time' => [19, 45, 0]],
    ];

    foreach ($times as $data) {
        User::create([
            'name' => $data['name'],
            'created_at' => $baseDate->copy()->setTime(...$data['time']),
        ]);
    }

    // Before noon
    $morning = User::whereTime('created_at', '<', '12:00:00')->count();

    // Between noon and 6 PM
    $afternoon = User::whereTime('created_at', '>=', '12:00:00')
        ->whereTime('created_at', '<=', '18:00:00')
        ->count();

    expect($morning)->toBe(2);
    expect($afternoon)->toBe(2);
});

test('combining date and time where clauses', function () {
    // Create users with specific date-time combinations
    $date1 = Carbon::create(2024, 3, 15, 10, 30, 0);
    $date2 = Carbon::create(2024, 3, 15, 14, 45, 0);
    $date3 = Carbon::create(2024, 3, 20, 10, 30, 0);
    $date4 = Carbon::create(2024, 4, 15, 10, 30, 0);

    User::create(['name' => 'User 1', 'created_at' => $date1]);
    User::create(['name' => 'User 2', 'created_at' => $date2]);
    User::create(['name' => 'User 3', 'created_at' => $date3]);
    User::create(['name' => 'User 4', 'created_at' => $date4]);

    // March 15th in the morning (before noon)
    $march15Morning = User::whereDate('created_at', '2024-03-15')
        ->whereTime('created_at', '<', '12:00:00')
        ->count();

    // March users at 10:30 AM
    $march1030 = User::whereMonth('created_at', 3)
        ->whereTime('created_at', '10:30:00')
        ->count();

    // 2024 users in March
    $users2024March = User::whereYear('created_at', 2024)
        ->whereMonth('created_at', 3)
        ->count();

    expect($march15Morning)->toBe(1);
    expect($march1030)->toBe(2);
    expect($users2024March)->toBe(3);
});

test('date time queries work with null values', function () {
    User::create(['name' => 'User with date', 'created_at' => Carbon::now()]);
    User::create(['name' => 'User without date', 'created_at' => null]);

    $withDate = User::whereNotNull('created_at')->count();
    $nullDate = User::whereNull('created_at')->count();

    expect($withDate)->toBe(1);
    expect($nullDate)->toBe(1);
});

test('date time queries work with different date formats', function () {
    $date = Carbon::create(2024, 3, 15, 14, 30, 0);
    User::create(['name' => 'Test User', 'created_at' => $date]);

    // Different date format strings
    $count1 = User::whereDate('created_at', '2024-03-15')->count();
    $count2 = User::whereDate('created_at', Carbon::create(2024, 3, 15))->count();
    $count3 = User::whereDate('created_at', '=', '2024-03-15')->count();

    expect($count1)->toBe(1);
    expect($count2)->toBe(1);
    expect($count3)->toBe(1);
});

test('date time queries with relationships', function () {
    $user = User::create(['name' => 'John', 'created_at' => Carbon::create(2024, 3, 15)]);

    Post::create([
        'title' => 'March Post',
        'user_id' => $user->id,
        'published_at' => Carbon::create(2024, 3, 20, 10, 0, 0),
    ]);

    Post::create([
        'title' => 'April Post',
        'user_id' => $user->id,
        'published_at' => Carbon::create(2024, 4, 10, 15, 0, 0),
    ]);

    // Posts published in March
    $marchPosts = Post::whereMonth('published_at', 3)->count();

    // Posts published in the morning
    $morningPosts = Post::whereTime('published_at', '<', '12:00:00')->count();

    // Posts for users created in March
    $postsFromMarchUsers = Post::whereHas('user', function ($query) {
        $query->whereMonth('created_at', 3);
    })->count();

    expect($marchPosts)->toBe(1);
    expect($morningPosts)->toBe(1);
    expect($postsFromMarchUsers)->toBe(2);
});
