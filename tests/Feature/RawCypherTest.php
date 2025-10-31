<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Models\Post;
use Tests\Models\User;
use Tests\TestCase\GraphTestCase;

class RawCypherTest extends GraphTestCase
{
    public function test_can_use_raw_cypher_with_db_raw()
    {
        // Create test data
        $user = User::create(['name' => 'John', 'email' => 'john@example.com']);
        $post = $user->posts()->create(['title' => 'Test Post']);

        // Get prefixed label for users
        $usersLabel = (new User)->getTable();

        // Use DB::raw() equivalent for Cypher with prefixed label
        $cypher = "MATCH (u:`{$usersLabel}` {name: \$name}) RETURN u";
        $results = DB::connection('graph')->cypher($cypher, ['name' => 'John']);

        $this->assertNotEmpty($results);
        $this->assertEquals('John', $results[0]['u']['name']);
    }

    public function test_can_use_where_raw_with_cypher()
    {
        // Create test data
        User::create(['name' => 'John', 'age' => 25]);
        User::create(['name' => 'Jane', 'age' => 35]);
        User::create(['name' => 'Bob', 'age' => 30]);

        // Use whereRaw with Cypher expression
        $users = User::whereRaw('n.age > 28 AND n.age < 40')->get();

        $this->assertCount(2, $users); // Jane (35) and Bob (30)
        $names = $users->pluck('name')->toArray();
        $this->assertContains('Jane', $names);
        $this->assertContains('Bob', $names);
    }

    public function test_can_use_select_raw_for_custom_projections()
    {
        // Create test data
        $user1 = User::create(['name' => 'John', 'age' => 25]);
        $user2 = User::create(['name' => 'Jane', 'age' => 35]);

        $user1->posts()->createMany([
            ['title' => 'Post 1'],
            ['title' => 'Post 2'],
        ]);

        $user2->posts()->create(['title' => 'Jane Post']);

        // Use selectRaw for custom projections
        $results = User::selectRaw([
            'n.name as user_name',
            'n.age * 2 as double_age',
        ])->get();

        $this->assertCount(2, $results);

        $john = $results->firstWhere('user_name', 'John');
        $this->assertEquals(50, $john->double_age); // 25 * 2

        $jane = $results->firstWhere('user_name', 'Jane');
        $this->assertEquals(70, $jane->double_age); // 35 * 2
    }

    public function test_can_execute_complex_cypher_queries()
    {
        // Create network of users and posts
        $user1 = User::create(['name' => 'Alice']);
        $user2 = User::create(['name' => 'Bob']);
        $user3 = User::create(['name' => 'Charlie']);

        $post1 = $user1->posts()->create(['title' => 'Alice Post', 'views' => 100]);
        $post2 = $user2->posts()->create(['title' => 'Bob Post', 'views' => 200]);
        $post3 = $user3->posts()->create(['title' => 'Charlie Post', 'views' => 50]);

        // Get prefixed labels
        $usersLabel = (new User)->getTable();
        $postsLabel = (new Post)->getTable();

        // Complex query: Find users ordered by total post views using foreign keys
        $cypher = "
            MATCH (u:`{$usersLabel}`), (p:`{$postsLabel}`)
            WHERE p.user_id = u.id
            WITH u, sum(p.views) as total_views
            WHERE total_views > 75
            RETURN u.name as name, total_views
            ORDER BY total_views DESC
        ";

        $results = DB::connection('graph')->cypher($cypher);

        $this->assertCount(2, $results); // Alice (100) and Bob (200), Charlie (50) excluded
        $this->assertEquals('Bob', $results[0]['name']); // Highest views first
        $this->assertEquals(200, $results[0]['total_views']);
        $this->assertEquals('Alice', $results[1]['name']);
        $this->assertEquals(100, $results[1]['total_views']);
    }

    public function test_can_combine_eloquent_with_raw_cypher()
    {
        // Create test data
        $user = User::create(['name' => 'John', 'status' => 'active']);
        $user->posts()->createMany([
            ['title' => 'Post 1', 'published' => true],
            ['title' => 'Draft Post', 'published' => false],
        ]);

        // Get prefixed label for posts
        $postsLabel = (new Post)->getTable();

        // Combine Eloquent with raw Cypher using foreign key relationships
        $users = User::where('status', 'active')
            ->whereRaw("exists { MATCH (p:`{$postsLabel}`) WHERE p.published = true AND p.user_id = n.id }")
            ->get();

        $this->assertCount(1, $users);
        $this->assertEquals('John', $users[0]->name);
    }

    public function test_can_use_raw_cypher_in_relationship_queries()
    {
        // Create test data
        $user = User::create(['name' => 'John']);
        $user->posts()->createMany([
            ['title' => 'Popular Post', 'views' => 1000],
            ['title' => 'Regular Post', 'views' => 100],
        ]);

        // Use raw Cypher in relationship queries
        $users = User::whereHas('posts', function ($query) {
            $query->whereRaw('n.views > 500');
        })->get();

        $this->assertCount(1, $users);
        $this->assertEquals('John', $users[0]->name);
    }

    public function test_can_use_cypher_functions_in_select()
    {
        // Create test data
        User::create(['name' => 'john doe', 'created_at' => '2023-01-15']);
        User::create(['name' => 'jane smith', 'created_at' => '2023-06-20']);

        // Use Cypher functions in select
        $users = User::selectRaw([
            'n.name as name',  // Add alias to make it accessible as 'name'
            'upper(n.name) as name_upper',
            'split(n.name, " ") as name_parts',
        ])->get();

        $this->assertCount(2, $users);

        $john = $users->firstWhere('name', 'john doe');
        $this->assertEquals('JOHN DOE', $john->name_upper);
        $this->assertEquals(['john', 'doe'], $john->name_parts);
    }
}
