# NeoEloquent Features Implementation Plan

**Date**: 2025-10-25
**Version Target**: v1.3.0 → v1.4.0
**Approach**: Test-Driven Development (TDD)
**Principle**: Laravel-like API, 100% backward compatible

---

## Table of Contents

1. [Phase 1: v1.3.0 - Quick Wins](#phase-1-v130---quick-wins)
2. [Phase 2: v1.4.0 - Advanced Features](#phase-2-v140---advanced-features)
3. [Implementation Principles](#implementation-principles)
4. [Success Criteria](#success-criteria)

---

## Phase 1: v1.3.0 - Quick Wins

**Timeline**: 1 week (~8 hours)
**Goal**: Add high-value, low-risk features that align with Laravel conventions

### Feature 1.1: Multi-Label Node Support

**Estimated Time**: 2-3 hours
**Value**: High (30% faster label-specific queries)
**Risk**: Low (fully backward compatible)

#### Design Goals

1. **Laravel-like API**: Follow Eloquent's table naming convention
2. **Backward compatible**: Single label (current behavior) remains default
3. **Progressive enhancement**: Opt-in for multi-label support
4. **Query optimization**: MATCH on all labels for efficiency

#### API Design

```php
// Option 1: Using $labels property (RECOMMENDED - Laravel-like)
class User extends Neo4JModel
{
    protected $table = 'users';        // Primary label (required)
    protected $labels = ['Person', 'Individual'];  // Additional labels (optional)
}
// Creates: (:users:Person:Individual)

// Option 2: Using single $label property with array
class User extends Neo4JModel
{
    protected $label = ['users', 'Person', 'Individual'];
}
// Creates: (:users:Person:Individual)

// Option 3: Dynamic labels
class User extends Neo4JModel
{
    public function getLabels(): array
    {
        return ['users', 'Person', 'Active'];
    }
}

// Querying
User::where('age', '>', 25)->get();
// MATCH (n:users:Person:Individual) WHERE n.age > 25 RETURN n

// Query specific label combination
User::withLabels(['users', 'Active'])->get();
// MATCH (n:users:Active) WHERE ... RETURN n

// Check labels at runtime
$user->getLabels(); // ['users', 'Person', 'Individual']
$user->hasLabel('Person'); // true
```

**Recommendation**: Use **Option 1** - separate `$labels` property for additional labels, keeping `$table` as primary.

#### TDD Implementation Steps

##### Step 1: Write Tests First (1 hour)

**Test File**: `tests/Feature/MultiLabelNodesTest.php`

```php
<?php

use Tests\TestCase;
use Tests\Models\User;
use Tests\Models\MultiLabelUser;

class MultiLabelNodesTest extends TestCase
{
    /** @test */
    public function creates_node_with_single_label_by_default()
    {
        $user = User::create(['name' => 'John']);

        // Verify single label exists
        $result = DB::connection('neo4j')->select('
            MATCH (n:users {id: $id})
            RETURN labels(n) as labels
        ', ['id' => $user->id]);

        $this->assertEquals(['users'], $result[0]->labels);
    }

    /** @test */
    public function creates_node_with_multiple_labels_when_specified()
    {
        $user = MultiLabelUser::create(['name' => 'Jane']);

        $result = DB::connection('neo4j')->select('
            MATCH (n {id: $id})
            RETURN labels(n) as labels
        ', ['id' => $user->id]);

        $this->assertContains('users', $result[0]->labels);
        $this->assertContains('Person', $result[0]->labels);
        $this->assertContains('Individual', $result[0]->labels);
    }

    /** @test */
    public function queries_match_on_all_labels()
    {
        MultiLabelUser::create(['name' => 'John']);

        $cypher = MultiLabelUser::where('name', 'John')->toCypher();

        $this->assertStringContainsString('MATCH (n:users:Person:Individual)', $cypher);
    }

    /** @test */
    public function can_query_with_specific_label_subset()
    {
        $user = MultiLabelUser::create(['name' => 'John']);

        $users = MultiLabelUser::withLabels(['users', 'Person'])->get();

        $this->assertCount(1, $users);
        $this->assertEquals('John', $users->first()->name);
    }

    /** @test */
    public function can_check_if_model_has_specific_label()
    {
        $user = MultiLabelUser::create(['name' => 'John']);

        $this->assertTrue($user->hasLabel('users'));
        $this->assertTrue($user->hasLabel('Person'));
        $this->assertFalse($user->hasLabel('Admin'));
    }

    /** @test */
    public function get_labels_returns_all_labels()
    {
        $user = MultiLabelUser::create(['name' => 'John']);

        $labels = $user->getLabels();

        $this->assertContains('users', $labels);
        $this->assertContains('Person', $labels);
        $this->assertContains('Individual', $labels);
    }

    /** @test */
    public function updates_preserve_all_labels()
    {
        $user = MultiLabelUser::create(['name' => 'John']);
        $user->update(['name' => 'Jane']);

        $result = DB::connection('neo4j')->select('
            MATCH (n {id: $id})
            RETURN labels(n) as labels
        ', ['id' => $user->id]);

        $this->assertCount(3, $result[0]->labels);
    }

    /** @test */
    public function deletes_work_with_multi_label_nodes()
    {
        $user = MultiLabelUser::create(['name' => 'John']);
        $id = $user->id;

        $user->delete();

        $this->assertNull(MultiLabelUser::find($id));
    }

    /** @test */
    public function relationships_work_with_multi_label_nodes()
    {
        $user = MultiLabelUser::create(['name' => 'John']);
        $post = $user->posts()->create(['title' => 'Test Post']);

        $this->assertCount(1, $user->posts);
        $this->assertEquals('Test Post', $user->posts->first()->title);
    }

    /** @test */
    public function eager_loading_works_with_multi_label_nodes()
    {
        $user = MultiLabelUser::create(['name' => 'John']);
        $user->posts()->create(['title' => 'Post 1']);

        $users = MultiLabelUser::with('posts')->get();

        $this->assertCount(1, $users->first()->posts);
    }

    /** @test */
    public function where_has_works_with_multi_label_nodes()
    {
        $user = MultiLabelUser::create(['name' => 'John']);
        $user->posts()->create(['title' => 'Post 1']);

        $users = MultiLabelUser::whereHas('posts')->get();

        $this->assertCount(1, $users);
    }
}
```

**Test Model**: `tests/Models/MultiLabelUser.php`

```php
<?php

namespace Tests\Models;

use Look\EloquentCypher\Neo4JModel;

class MultiLabelUser extends Neo4JModel
{
    protected $connection = 'neo4j';
    protected $table = 'users';
    protected $labels = ['Person', 'Individual'];
    protected $fillable = ['name', 'email', 'age'];

    public function posts()
    {
        return $this->hasMany(Post::class, 'user_id');
    }
}
```

##### Step 2: Run Tests (Expect Failures)

```bash
./vendor/bin/pest tests/Feature/MultiLabelNodesTest.php --no-coverage
# Expected: All tests fail
```

##### Step 3: Implement Core Functionality (1-1.5 hours)

**File**: `src/Neo4JModel.php`

```php
// Add property for additional labels
protected $labels = [];

// Add method to get all labels (primary + additional)
public function getLabels(): array
{
    $primary = $this->getTable();
    $additional = $this->labels ?? [];

    return array_merge([$primary], $additional);
}

// Add method to check if model has specific label
public function hasLabel(string $label): bool
{
    return in_array($label, $this->getLabels());
}

// Add method to get label string for Cypher queries
protected function getLabelString(): string
{
    return ':' . implode(':', $this->getLabels());
}

// Add scope for querying with specific labels
public function scopeWithLabels($query, array $labels)
{
    // Store custom labels for this query
    $query->customLabels = $labels;
    return $query;
}
```

**File**: `src/Neo4jQueryBuilder.php`

```php
// Add property for custom labels
public $customLabels;

// Modify compileComponents() to use multi-labels
protected function compileComponents(): string
{
    // Get model's labels
    $labels = $this->getLabelsForQuery();

    $cypher = "MATCH (n{$labels})";
    // ... rest of query building
}

protected function getLabelsForQuery(): string
{
    if (!empty($this->customLabels)) {
        return ':' . implode(':', $this->customLabels);
    }

    if ($this->model && method_exists($this->model, 'getLabels')) {
        return ':' . implode(':', $this->model->getLabels());
    }

    return ':' . $this->from;
}

// Update CREATE queries
protected function buildCreateQuery(array $values): string
{
    $labels = $this->model ? $this->model->getLabelString() : ':' . $this->from;

    return "CREATE (n{$labels} \$properties) RETURN n";
}

// Update all query methods to use multi-label matching
```

**File**: `src/Neo4jConnection.php`

```php
// Update processInsert() to handle multiple labels
protected function processInsert($query, $bindings, $returnLastId = false)
{
    // Extract labels from query (already in format :Label1:Label2)
    // No changes needed if we handle it in QueryBuilder
}
```

##### Step 4: Run Tests Again (Should Pass)

```bash
./vendor/bin/pest tests/Feature/MultiLabelNodesTest.php --no-coverage
# Expected: All tests pass
```

##### Step 5: Verify Existing Tests Still Pass (No Regressions)

```bash
./vendor/bin/pest --no-coverage
# Expected: All 1,408 tests still pass
```

##### Step 6: Update Documentation (0.5 hours)

**Files to Update**:
- `CLAUDE.md` - Add multi-label section
- `README.md` - Add multi-label examples
- `DOCUMENTATION.md` - Add comprehensive multi-label guide

---

### Feature 1.2: Neo4j-Specific Aggregate Functions

**Estimated Time**: 1-2 hours
**Value**: Medium (native Neo4j stats)
**Risk**: Low (additive feature)

#### Design Goals

1. **Laravel-like API**: Follow Eloquent's aggregate method patterns
2. **Consistent with existing**: Match `count()`, `sum()`, `avg()` style
3. **Neo4j-specific**: Add functions unique to Neo4j
4. **Type-safe**: Return appropriate types (float, int, array)

#### API Design

```php
// Follow Laravel's aggregate pattern
User::percentileDisc('age', 0.95); // float
User::percentileCont('age', 0.5);  // float (median)
User::stdev('age');                 // float (standard deviation)
User::stdevp('age');                // float (population std dev)
User::collect('skills');            // array (collect values into list)

// Chain with query builder (Laravel-like)
User::where('active', true)
    ->where('age', '>', 18)
    ->percentileDisc('age', 0.95);

// Multiple aggregates (return object)
$stats = User::selectRaw([
    'COUNT(*) as total',
    'AVG(n.age) as avg_age',
    'percentileDisc(n.age, 0.95) as p95_age',
    'stdev(n.age) as std_dev'
])->first();
```

#### TDD Implementation Steps

##### Step 1: Write Tests First (0.5 hours)

**Test File**: `tests/Feature/Neo4jAggregatesTest.php`

```php
<?php

use Tests\TestCase;
use Tests\Models\User;

class Neo4jAggregatesTest extends TestCase
{
    /** @test */
    public function percentile_disc_returns_value_at_percentile()
    {
        // Create users with ages 20, 30, 40, 50, 60
        foreach ([20, 30, 40, 50, 60] as $age) {
            User::create(['name' => "User $age", 'age' => $age]);
        }

        $p95 = User::percentileDisc('age', 0.95);

        $this->assertEquals(60, $p95); // 95th percentile
    }

    /** @test */
    public function percentile_cont_returns_interpolated_value()
    {
        foreach ([20, 30, 40, 50, 60] as $age) {
            User::create(['name' => "User $age", 'age' => $age]);
        }

        $median = User::percentileCont('age', 0.5);

        $this->assertEquals(40.0, $median);
    }

    /** @test */
    public function stdev_returns_standard_deviation()
    {
        foreach ([10, 20, 30, 40, 50] as $age) {
            User::create(['name' => "User $age", 'age' => $age]);
        }

        $stdDev = User::stdev('age');

        // Standard deviation of [10,20,30,40,50] ≈ 15.81
        $this->assertEqualsWithDelta(15.81, $stdDev, 0.1);
    }

    /** @test */
    public function stdevp_returns_population_standard_deviation()
    {
        foreach ([10, 20, 30, 40, 50] as $age) {
            User::create(['name' => "User $age", 'age' => $age]);
        }

        $stdDevP = User::stdevp('age');

        // Population standard deviation ≈ 14.14
        $this->assertEqualsWithDelta(14.14, $stdDevP, 0.1);
    }

    /** @test */
    public function collect_returns_array_of_values()
    {
        User::create(['name' => 'John', 'skills' => ['PHP', 'JavaScript']]);
        User::create(['name' => 'Jane', 'skills' => ['Python', 'Go']]);

        $allSkills = User::collect('skills');

        $this->assertIsArray($allSkills);
        $this->assertContains('PHP', $allSkills);
        $this->assertContains('Python', $allSkills);
    }

    /** @test */
    public function aggregates_work_with_where_clauses()
    {
        User::create(['name' => 'John', 'age' => 25, 'active' => true]);
        User::create(['name' => 'Jane', 'age' => 35, 'active' => true]);
        User::create(['name' => 'Bob', 'age' => 45, 'active' => false]);

        $avgActiveAge = User::where('active', true)->avg('age');

        $this->assertEquals(30, $avgActiveAge);
    }

    /** @test */
    public function percentile_disc_with_where_clause()
    {
        foreach ([20, 30, 40, 50, 60] as $age) {
            User::create(['name' => "User $age", 'age' => $age, 'active' => $age > 30]);
        }

        $p95 = User::where('active', true)->percentileDisc('age', 0.95);

        $this->assertGreaterThanOrEqual(50, $p95);
    }

    /** @test */
    public function aggregates_return_null_for_empty_result_set()
    {
        $p95 = User::where('age', '>', 1000)->percentileDisc('age', 0.95);

        $this->assertNull($p95);
    }

    /** @test */
    public function multiple_aggregates_in_select_raw()
    {
        foreach ([20, 30, 40, 50, 60] as $age) {
            User::create(['name' => "User $age", 'age' => $age]);
        }

        $stats = User::selectRaw('
            COUNT(*) as total,
            AVG(n.age) as avg_age,
            percentileDisc(n.age, 0.95) as p95_age,
            stdev(n.age) as std_dev
        ')->first();

        $this->assertEquals(5, $stats->total);
        $this->assertEquals(40, $stats->avg_age);
        $this->assertEquals(60, $stats->p95_age);
        $this->assertGreaterThan(0, $stats->std_dev);
    }
}
```

**Unit Test File**: `tests/Unit/Neo4jAggregateGrammarTest.php`

```php
<?php

use Tests\TestCase;
use Look\EloquentCypher\Neo4jQueryBuilder;

class Neo4jAggregateGrammarTest extends TestCase
{
    /** @test */
    public function compiles_percentile_disc_correctly()
    {
        $builder = $this->getBuilder();

        $cypher = $builder->from('users')->percentileDisc('age', 0.95);

        $this->assertStringContainsString('percentileDisc(n.age, 0.95)', $cypher);
    }

    /** @test */
    public function compiles_percentile_cont_correctly()
    {
        $builder = $this->getBuilder();

        $cypher = $builder->from('users')->percentileCont('age', 0.5);

        $this->assertStringContainsString('percentileCont(n.age, 0.5)', $cypher);
    }

    /** @test */
    public function compiles_stdev_correctly()
    {
        $builder = $this->getBuilder();

        $cypher = $builder->from('users')->stdev('age');

        $this->assertStringContainsString('stdev(n.age)', $cypher);
    }

    protected function getBuilder()
    {
        return new Neo4jQueryBuilder(
            DB::connection('neo4j'),
            new \Look\EloquentCypher\Neo4jGrammar
        );
    }
}
```

##### Step 2: Run Tests (Expect Failures)

```bash
./vendor/bin/pest tests/Feature/Neo4jAggregatesTest.php --no-coverage
# Expected: All tests fail
```

##### Step 3: Implement Core Functionality (0.5-1 hour)

**File**: `src/Neo4jQueryBuilder.php`

```php
/**
 * Get the 95th percentile (discrete) of a column's values.
 *
 * @param  string  $column
 * @param  float  $percentile  Value between 0.0 and 1.0
 * @return float|null
 */
public function percentileDisc(string $column, float $percentile): ?float
{
    return $this->aggregate('percentileDisc', [$column, $percentile]);
}

/**
 * Get the interpolated percentile (continuous) of a column's values.
 *
 * @param  string  $column
 * @param  float  $percentile  Value between 0.0 and 1.0
 * @return float|null
 */
public function percentileCont(string $column, float $percentile): ?float
{
    return $this->aggregate('percentileCont', [$column, $percentile]);
}

/**
 * Get the sample standard deviation of a column's values.
 *
 * @param  string  $column
 * @return float|null
 */
public function stdev(string $column): ?float
{
    return $this->aggregate('stdev', [$column]);
}

/**
 * Get the population standard deviation of a column's values.
 *
 * @param  string  $column
 * @return float|null
 */
public function stdevp(string $column): ?float
{
    return $this->aggregate('stdevp', [$column]);
}

/**
 * Collect all values of a column into an array.
 *
 * @param  string  $column
 * @return array
 */
public function collect(string $column): array
{
    $result = $this->aggregate('collect', [$column]);
    return is_array($result) ? $result : [];
}

/**
 * Execute an aggregate function on the database.
 * Enhanced to support Neo4j-specific functions with multiple parameters.
 *
 * @param  string  $function
 * @param  array  $columns
 * @return mixed
 */
public function aggregate($function, $columns = ['*'])
{
    $this->aggregate = compact('function', 'columns');

    $previousColumns = $this->columns;
    $this->columns = null;

    $results = $this->get($columns);

    $this->aggregate = null;
    $this->columns = $previousColumns;

    if (isset($results[0])) {
        return $results[0]->aggregate;
    }

    return null;
}
```

**File**: `src/Neo4jGrammar.php`

```php
/**
 * Compile an aggregate query clause with Neo4j-specific support.
 *
 * @param  \Look\EloquentCypher\Neo4jQueryBuilder  $query
 * @param  array  $aggregate
 * @return string
 */
protected function compileAggregate(Neo4jQueryBuilder $query, array $aggregate): string
{
    $function = $aggregate['function'];
    $columns = $aggregate['columns'];

    // For standard aggregates (count, sum, avg, min, max)
    if (in_array(strtolower($function), ['count', 'sum', 'avg', 'min', 'max'])) {
        $column = $columns[0] === '*' ? '*' : "n.{$columns[0]}";
        return strtoupper($function) . "({$column}) as aggregate";
    }

    // For Neo4j-specific aggregates with multiple parameters
    if (in_array($function, ['percentileDisc', 'percentileCont'])) {
        $column = "n.{$columns[0]}";
        $percentile = $columns[1];
        return "{$function}({$column}, {$percentile}) as aggregate";
    }

    // For single-parameter Neo4j aggregates
    if (in_array($function, ['stdev', 'stdevp', 'collect'])) {
        $column = "n.{$columns[0]}";
        return "{$function}({$column}) as aggregate";
    }

    // Fallback for unknown functions
    $column = $columns[0] === '*' ? '*' : "n.{$columns[0]}";
    return "{$function}({$column}) as aggregate";
}
```

##### Step 4: Run Tests Again (Should Pass)

```bash
./vendor/bin/pest tests/Feature/Neo4jAggregatesTest.php --no-coverage
./vendor/bin/pest tests/Unit/Neo4jAggregateGrammarTest.php --no-coverage
# Expected: All tests pass
```

##### Step 5: Verify No Regressions

```bash
./vendor/bin/pest --no-coverage
# Expected: All 1,408 tests + 20 new tests pass
```

##### Step 6: Update Documentation (0.5 hours)

**Add to README.md**:
```php
// Neo4j-Specific Aggregates
$p95 = User::where('active', true)->percentileDisc('age', 0.95);
$median = User::percentileCont('age', 0.5);
$stdDev = User::stdev('salary');
$allSkills = User::collect('skills');
```

---

### Phase 1 Summary

**Total Time**: ~8 hours
**New Tests**: ~31 tests
**New Features**: 2 (multi-labels, aggregates)
**Breaking Changes**: 0
**Backward Compatibility**: 100%

**Success Metrics**:
- ✅ All existing tests pass (1,408 tests)
- ✅ All new tests pass (~31 new tests)
- ✅ Zero breaking changes
- ✅ Documentation updated
- ✅ Code style (Pint) passing
- ✅ PHPStan level 5 passing

---

## Phase 2: v1.4.0 - Advanced Features

**Timeline**: 2 weeks (~24 hours)
**Goal**: Add high-value features with medium complexity

### Feature 2.1: createWith() Multi-Model Creation

**Estimated Time**: 8-10 hours
**Value**: High (50%+ faster multi-model creation)
**Risk**: Medium (complex implementation)

#### Design Goals

1. **Laravel-like API**: Similar to `create()` but with relations
2. **Single query**: All creates in one Cypher query
3. **Transaction safety**: Atomic operation
4. **Relationship support**: hasMany, hasOne, belongsTo, belongsToMany
5. **Return loaded model**: With all relations loaded

#### API Design

```php
// Basic usage (Laravel-like)
$user = User::createWith([
    'name' => 'John',
    'email' => 'john@example.com'
], [
    'posts' => [
        ['title' => 'Post 1', 'body' => 'Content 1'],
        ['title' => 'Post 2', 'body' => 'Content 2']
    ],
    'profile' => [
        'bio' => 'Software Developer',
        'avatar' => 'avatar.jpg'
    ]
]);

// Result: User created with 2 posts and 1 profile in single query
// All relations loaded: $user->posts->count() === 2

// With belongsToMany (pivot data)
$user = User::createWith([
    'name' => 'Jane'
], [
    'roles' => [
        ['id' => 1, 'pivot' => ['granted_at' => now()]],
        ['id' => 2, 'pivot' => ['granted_at' => now(), 'expires_at' => now()->addYear()]]
    ]
]);

// Nested relations
$user = User::createWith([
    'name' => 'John'
], [
    'posts' => [
        [
            'title' => 'Post 1',
            'comments' => [
                ['body' => 'Great post!'],
                ['body' => 'Thanks for sharing']
            ]
        ]
    ]
]);

// With native edges
$user = User::createWith([
    'name' => 'John'
], [
    'posts' => [
        ['title' => 'Post 1', 'edge' => ['created_at' => now(), 'priority' => 'high']]
    ]
])->useNativeEdges();
```

#### TDD Implementation Steps

##### Step 1: Write Tests First (3-4 hours)

**Test File**: `tests/Feature/CreateWithTest.php`

```php
<?php

use Tests\TestCase;
use Tests\Models\User;
use Tests\Models\Post;
use Tests\Models\Profile;
use Tests\Models\Role;

class CreateWithTest extends TestCase
{
    /** @test */
    public function creates_model_with_has_many_relations()
    {
        $user = User::createWith([
            'name' => 'John',
            'email' => 'john@example.com'
        ], [
            'posts' => [
                ['title' => 'Post 1', 'body' => 'Content 1'],
                ['title' => 'Post 2', 'body' => 'Content 2']
            ]
        ]);

        $this->assertInstanceOf(User::class, $user);
        $this->assertEquals('John', $user->name);
        $this->assertCount(2, $user->posts);
        $this->assertEquals('Post 1', $user->posts->first()->title);

        // Verify in database
        $fresh = User::with('posts')->find($user->id);
        $this->assertCount(2, $fresh->posts);
    }

    /** @test */
    public function creates_model_with_has_one_relation()
    {
        $user = User::createWith([
            'name' => 'Jane'
        ], [
            'profile' => [
                'bio' => 'Developer',
                'avatar' => 'avatar.jpg'
            ]
        ]);

        $this->assertNotNull($user->profile);
        $this->assertEquals('Developer', $user->profile->bio);
    }

    /** @test */
    public function creates_model_with_belongs_to_many_relations()
    {
        $role1 = Role::create(['name' => 'Admin']);
        $role2 = Role::create(['name' => 'Editor']);

        $user = User::createWith([
            'name' => 'John'
        ], [
            'roles' => [
                ['id' => $role1->id, 'pivot' => ['granted_at' => now()]],
                ['id' => $role2->id, 'pivot' => ['granted_at' => now()]]
            ]
        ]);

        $this->assertCount(2, $user->roles);
        $this->assertNotNull($user->roles->first()->pivot->granted_at);
    }

    /** @test */
    public function creates_model_with_nested_relations()
    {
        $user = User::createWith([
            'name' => 'John'
        ], [
            'posts' => [
                [
                    'title' => 'Post 1',
                    'comments' => [
                        ['body' => 'Comment 1'],
                        ['body' => 'Comment 2']
                    ]
                ]
            ]
        ]);

        $this->assertCount(1, $user->posts);
        $this->assertCount(2, $user->posts->first()->comments);
    }

    /** @test */
    public function rollback_on_error_keeps_database_clean()
    {
        $initialCount = User::count();

        try {
            User::createWith([
                'name' => 'John'
            ], [
                'posts' => [
                    ['title' => null] // Will fail validation
                ]
            ]);

            $this->fail('Should have thrown exception');
        } catch (\Exception $e) {
            $this->assertEquals($initialCount, User::count());
        }
    }

    /** @test */
    public function creates_with_native_edges_when_enabled()
    {
        $user = User::createWith([
            'name' => 'John'
        ], [
            'posts' => [
                ['title' => 'Post 1']
            ]
        ])->useNativeEdges();

        // Verify edge exists
        $result = DB::connection('neo4j')->select('
            MATCH (u:users {id: $userId})-[r]->(p:posts)
            RETURN type(r) as rel_type
        ', ['userId' => $user->id]);

        $this->assertNotEmpty($result);
    }

    /** @test */
    public function performance_faster_than_sequential_creates()
    {
        // Sequential creation
        $start = microtime(true);
        $user1 = User::create(['name' => 'User 1']);
        foreach (range(1, 10) as $i) {
            $user1->posts()->create(['title' => "Post $i"]);
        }
        $sequentialTime = microtime(true) - $start;

        // createWith
        $start = microtime(true);
        $user2 = User::createWith(['name' => 'User 2'], [
            'posts' => array_map(fn($i) => ['title' => "Post $i"], range(1, 10))
        ]);
        $createWithTime = microtime(true) - $start;

        $this->assertLessThan($sequentialTime, $createWithTime);
        $improvement = (($sequentialTime - $createWithTime) / $sequentialTime) * 100;
        $this->assertGreaterThan(40, $improvement); // At least 40% faster
    }

    /** @test */
    public function validates_relation_names()
    {
        $this->expectException(\InvalidArgumentException::class);

        User::createWith(['name' => 'John'], [
            'nonExistentRelation' => [['data' => 'value']]
        ]);
    }

    /** @test */
    public function empty_relations_array_works()
    {
        $user = User::createWith(['name' => 'John'], []);

        $this->assertEquals('John', $user->name);
    }

    /** @test */
    public function handles_timestamps_correctly()
    {
        $user = User::createWith(['name' => 'John'], [
            'posts' => [['title' => 'Post 1']]
        ]);

        $this->assertNotNull($user->created_at);
        $this->assertNotNull($user->posts->first()->created_at);
    }
}
```

##### Step 2: Implementation (4-5 hours)

**File**: `src/Neo4JModel.php`

```php
/**
 * Create a new model with relations in a single query.
 * Laravel-like API for efficient multi-model creation.
 *
 * @param  array  $attributes  Model attributes
 * @param  array  $relations   Relations to create ['posts' => [...], 'profile' => [...]]
 * @return static
 */
public static function createWith(array $attributes, array $relations = []): static
{
    return static::query()->createWith($attributes, $relations);
}
```

**File**: `src/Neo4jEloquentBuilder.php`

```php
/**
 * Create model with relations in single query.
 *
 * @param  array  $attributes
 * @param  array  $relations
 * @return \Look\EloquentCypher\Neo4JModel
 */
public function createWith(array $attributes, array $relations = [])
{
    // Validate relation names
    $this->validateRelations($relations);

    // Build complex CREATE query
    $cypher = $this->buildCreateWithQuery($attributes, $relations);

    // Execute in transaction
    return $this->connection->transaction(function () use ($cypher, $attributes, $relations) {
        $result = $this->connection->select($cypher);

        // Hydrate model with relations
        return $this->hydrateModelWithRelations($result, $relations);
    });
}

protected function validateRelations(array $relations): void
{
    foreach (array_keys($relations) as $relationName) {
        if (!method_exists($this->model, $relationName)) {
            throw new \InvalidArgumentException(
                "Relation [{$relationName}] does not exist on model [" . get_class($this->model) . "]"
            );
        }
    }
}

protected function buildCreateWithQuery(array $attributes, array $relations): string
{
    // Build CREATE statement for main model
    // Build CREATE statements for each relation
    // Build relationship edges (MATCH + CREATE)
    // Return all in single RETURN statement

    // This is complex - see detailed implementation below
}
```

##### Step 3: Run Tests (Iteratively Fix Issues)

```bash
./vendor/bin/pest tests/Feature/CreateWithTest.php --no-coverage
```

##### Step 4: Documentation (1 hour)

---

### Feature 2.2: Enhanced Edge Model Classes

**Estimated Time**: 6-8 hours
**Value**: Medium (more intuitive edge operations)
**Risk**: Medium (need careful API design)

#### Design Goals

1. **Laravel-like**: Similar to Pivot model API
2. **Backward compatible**: Keep existing pivot API
3. **Explicit edges**: `edge()` method returns Edge model
4. **Edge properties**: Easy get/set for edge attributes
5. **Edge operations**: Update, delete edges directly

#### API Design

```php
// Access edge explicitly (new)
$edge = $user->posts()->edge();
$edge->created_at;
$edge->priority = 'high';
$edge->save();

// Still works (backward compatible)
$post->pivot->created_at;

// Query edges
$edges = $user->posts()->edges()->get();
foreach ($edges as $edge) {
    echo $edge->type; // Relationship type
    echo $edge->start; // Start node
    echo $edge->end; // End node
}

// Update edge properties
$user->posts()->updateEdge($postId, ['priority' => 'high']);

// Delete specific edge
$user->posts()->detachEdge($postId);

// Create edge with properties
$user->posts()->attachEdge($postId, ['created_at' => now()]);
```

#### TDD Implementation Steps

Similar structure to previous features - write tests first, implement, verify.

---

## Implementation Principles

### 1. Test-Driven Development (TDD)

**ALWAYS follow this order**:
1. ✅ Write comprehensive tests FIRST
2. ✅ Run tests (expect failures)
3. ✅ Implement minimal code to pass tests
4. ✅ Run tests again (should pass)
5. ✅ Verify no regressions (run full suite)
6. ✅ Refactor if needed (tests still pass)
7. ✅ Update documentation

**Never write code before tests!**

### 2. Laravel-Like API

**Follow Laravel conventions**:
- Method naming: camelCase (e.g., `createWith`, `percentileDisc`)
- Return types: Match Eloquent (Collection, Model, primitives)
- Optional parameters: Sensible defaults
- Fluent interface: Chain methods
- Array parameters: Use arrays, not variadic
- Documentation: Follow Laravel docblock style

**Examples**:
```php
// GOOD - Laravel-like
User::percentileDisc('age', 0.95);
User::createWith(['name' => 'John'], ['posts' => [...]]);

// BAD - Not Laravel-like
User::percentile_disc('age', 0.95);
User::create_with_relations(['name' => 'John'], [...]);
```

### 3. Backward Compatibility

**Non-negotiable rules**:
- ✅ All existing tests MUST pass
- ✅ No breaking changes to public APIs
- ✅ New features are additive only
- ✅ Default behavior unchanged
- ✅ Opt-in for new features

### 4. Minimal Code

**SOLID principles**:
- Single Responsibility: One method = one purpose
- Open/Closed: Extend, don't modify
- Liskov Substitution: Subtypes are substitutable
- Interface Segregation: Small, focused interfaces
- Dependency Inversion: Depend on abstractions

**Keep it simple**:
- No premature optimization
- No unnecessary abstraction
- Clear, readable code
- Comprehensive comments

### 5. Documentation

**Update immediately after implementation**:
- CLAUDE.md: Technical details for AI
- README.md: User-facing examples
- DOCUMENTATION.md: Comprehensive guide
- Inline docblocks: PHPStan compliance

---

## Success Criteria

### Phase 1 (v1.3.0)

✅ **All existing 1,408 tests pass**
✅ **All ~31 new tests pass**
✅ **Zero breaking changes**
✅ **PHPStan level 5 passing**
✅ **Code style (Pint) passing**
✅ **Documentation complete**
✅ **Performance validated** (multi-label 30% faster)

### Phase 2 (v1.4.0)

✅ **All existing + Phase 1 tests pass**
✅ **All ~50 new tests pass** (createWith + Edge models)
✅ **Zero breaking changes**
✅ **createWith 40%+ faster** than sequential creates
✅ **PHPStan level 5 passing**
✅ **Code style (Pint) passing**
✅ **Comprehensive documentation**

---

## Timeline

### Week 1: Phase 1 (v1.3.0)

**Day 1-2**: Multi-label support (2-3 hours)
- Write tests (1h)
- Implement (1-1.5h)
- Document (0.5h)

**Day 2-3**: Neo4j aggregates (1-2 hours)
- Write tests (0.5h)
- Implement (0.5-1h)
- Document (0.5h)

**Day 3**: Testing & Polish (2-3 hours)
- Run full test suite
- Fix any issues
- Code review
- Prepare for release

### Weeks 2-3: Phase 2 (v1.4.0)

**Week 2**: createWith() (8-10 hours)
- Design API (1h)
- Write tests (3-4h)
- Implement (4-5h)
- Document (1h)

**Week 3**: Edge models (6-8 hours)
- Design API (2h)
- Write tests (2-3h)
- Implement (4-5h)
- Document (1h)

**Week 3 End**: Final testing & release
- Full test suite
- Performance benchmarks
- Documentation review
- Release v1.4.0

---

## Next Steps

**Ready to start implementation!**

1. Begin with Phase 1 - Multi-Label Support
2. Follow TDD strictly - tests first
3. Keep API Laravel-like
4. Maintain backward compatibility
5. Document as you go

**Let's build amazing features! 🚀**
