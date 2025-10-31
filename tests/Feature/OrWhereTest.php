<?php

use Tests\Models\Post;
use Tests\Models\User;
use Tests\TestCase\GraphTestCase;

class OrWhereTest extends GraphTestCase
{
    public function test_simple_or_where()
    {
        User::create(['name' => 'John', 'email' => 'john@example.com', 'age' => 25]);
        User::create(['name' => 'Jane', 'email' => 'jane@example.com', 'age' => 30]);
        User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'age' => 35]);

        $users = User::where('name', 'John')
            ->orWhere('age', 30)
            ->get();

        $this->assertCount(2, $users);
        $this->assertTrue($users->contains('name', 'John'));
        $this->assertTrue($users->contains('name', 'Jane'));
    }

    public function test_multiple_or_where_conditions()
    {
        User::create(['name' => 'John', 'email' => 'john@example.com', 'age' => 25, 'status' => 'active']);
        User::create(['name' => 'Jane', 'email' => 'jane@example.com', 'age' => 30, 'status' => 'inactive']);
        User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'age' => 35, 'status' => 'pending']);
        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'age' => 40, 'status' => 'active']);

        $users = User::where('name', 'John')
            ->orWhere('age', 30)
            ->orWhere('status', 'pending')
            ->get();

        $this->assertCount(3, $users);
        $this->assertTrue($users->contains('name', 'John'));
        $this->assertTrue($users->contains('name', 'Jane'));
        $this->assertTrue($users->contains('name', 'Bob'));
    }

    public function test_or_where_with_operators()
    {
        User::create(['name' => 'John', 'age' => 20]);
        User::create(['name' => 'Jane', 'age' => 30]);
        User::create(['name' => 'Bob', 'age' => 40]);
        User::create(['name' => 'Alice', 'age' => 50]);

        $users = User::where('age', '<', 25)
            ->orWhere('age', '>', 45)
            ->get();

        $this->assertCount(2, $users);
        $this->assertTrue($users->contains('name', 'John'));
        $this->assertTrue($users->contains('name', 'Alice'));
    }

    public function test_or_where_with_closure()
    {
        User::create(['name' => 'John', 'age' => 25, 'status' => 'active']);
        User::create(['name' => 'Jane', 'age' => 30, 'status' => 'inactive']);
        User::create(['name' => 'Bob', 'age' => 35, 'status' => 'active']);
        User::create(['name' => 'Alice', 'age' => 40, 'status' => 'pending']);

        $users = User::where('status', 'active')
            ->orWhere(function ($query) {
                $query->where('age', '>', 35)
                    ->where('status', 'pending');
            })
            ->get();

        $this->assertCount(3, $users);
        $this->assertTrue($users->contains('name', 'John'));
        $this->assertTrue($users->contains('name', 'Bob'));
        $this->assertTrue($users->contains('name', 'Alice'));
    }

    public function test_or_where_null()
    {
        User::create(['name' => 'John', 'email' => 'john@example.com']);
        User::create(['name' => 'Jane', 'email' => null]);
        User::create(['name' => 'Bob', 'email' => 'bob@example.com']);
        User::create(['name' => 'Alice', 'email' => null]);

        $users = User::where('name', 'John')
            ->orWhereNull('email')
            ->get();

        $this->assertCount(3, $users);
        $this->assertTrue($users->contains('name', 'John'));
        $this->assertTrue($users->contains('name', 'Jane'));
        $this->assertTrue($users->contains('name', 'Alice'));
    }

    public function test_or_where_not_null()
    {
        User::create(['name' => 'John', 'email' => 'john@example.com', 'age' => 25]);
        User::create(['name' => 'Jane', 'email' => null, 'age' => 30]);
        User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'age' => 35]);
        User::create(['name' => 'Alice', 'email' => null, 'age' => 40]);

        $users = User::where('age', '<', 28)
            ->orWhereNotNull('email')
            ->get();

        $this->assertCount(2, $users);
        $this->assertTrue($users->contains('name', 'John'));
        $this->assertTrue($users->contains('name', 'Bob'));
    }

    public function test_or_where_in()
    {
        User::create(['name' => 'John', 'status' => 'active']);
        User::create(['name' => 'Jane', 'status' => 'pending']);
        User::create(['name' => 'Bob', 'status' => 'inactive']);
        User::create(['name' => 'Alice', 'status' => 'blocked']);

        $users = User::where('name', 'John')
            ->orWhereIn('status', ['pending', 'inactive'])
            ->get();

        $this->assertCount(3, $users);
        $this->assertTrue($users->contains('name', 'John'));
        $this->assertTrue($users->contains('name', 'Jane'));
        $this->assertTrue($users->contains('name', 'Bob'));
    }

    public function test_or_where_not_in()
    {
        User::create(['name' => 'John', 'status' => 'active', 'age' => 25]);
        User::create(['name' => 'Jane', 'status' => 'pending', 'age' => 30]);
        User::create(['name' => 'Bob', 'status' => 'inactive', 'age' => 35]);
        User::create(['name' => 'Alice', 'status' => 'blocked', 'age' => 40]);

        $users = User::where('age', '<', 28)
            ->orWhereNotIn('status', ['inactive', 'blocked'])
            ->get();

        $this->assertCount(2, $users);
        $this->assertTrue($users->contains('name', 'John'));
        $this->assertTrue($users->contains('name', 'Jane'));
    }

    public function test_or_where_between()
    {
        User::create(['name' => 'John', 'age' => 20]);
        User::create(['name' => 'Jane', 'age' => 30]);
        User::create(['name' => 'Bob', 'age' => 40]);
        User::create(['name' => 'Alice', 'age' => 50]);

        $users = User::where('name', 'Alice')
            ->orWhereBetween('age', [25, 35])
            ->get();

        $this->assertCount(2, $users);
        $this->assertTrue($users->contains('name', 'Jane'));
        $this->assertTrue($users->contains('name', 'Alice'));
    }

    public function test_complex_and_or_combinations()
    {
        User::create(['name' => 'John', 'age' => 25, 'status' => 'active', 'email' => 'john@example.com']);
        User::create(['name' => 'Jane', 'age' => 30, 'status' => 'inactive', 'email' => 'jane@example.com']);
        User::create(['name' => 'Bob', 'age' => 35, 'status' => 'active', 'email' => null]);
        User::create(['name' => 'Alice', 'age' => 40, 'status' => 'pending', 'email' => 'alice@example.com']);

        // (status = 'active' AND email IS NOT NULL) OR (age > 35 AND status = 'pending')
        $users = User::where(function ($query) {
            $query->where('status', 'active')
                ->whereNotNull('email');
        })
            ->orWhere(function ($query) {
                $query->where('age', '>', 35)
                    ->where('status', 'pending');
            })
            ->get();

        $this->assertCount(2, $users);
        $this->assertTrue($users->contains('name', 'John'));
        $this->assertTrue($users->contains('name', 'Alice'));
    }

    public function test_or_where_column()
    {
        Post::create(['title' => 'Post 1', 'content' => 'Content', 'user_id' => 1, 'likes' => 10, 'shares_count' => 10]);
        Post::create(['title' => 'Post 2', 'content' => 'Content', 'user_id' => 2, 'likes' => 20, 'shares_count' => 15]);
        Post::create(['title' => 'Post 3', 'content' => 'Content', 'user_id' => 3, 'likes' => 30, 'shares_count' => 25]);
        Post::create(['title' => 'Post 4', 'content' => 'Content', 'user_id' => 4, 'likes' => 40, 'shares_count' => 50]);

        $posts = Post::where('title', 'Post 1')
            ->orWhereColumn('likes', 'shares_count')
            ->get();

        $this->assertCount(1, $posts);
        $this->assertTrue($posts->contains('title', 'Post 1'));
    }

    public function test_or_where_raw()
    {
        User::create(['name' => 'John', 'age' => 25]);
        User::create(['name' => 'Jane', 'age' => 30]);
        User::create(['name' => 'Bob', 'age' => 35]);
        User::create(['name' => 'Alice', 'age' => 40]);

        $users = User::where('name', 'John')
            ->orWhereRaw('n.age > 35')
            ->get();

        $this->assertCount(2, $users);
        $this->assertTrue($users->contains('name', 'John'));
        $this->assertTrue($users->contains('name', 'Alice'));
    }

    public function test_or_where_preserves_order()
    {
        User::create(['name' => 'John', 'age' => 25]);
        User::create(['name' => 'Jane', 'age' => 30]);
        User::create(['name' => 'Bob', 'age' => 35]);
        User::create(['name' => 'Alice', 'age' => 40]);

        $users = User::where('age', 25)
            ->orWhere('age', 40)
            ->orderBy('age', 'desc')
            ->get();

        $this->assertCount(2, $users);
        $this->assertEquals('Alice', $users->first()->name);
        $this->assertEquals('John', $users->last()->name);
    }

    public function test_or_where_with_select()
    {
        // Ensure clean slate - explicitly delete any leftover users
        User::query()->delete();

        User::create(['name' => 'John', 'email' => 'john@example.com', 'age' => 25]);
        User::create(['name' => 'Jane', 'email' => 'jane@example.com', 'age' => 30]);
        User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'age' => 35]);

        $users = User::select('name', 'age')
            ->where('name', 'John')
            ->orWhere('age', 35)
            ->get();

        $this->assertCount(2, $users);
        // Both users should be returned
        $names = $users->pluck('name')->toArray();
        $this->assertContains('John', $names);
        $this->assertContains('Bob', $names);

        // Check that the select worked - email should not be included
        foreach ($users as $user) {
            $this->assertNull($user->email);
        }

        // Verify the correct users match the conditions
        $john = $users->firstWhere('name', 'John');
        $this->assertEquals(25, $john->age);

        $bob = $users->firstWhere('name', 'Bob');
        $this->assertEquals(35, $bob->age);
    }
}
