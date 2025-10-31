<?php

namespace Tests\Feature;

use Tests\Models\User;
use Tests\TestCase\GraphTestCase;

class Neo4jAggregatesTest extends GraphTestCase
{
    public function test_percentile_disc_returns_discrete_percentile_value()
    {
        // Create users with ages 20, 30, 40, 50, 60
        foreach ([20, 30, 40, 50, 60] as $age) {
            User::create(['name' => "User $age", 'age' => $age]);
        }

        $p95 = User::percentileDisc('age', 0.95);

        // 95th percentile of [20, 30, 40, 50, 60] should be 60
        $this->assertEquals(60, $p95);
    }

    public function test_percentile_disc_with_median()
    {
        foreach ([10, 20, 30, 40, 50] as $age) {
            User::create(['name' => "User $age", 'age' => $age]);
        }

        $median = User::percentileDisc('age', 0.5);

        // 50th percentile (median) of [10, 20, 30, 40, 50] should be 30
        $this->assertEquals(30, $median);
    }

    public function test_percentile_disc_with_where_clause()
    {
        foreach ([20, 30, 40, 50, 60] as $age) {
            User::create(['name' => "User $age", 'age' => $age, 'active' => $age > 30]);
        }

        $p95 = User::where('active', true)->percentileDisc('age', 0.95);

        // 95th percentile of [40, 50, 60] should be 60
        $this->assertGreaterThanOrEqual(50, $p95);
    }

    public function test_percentile_cont_returns_interpolated_percentile()
    {
        foreach ([20, 30, 40, 50, 60] as $age) {
            User::create(['name' => "User $age", 'age' => $age]);
        }

        $median = User::percentileCont('age', 0.5);

        // Continuous 50th percentile (median) of [20, 30, 40, 50, 60]
        $this->assertEquals(40.0, $median);
    }

    public function test_percentile_cont_with_interpolation()
    {
        foreach ([10, 20, 30, 40, 50] as $age) {
            User::create(['name' => "User $age", 'age' => $age]);
        }

        $p25 = User::percentileCont('age', 0.25);

        // 25th percentile should be interpolated value
        $this->assertGreaterThan(10, $p25);
        $this->assertLessThan(30, $p25);
    }

    public function test_percentile_cont_with_where_clause()
    {
        foreach ([20, 30, 40, 50, 60] as $age) {
            User::create(['name' => "User $age", 'age' => $age, 'active' => $age >= 40]);
        }

        $median = User::where('active', true)->percentileCont('age', 0.5);

        // Median of [40, 50, 60] should be 50
        $this->assertEquals(50.0, $median);
    }

    public function test_stdev_returns_sample_standard_deviation()
    {
        foreach ([10, 20, 30, 40, 50] as $age) {
            User::create(['name' => "User $age", 'age' => $age]);
        }

        $stdDev = User::stdev('age');

        // Sample standard deviation of [10, 20, 30, 40, 50] ≈ 15.81
        $this->assertEqualsWithDelta(15.81, $stdDev, 0.1);
    }

    public function test_stdev_with_where_clause()
    {
        foreach ([10, 20, 30, 40, 50, 100] as $age) {
            User::create(['name' => "User $age", 'age' => $age, 'active' => $age <= 50]);
        }

        $stdDev = User::where('active', true)->stdev('age');

        // Standard deviation of [10, 20, 30, 40, 50]
        $this->assertEqualsWithDelta(15.81, $stdDev, 0.1);
    }

    public function test_stdev_returns_float()
    {
        User::create(['name' => 'User 1', 'age' => 25]);
        User::create(['name' => 'User 2', 'age' => 35]);

        $stdDev = User::stdev('age');

        $this->assertIsFloat($stdDev);
    }

    public function test_stdevp_returns_population_standard_deviation()
    {
        foreach ([10, 20, 30, 40, 50] as $age) {
            User::create(['name' => "User $age", 'age' => $age]);
        }

        $stdDevP = User::stdevp('age');

        // Population standard deviation ≈ 14.14
        $this->assertEqualsWithDelta(14.14, $stdDevP, 0.1);
    }

    public function test_stdevp_with_where_clause()
    {
        foreach ([10, 20, 30, 40, 50, 100] as $salary) {
            User::create([
                'name' => "User $salary",
                'salary' => $salary * 1000,
                'active' => $salary <= 50,
            ]);
        }

        $stdDevP = User::where('active', true)->stdevp('salary');

        // Population standard deviation of [10000, 20000, 30000, 40000, 50000]
        $this->assertGreaterThan(0, $stdDevP);
        $this->assertIsFloat($stdDevP);
    }

    public function test_collect_returns_array_of_values()
    {
        User::create(['name' => 'John', 'email' => 'john@example.com']);
        User::create(['name' => 'Jane', 'email' => 'jane@example.com']);
        User::create(['name' => 'Bob', 'email' => 'bob@example.com']);

        $names = User::collect('name');

        $this->assertIsArray($names);
        $this->assertCount(3, $names);
        $this->assertContains('John', $names);
        $this->assertContains('Jane', $names);
        $this->assertContains('Bob', $names);
    }

    public function test_collect_with_where_clause()
    {
        User::create(['name' => 'John', 'age' => 25]);
        User::create(['name' => 'Jane', 'age' => 35]);
        User::create(['name' => 'Bob', 'age' => 45]);

        $names = User::where('age', '>', 30)->collect('name');

        $this->assertIsArray($names);
        $this->assertCount(2, $names);
        $this->assertContains('Jane', $names);
        $this->assertContains('Bob', $names);
        $this->assertNotContains('John', $names);
    }

    public function test_collect_returns_empty_array_for_empty_result()
    {
        $names = User::where('age', '>', 1000)->collect('name');

        $this->assertIsArray($names);
        $this->assertEmpty($names);
    }

    public function test_percentile_disc_returns_null_for_empty_result_set()
    {
        $p95 = User::where('age', '>', 1000)->percentileDisc('age', 0.95);

        $this->assertNull($p95);
    }

    public function test_percentile_cont_returns_null_for_empty_result_set()
    {
        $median = User::where('age', '>', 1000)->percentileCont('age', 0.5);

        $this->assertNull($median);
    }

    public function test_stdev_returns_zero_for_empty_result_set()
    {
        $stdDev = User::where('age', '>', 1000)->stdev('age');

        // Neo4j returns 0.0 for stdev on empty sets (unlike percentile which returns null)
        $this->assertEquals(0.0, $stdDev);
    }

    public function test_stdevp_returns_zero_for_empty_result_set()
    {
        $stdDevP = User::where('age', '>', 1000)->stdevp('age');

        // Neo4j returns 0.0 for stdevp on empty sets (unlike percentile which returns null)
        $this->assertEquals(0.0, $stdDevP);
    }

    public function test_percentile_disc_with_boundary_values()
    {
        foreach ([10, 20, 30, 40, 50] as $age) {
            User::create(['name' => "User $age", 'age' => $age]);
        }

        $p0 = User::percentileDisc('age', 0.0);
        $p100 = User::percentileDisc('age', 1.0);

        $this->assertEquals(10, $p0);
        $this->assertEquals(50, $p100);
    }

    public function test_percentile_cont_with_boundary_values()
    {
        foreach ([10, 20, 30, 40, 50] as $age) {
            User::create(['name' => "User $age", 'age' => $age]);
        }

        $p0 = User::percentileCont('age', 0.0);
        $p100 = User::percentileCont('age', 1.0);

        $this->assertEquals(10.0, $p0);
        $this->assertEquals(50.0, $p100);
    }

    public function test_stdev_with_single_value_returns_zero()
    {
        User::create(['name' => 'John', 'age' => 25]);

        $stdDev = User::stdev('age');

        // Standard deviation of single value should be 0
        $this->assertEquals(0, $stdDev);
    }

    public function test_stdevp_with_single_value_returns_zero()
    {
        User::create(['name' => 'John', 'age' => 25]);

        $stdDevP = User::stdevp('age');

        // Population standard deviation of single value should be 0
        $this->assertEquals(0, $stdDevP);
    }
}
