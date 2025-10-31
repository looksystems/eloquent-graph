<?php

namespace Tests\Feature;

use Tests\Models\User;
use Tests\TestCase\GraphTestCase;

class QueryBuilderMethodsTest extends GraphTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Create test data
        User::create(['name' => 'Alice', 'age' => 25, 'department' => 'Sales']);
        User::create(['name' => 'Bob', 'age' => 30, 'department' => 'Engineering']);
        User::create(['name' => 'Charlie', 'age' => 25, 'department' => 'Sales']);
        User::create(['name' => 'David', 'age' => 35, 'department' => 'Engineering']);
        User::create(['name' => 'Eve', 'age' => 30, 'department' => 'Marketing']);
        User::create(['name' => 'Frank', 'age' => 25, 'department' => 'Sales']);
    }

    // DISTINCT TESTS
    public function test_distinct_removes_duplicates(): void
    {
        $ages = User::distinct()->pluck('age')->sort()->values();

        $this->assertEquals([25, 30, 35], $ages->toArray());
    }

    public function test_distinct_with_select(): void
    {
        $users = User::select('age')->distinct()->get();
        $ages = $users->pluck('age')->sort()->values();

        $this->assertEquals([25, 30, 35], $ages->toArray());
    }

    public function test_distinct_with_where_clause(): void
    {
        $ages = User::where('department', 'Sales')
            ->distinct()
            ->pluck('age');

        $this->assertEquals([25], $ages->toArray());
    }

    // GROUP BY TESTS
    public function test_group_by_single_column(): void
    {
        $result = User::selectRaw('department, COUNT(*) as count')
            ->groupBy('department')
            ->get();

        $grouped = $result->pluck('count', 'department');

        $this->assertEquals(3, $grouped['Sales']);
        $this->assertEquals(2, $grouped['Engineering']);
        $this->assertEquals(1, $grouped['Marketing']);
    }

    public function test_group_by_multiple_columns(): void
    {
        $result = User::selectRaw('department, age, COUNT(*) as count')
            ->groupBy('department', 'age')
            ->get();

        $salesAge25 = $result->where('department', 'Sales')
            ->where('age', 25)
            ->first();

        $this->assertEquals(3, $salesAge25->count); // Alice, Charlie, Frank
    }

    public function test_group_by_with_aggregate_functions(): void
    {
        $result = User::selectRaw('department, AVG(age) as avg_age, MAX(age) as max_age, MIN(age) as min_age')
            ->groupBy('department')
            ->get();

        $engineering = $result->where('department', 'Engineering')->first();

        $this->assertEquals(32.5, $engineering->avg_age); // (30 + 35) / 2
        $this->assertEquals(35, $engineering->max_age);
        $this->assertEquals(30, $engineering->min_age);
    }

    // HAVING TESTS
    public function test_having_filters_grouped_results(): void
    {
        $result = User::selectRaw('department, COUNT(*) as count')
            ->groupBy('department')
            ->having('count', '>', 1)
            ->get();

        $departments = $result->pluck('department')->sort()->values();

        // Only Sales (3) and Engineering (2) have more than 1 user
        $this->assertEquals(['Engineering', 'Sales'], $departments->toArray());
    }

    public function test_having_with_aggregate_condition(): void
    {
        $result = User::selectRaw('department, AVG(age) as avg_age')
            ->groupBy('department')
            ->havingRaw('AVG(age) >= ?', [30])
            ->get();

        $departments = $result->pluck('department')->sort()->values();

        // Engineering avg = 32.5, Marketing avg = 30
        $this->assertEquals(['Engineering', 'Marketing'], $departments->toArray());
    }

    public function test_having_with_multiple_conditions(): void
    {
        $result = User::selectRaw('department, COUNT(*) as count, AVG(age) as avg_age')
            ->groupBy('department')
            ->having('count', '>=', 2)
            ->having('avg_age', '<', 30)  // Changed from 35 to 30 to make only Sales match
            ->get();

        // Only Sales matches: count=3, avg_age=25
        $this->assertCount(1, $result);
        $this->assertEquals('Sales', $result[0]->department);
    }

    // WHERE BETWEEN TESTS
    public function test_where_between_includes_range(): void
    {
        $users = User::whereBetween('age', [25, 30])
            ->orderBy('name')
            ->get();

        $names = $users->pluck('name')->toArray();

        $this->assertEquals(['Alice', 'Bob', 'Charlie', 'Eve', 'Frank'], $names);
    }

    public function test_where_between_with_exact_boundaries(): void
    {
        $count = User::whereBetween('age', [30, 30])->count();

        $this->assertEquals(2, $count); // Bob and Eve are both 30
    }

    public function test_where_not_between_excludes_range(): void
    {
        $users = User::whereNotBetween('age', [26, 34])
            ->orderBy('name')
            ->get();

        $names = $users->pluck('name')->toArray();

        // Only users with age 25 (Alice, Charlie, Frank) and 35 (David)
        $this->assertEquals(['Alice', 'Charlie', 'David', 'Frank'], $names);
    }

    public function test_or_where_between(): void
    {
        $users = User::where('department', 'Marketing')
            ->orWhereBetween('age', [34, 36])
            ->orderBy('name')
            ->get();

        $names = $users->pluck('name')->toArray();

        // Eve (Marketing) and David (age 35)
        $this->assertEquals(['David', 'Eve'], $names);
    }

    public function test_where_between_with_dates(): void
    {
        // Create users with specific dates
        User::truncate();
        User::create(['name' => 'Past', 'created_at' => now()->subDays(10)]);
        User::create(['name' => 'Recent', 'created_at' => now()->subDays(3)]);
        User::create(['name' => 'Current', 'created_at' => now()]);

        $users = User::whereBetween('created_at', [now()->subDays(7), now()])
            ->orderBy('name')
            ->get();

        $names = $users->pluck('name')->toArray();

        $this->assertEquals(['Current', 'Recent'], $names);
    }

    // COMBINED TESTS
    public function test_distinct_with_group_by(): void
    {
        // Add duplicate rows
        User::create(['name' => 'Alice2', 'age' => 25, 'department' => 'Sales']);

        $result = User::selectRaw('DISTINCT department, COUNT(*) as count')
            ->groupBy('department')
            ->get();

        $this->assertCount(3, $result); // Sales, Engineering, Marketing
    }

    public function test_group_by_with_where_between(): void
    {
        $result = User::whereBetween('age', [25, 30])
            ->selectRaw('department, COUNT(*) as count')
            ->groupBy('department')
            ->get();

        $grouped = $result->pluck('count', 'department');

        $this->assertEquals(3, $grouped['Sales']); // All Sales users are 25
        $this->assertEquals(1, $grouped['Engineering']); // Only Bob is 30
        $this->assertEquals(1, $grouped['Marketing']); // Eve is 30
    }

    public function test_complex_query_with_all_features(): void
    {
        $result = User::distinct()
            ->whereBetween('age', [25, 35])
            ->selectRaw('department, AVG(age) as avg_age, COUNT(*) as count')
            ->groupBy('department')
            ->having('count', '>=', 2)
            ->orderBy('avg_age', 'desc')
            ->get();

        // Engineering (avg 32.5) and Sales (avg 25) have >= 2 users
        $this->assertCount(2, $result);
        $this->assertEquals('Engineering', $result[0]->department);
        $this->assertEquals('Sales', $result[1]->department);
    }
}
