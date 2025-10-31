<?php

use Tests\Models\User;
use Tests\TestCase\GraphTestCase;

class OrderingTest extends GraphTestCase
{
    public function test_user_can_order_by_column()
    {
        User::create(['name' => 'Charlie']);
        User::create(['name' => 'Alice']);
        User::create(['name' => 'Bob']);

        $users = User::orderBy('name')->get();

        $this->assertEquals('Alice', $users[0]->name);
        $this->assertEquals('Bob', $users[1]->name);
        $this->assertEquals('Charlie', $users[2]->name);
    }

    public function test_user_can_order_by_desc()
    {
        User::create(['name' => 'Charlie']);
        User::create(['name' => 'Alice']);
        User::create(['name' => 'Bob']);

        $users = User::orderByDesc('name')->get();

        $this->assertEquals('Charlie', $users[0]->name);
        $this->assertEquals('Bob', $users[1]->name);
        $this->assertEquals('Alice', $users[2]->name);
    }

    public function test_user_can_order_by_multiple_columns()
    {
        User::create(['name' => 'Alice', 'age' => 30]);
        User::create(['name' => 'Bob', 'age' => 25]);
        User::create(['name' => 'Alice', 'age' => 25]);
        User::create(['name' => 'Bob', 'age' => 30]);

        $users = User::orderBy('name')->orderBy('age')->get();

        $this->assertEquals('Alice', $users[0]->name);
        $this->assertEquals(25, $users[0]->age);
        $this->assertEquals('Alice', $users[1]->name);
        $this->assertEquals(30, $users[1]->age);
        $this->assertEquals('Bob', $users[2]->name);
        $this->assertEquals(25, $users[2]->age);
        $this->assertEquals('Bob', $users[3]->name);
        $this->assertEquals(30, $users[3]->age);
    }

    public function test_user_can_limit_and_offset()
    {
        for ($i = 1; $i <= 5; $i++) {
            User::create(['name' => "User $i", 'age' => $i]);
        }

        $users = User::orderBy('age')->skip(2)->take(2)->get();

        $this->assertCount(2, $users);
        $this->assertEquals('User 3', $users[0]->name);
        $this->assertEquals('User 4', $users[1]->name);
    }

    public function test_user_can_use_limit()
    {
        for ($i = 1; $i <= 5; $i++) {
            User::create(['name' => "User $i", 'age' => $i]);
        }

        $users = User::orderBy('age')->limit(3)->get();

        $this->assertCount(3, $users);
        $this->assertEquals('User 1', $users[0]->name);
        $this->assertEquals('User 2', $users[1]->name);
        $this->assertEquals('User 3', $users[2]->name);
    }

    public function test_user_can_use_offset()
    {
        for ($i = 1; $i <= 5; $i++) {
            User::create(['name' => "User $i", 'age' => $i]);
        }

        $users = User::orderBy('age')->offset(3)->get();

        $this->assertCount(2, $users);
        $this->assertEquals('User 4', $users[0]->name);
        $this->assertEquals('User 5', $users[1]->name);
    }

    public function test_user_can_combine_where_with_order_by()
    {
        User::create(['name' => 'Alice', 'age' => 30]);
        User::create(['name' => 'Bob', 'age' => 25]);
        User::create(['name' => 'Charlie', 'age' => 35]);
        User::create(['name' => 'David', 'age' => 20]);

        $users = User::where('age', '>', 25)->orderBy('name')->get();

        $this->assertCount(2, $users);
        $this->assertEquals('Alice', $users[0]->name);
        $this->assertEquals('Charlie', $users[1]->name);
    }

    public function test_user_can_combine_where_with_limit()
    {
        User::create(['name' => 'Alice', 'age' => 30]);
        User::create(['name' => 'Bob', 'age' => 25]);
        User::create(['name' => 'Charlie', 'age' => 35]);
        User::create(['name' => 'David', 'age' => 40]);

        $users = User::where('age', '>=', 30)->limit(2)->get();

        $this->assertCount(2, $users);
    }

    public function test_user_can_first()
    {
        User::create(['name' => 'Alice']);
        User::create(['name' => 'Bob']);
        User::create(['name' => 'Charlie']);

        $user = User::orderBy('name')->first();

        $this->assertNotNull($user);
        $this->assertEquals('Alice', $user->name);
    }

    public function test_first_returns_null_when_no_results()
    {
        $user = User::where('name', 'NonExistent')->first();

        $this->assertNull($user);
    }
}
