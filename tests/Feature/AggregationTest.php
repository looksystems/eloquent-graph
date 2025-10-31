<?php

namespace Tests\Feature;

use Tests\Models\User;
use Tests\TestCase\GraphTestCase;

class AggregationTest extends GraphTestCase
{
    public function test_user_can_sum_numeric_column()
    {
        User::create(['name' => 'John', 'age' => 25, 'salary' => 50000]);
        User::create(['name' => 'Jane', 'age' => 30, 'salary' => 60000]);
        User::create(['name' => 'Bob', 'age' => 35, 'salary' => 70000]);

        $totalSalary = User::sum('salary');

        $this->assertEquals(180000, $totalSalary);
    }

    public function test_user_can_sum_with_where_clause()
    {
        User::create(['name' => 'Young', 'age' => 25, 'salary' => 50000]);
        User::create(['name' => 'Old', 'age' => 40, 'salary' => 80000]);
        User::create(['name' => 'Middle', 'age' => 30, 'salary' => 60000]);

        $youngSalaries = User::where('age', '<', 35)->sum('salary');

        $this->assertEquals(110000, $youngSalaries);
    }

    public function test_user_can_average_numeric_column()
    {
        User::create(['name' => 'John', 'age' => 20]);
        User::create(['name' => 'Jane', 'age' => 30]);
        User::create(['name' => 'Bob', 'age' => 40]);

        $averageAge = User::avg('age');

        $this->assertEquals(30, $averageAge);
    }

    public function test_user_can_average_with_where_clause()
    {
        User::create(['name' => 'Young1', 'age' => 20, 'salary' => 40000]);
        User::create(['name' => 'Young2', 'age' => 25, 'salary' => 50000]);
        User::create(['name' => 'Old', 'age' => 50, 'salary' => 100000]);

        $youngAverage = User::where('age', '<', 30)->avg('salary');

        $this->assertEquals(45000, $youngAverage);
    }

    public function test_user_can_find_minimum_value()
    {
        User::create(['name' => 'John', 'age' => 35]);
        User::create(['name' => 'Jane', 'age' => 25]);
        User::create(['name' => 'Bob', 'age' => 45]);

        $minAge = User::min('age');

        $this->assertEquals(25, $minAge);
    }

    public function test_user_can_find_minimum_with_where_clause()
    {
        User::create(['name' => 'John', 'age' => 35, 'salary' => 70000]);
        User::create(['name' => 'Jane', 'age' => 25, 'salary' => 45000]);
        User::create(['name' => 'Bob', 'age' => 45, 'salary' => 90000]);

        $minSalaryForAdults = User::where('age', '>=', 30)->min('salary');

        $this->assertEquals(70000, $minSalaryForAdults);
    }

    public function test_user_can_find_maximum_value()
    {
        User::create(['name' => 'John', 'age' => 35]);
        User::create(['name' => 'Jane', 'age' => 25]);
        User::create(['name' => 'Bob', 'age' => 45]);

        $maxAge = User::max('age');

        $this->assertEquals(45, $maxAge);
    }

    public function test_user_can_find_maximum_with_where_clause()
    {
        User::create(['name' => 'John', 'age' => 35, 'salary' => 70000]);
        User::create(['name' => 'Jane', 'age' => 25, 'salary' => 45000]);
        User::create(['name' => 'Bob', 'age' => 45, 'salary' => 90000]);

        $maxSalaryForYoung = User::where('age', '<', 40)->max('salary');

        $this->assertEquals(70000, $maxSalaryForYoung);
    }

    public function test_aggregation_returns_zero_for_empty_result()
    {
        // No users created

        $sum = User::sum('salary');
        $avg = User::avg('age');
        $min = User::min('age');
        $max = User::max('salary');

        $this->assertEquals(0, $sum);
        $this->assertEquals(0, $avg);
        $this->assertEquals(0, $min);
        $this->assertEquals(0, $max);
    }

    public function test_aggregation_returns_zero_for_no_matching_records()
    {
        User::create(['name' => 'John', 'age' => 25]);

        $oldUserSalary = User::where('age', '>', 50)->sum('salary');
        $oldUserAvgAge = User::where('age', '>', 50)->avg('age');

        $this->assertEquals(0, $oldUserSalary);
        $this->assertEquals(0, $oldUserAvgAge);
    }
}
