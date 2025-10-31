<?php

use Tests\Models\User;
use Tests\TestCase\GraphTestCase;

class WhereClauseTest extends GraphTestCase
{
    public function test_user_can_filter_with_where_equals()
    {
        User::create(['name' => 'John', 'age' => 25]);
        User::create(['name' => 'Jane', 'age' => 30]);

        $users = User::where('age', 25)->get();

        $this->assertCount(1, $users);
        $this->assertEquals('John', $users->first()->name);
    }

    public function test_user_can_filter_with_where_three_params()
    {
        User::create(['name' => 'John', 'age' => 25]);
        User::create(['name' => 'Jane', 'age' => 30]);

        $users = User::where('age', '=', 25)->get();

        $this->assertCount(1, $users);
        $this->assertEquals('John', $users->first()->name);
    }

    public function test_user_can_filter_with_operators()
    {
        User::create(['name' => 'Young', 'age' => 20]);
        User::create(['name' => 'Old', 'age' => 40]);

        $users = User::where('age', '>', 30)->get();

        $this->assertCount(1, $users);
        $this->assertEquals('Old', $users->first()->name);
    }

    public function test_user_can_filter_with_less_than()
    {
        User::create(['name' => 'Young', 'age' => 20]);
        User::create(['name' => 'Old', 'age' => 40]);

        $users = User::where('age', '<', 30)->get();

        $this->assertCount(1, $users);
        $this->assertEquals('Young', $users->first()->name);
    }

    public function test_user_can_filter_with_greater_than_or_equal()
    {
        User::create(['name' => 'A', 'age' => 30]);
        User::create(['name' => 'B', 'age' => 40]);
        User::create(['name' => 'C', 'age' => 20]);

        $users = User::where('age', '>=', 30)->get();

        $this->assertCount(2, $users);
    }

    public function test_user_can_filter_with_less_than_or_equal()
    {
        User::create(['name' => 'A', 'age' => 30]);
        User::create(['name' => 'B', 'age' => 40]);
        User::create(['name' => 'C', 'age' => 20]);

        $users = User::where('age', '<=', 30)->get();

        $this->assertCount(2, $users);
    }

    public function test_user_can_filter_with_not_equals()
    {
        User::create(['name' => 'John', 'age' => 25]);
        User::create(['name' => 'Jane', 'age' => 30]);

        $users = User::where('age', '!=', 25)->get();

        $this->assertCount(1, $users);
        $this->assertEquals('Jane', $users->first()->name);
    }

    public function test_user_can_filter_with_not_equals_alternative()
    {
        User::create(['name' => 'John', 'age' => 25]);
        User::create(['name' => 'Jane', 'age' => 30]);

        $users = User::where('age', '<>', 25)->get();

        $this->assertCount(1, $users);
        $this->assertEquals('Jane', $users->first()->name);
    }

    public function test_user_can_use_where_in()
    {
        User::create(['status' => 'active']);
        User::create(['status' => 'pending']);
        User::create(['status' => 'inactive']);

        $users = User::whereIn('status', ['active', 'pending'])->get();

        $this->assertCount(2, $users);
    }

    public function test_user_can_chain_multiple_where_clauses()
    {
        User::create(['name' => 'John', 'age' => 25, 'status' => 'active']);
        User::create(['name' => 'Jane', 'age' => 30, 'status' => 'active']);
        User::create(['name' => 'Bob', 'age' => 25, 'status' => 'inactive']);

        $users = User::where('age', 25)->where('status', 'active')->get();

        $this->assertCount(1, $users);
        $this->assertEquals('John', $users->first()->name);
    }

    public function test_user_can_use_where_null()
    {
        User::create(['name' => 'John', 'email' => null]);
        User::create(['name' => 'Jane', 'email' => 'jane@example.com']);

        $users = User::whereNull('email')->get();

        $this->assertCount(1, $users);
        $this->assertEquals('John', $users->first()->name);
    }

    public function test_user_can_use_where_not_null()
    {
        User::create(['name' => 'John', 'email' => null]);
        User::create(['name' => 'Jane', 'email' => 'jane@example.com']);

        $users = User::whereNotNull('email')->get();

        $this->assertCount(1, $users);
        $this->assertEquals('Jane', $users->first()->name);
    }

    public function test_user_can_use_where_between()
    {
        User::create(['name' => 'Young', 'age' => 20]);
        User::create(['name' => 'Middle', 'age' => 30]);
        User::create(['name' => 'Old', 'age' => 40]);

        $users = User::whereBetween('age', [25, 35])->get();

        $this->assertCount(1, $users);
        $this->assertEquals('Middle', $users->first()->name);
    }

    public function test_user_can_use_where_not_in()
    {
        User::create(['status' => 'active']);
        User::create(['status' => 'pending']);
        User::create(['status' => 'inactive']);

        $users = User::whereNotIn('status', ['inactive', 'pending'])->get();

        $this->assertCount(1, $users);
        $this->assertEquals('active', $users->first()->status);
    }

    public function test_where_returns_empty_collection_when_no_matches()
    {
        User::create(['name' => 'John', 'age' => 25]);

        $users = User::where('age', 100)->get();

        $this->assertCount(0, $users);
        $this->assertTrue($users->isEmpty());
    }
}
