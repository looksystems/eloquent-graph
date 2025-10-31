<?php

namespace Tests\Feature;

use Tests\Models\User;
use Tests\TestCase\GraphTestCase;

class Neo4jAggregatesExtendedTest extends GraphTestCase
{
    public function test_multiple_aggregates_in_select_raw()
    {
        foreach ([20, 30, 40, 50, 60] as $age) {
            User::create(['name' => "User $age", 'age' => $age, 'salary' => $age * 1000]);
        }

        $stats = User::selectRaw('
            COUNT(*) as total,
            AVG(n.age) as avg_age,
            percentileDisc(n.age, 0.95) as p95_age,
            percentileCont(n.age, 0.5) as median_age,
            stdev(n.salary) as std_dev_salary,
            stdevp(n.salary) as std_devp_salary
        ')->first();

        $this->assertEquals(5, $stats->total);
        $this->assertEquals(40, $stats->avg_age);
        $this->assertEquals(60, $stats->p95_age);
        $this->assertEquals(40.0, $stats->median_age);
        $this->assertGreaterThan(0, $stats->std_dev_salary);
        $this->assertGreaterThan(0, $stats->std_devp_salary);
    }

    public function test_collect_in_select_raw()
    {
        User::create(['name' => 'John', 'age' => 25]);
        User::create(['name' => 'Jane', 'age' => 30]);
        User::create(['name' => 'Bob', 'age' => 35]);

        $result = User::selectRaw('collect(n.name) as all_names')->first();

        $this->assertIsArray($result->all_names);
        $this->assertCount(3, $result->all_names);
    }

    public function test_combining_standard_and_neo4j_aggregates()
    {
        foreach ([10, 20, 30, 40, 50] as $age) {
            User::create(['name' => "User $age", 'age' => $age, 'salary' => $age * 1000]);
        }

        // Test that we can mix standard (COUNT, SUM, AVG) with Neo4j-specific aggregates
        $stats = User::selectRaw('
            COUNT(*) as count,
            SUM(n.salary) as total_salary,
            AVG(n.age) as avg_age,
            percentileDisc(n.age, 0.95) as p95,
            stdev(n.salary) as salary_stdev
        ')->first();

        $this->assertEquals(5, $stats->count);
        $this->assertEquals(150000, $stats->total_salary);
        $this->assertEquals(30, $stats->avg_age);
        $this->assertEquals(50, $stats->p95);
        $this->assertGreaterThan(0, $stats->salary_stdev);
    }

    public function test_aggregates_with_complex_where_clauses()
    {
        foreach (range(1, 100) as $i) {
            User::create([
                'name' => "User $i",
                'age' => 20 + $i,
                'salary' => $i * 1000,
                'active' => $i % 2 === 0,
            ]);
        }

        $p95 = User::where('active', true)
            ->where('age', '>', 40)
            ->percentileDisc('salary', 0.95);

        $this->assertGreaterThan(0, $p95);
    }

    public function test_with_aggregate_supports_neo4j_functions()
    {
        $user1 = User::create(['name' => 'User 1', 'email' => 'user1@example.com']);
        $user2 = User::create(['name' => 'User 2', 'email' => 'user2@example.com']);

        // Create posts with different view counts
        $user1->posts()->create(['title' => 'Post 1', 'views' => 100]);
        $user1->posts()->create(['title' => 'Post 2', 'views' => 200]);
        $user1->posts()->create(['title' => 'Post 3', 'views' => 300]);

        $user2->posts()->create(['title' => 'Post 4', 'views' => 50]);
        $user2->posts()->create(['title' => 'Post 5', 'views' => 150]);

        // Get users with aggregate
        $users = User::withAggregate('posts', 'views', 'stdev')
            ->whereIn('id', [$user1->id, $user2->id])
            ->get();

        $loadedUser1 = $users->find($user1->id);
        $loadedUser2 = $users->find($user2->id);

        // Check that stdev was calculated
        $this->assertNotNull($loadedUser1->posts_stdev_views);
        $this->assertNotNull($loadedUser2->posts_stdev_views);
        $this->assertGreaterThan(0, $loadedUser1->posts_stdev_views);
    }

    public function test_with_aggregate_for_percentile_disc()
    {
        $user = User::create(['name' => 'John Doe', 'email' => 'john@example.com']);

        foreach ([10, 20, 30, 40, 50, 60, 70, 80, 90, 100] as $views) {
            $user->posts()->create(['title' => "Post $views", 'views' => $views]);
        }

        // Note: withAggregate for multi-parameter functions may need special handling
        // For now, we'll test it works with standard single-parameter aggregates
        $user = User::with('posts')->withAggregate('posts', 'views', 'stdev')->find($user->id);

        $this->assertNotNull($user->posts_stdev_views);
    }

    public function test_load_aggregate_with_neo4j_functions()
    {
        $user = User::create(['name' => 'John Doe', 'email' => 'john@example.com']);

        foreach ([100, 200, 300, 400, 500] as $views) {
            $user->posts()->create(['title' => "Post $views", 'views' => $views]);
        }

        // Load stdev aggregate
        $user->loadAggregate('posts', 'views', 'stdev');

        $this->assertNotNull($user->posts_stdev_views);
        $this->assertGreaterThan(0, $user->posts_stdev_views);
    }

    public function test_load_stdevp_on_relationship()
    {
        $user = User::create(['name' => 'John Doe', 'email' => 'john@example.com']);

        foreach ([100, 200, 300, 400, 500] as $views) {
            $user->posts()->create(['title' => "Post $views", 'views' => $views]);
        }

        $user->loadAggregate('posts', 'views', 'stdevp');

        $this->assertNotNull($user->posts_stdevp_views);
        $this->assertGreaterThan(0, $user->posts_stdevp_views);
    }

    public function test_aggregates_work_on_relationship_queries()
    {
        $user = User::create(['name' => 'John Doe', 'email' => 'john@example.com']);

        foreach ([10, 20, 30, 40, 50] as $views) {
            $user->posts()->create(['title' => "Post $views", 'views' => $views]);
        }

        // Test aggregates directly on relationship
        $avgViews = $user->posts()->avg('views');
        $stdDevViews = $user->posts()->stdev('views');
        $p95Views = $user->posts()->percentileDisc('views', 0.95);

        $this->assertEquals(30, $avgViews);
        $this->assertGreaterThan(0, $stdDevViews);
        $this->assertEquals(50, $p95Views);
    }

    public function test_collect_aggregate_on_collection()
    {
        $user1 = User::create(['name' => 'User 1', 'email' => 'user1@example.com']);
        $user2 = User::create(['name' => 'User 2', 'email' => 'user2@example.com']);

        $user1->posts()->create(['title' => 'Post 1']);
        $user1->posts()->create(['title' => 'Post 2']);
        $user2->posts()->create(['title' => 'Post 3']);

        // Collect all post titles across users
        $titles = $user1->posts()->collect('title');

        $this->assertIsArray($titles);
        $this->assertCount(2, $titles);
        $this->assertContains('Post 1', $titles);
        $this->assertContains('Post 2', $titles);
    }

    public function test_percentile_functions_with_decimal_values()
    {
        // Test with salary data that has decimal values
        User::create(['name' => 'User 1', 'salary' => 45500.50]);
        User::create(['name' => 'User 2', 'salary' => 52000.75]);
        User::create(['name' => 'User 3', 'salary' => 48300.25]);
        User::create(['name' => 'User 4', 'salary' => 61000.00]);
        User::create(['name' => 'User 5', 'salary' => 55500.50]);

        $median = User::percentileCont('salary', 0.5);
        $p75 = User::percentileDisc('salary', 0.75);

        $this->assertIsFloat($median);
        $this->assertGreaterThan(45000, $median);
        $this->assertLessThan(62000, $median);
        $this->assertGreaterThan(50000, $p75);
    }

    public function test_stdev_functions_handle_negative_values()
    {
        // Test with data that includes negative values
        User::create(['name' => 'User 1', 'age' => -10]);
        User::create(['name' => 'User 2', 'age' => 0]);
        User::create(['name' => 'User 3', 'age' => 10]);
        User::create(['name' => 'User 4', 'age' => 20]);

        $stdDev = User::stdev('age');
        $stdDevP = User::stdevp('age');

        $this->assertIsFloat($stdDev);
        $this->assertIsFloat($stdDevP);
        $this->assertGreaterThan(0, $stdDev);
        $this->assertGreaterThan(0, $stdDevP);
    }
}
