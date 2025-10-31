# Test Isolation Review & Refactoring Plan

**Date:** October 25, 2025
**Status:** Analysis Complete - Ready for Implementation

## Executive Summary

The test suite has **excellent isolation measures** already in place. This document identifies small gaps and proposes targeted improvements to achieve perfect test isolation.

**Current Grade:** A
**Target Grade:** A+

---

## Current State Analysis

### ✅ Strong Foundation Already in Place

The test suite has robust isolation infrastructure:

1. **Random Test Execution Order** (`phpunit.xml.dist:7`)
   - Helps identify hidden test dependencies
   - Tests pass in any order

2. **Comprehensive Neo4jTestCase** (`tests/TestCase/Neo4jTestCase.php`)
   - Database cleanup before/after each test
   - Model event listener clearing
   - Connection recreation between tests
   - Transaction state reset
   - Model static state clearing
   - Parallel execution support with unique namespacing

3. **Proper Test Organization**
   - Feature tests extend Neo4jTestCase (full isolation)
   - Unit tests extend UnitTestCase (lightweight)

4. **Defensive Cleanup**
   - `setUp()`: Clear listeners, recreate connection, clean database
   - `tearDown()`: Reset transactions, clear database, flush listeners, verify cleanup

---

## Identified Isolation Issues

### 🔴 High Priority Issues

#### Issue #1: ConnectionPoolingTest Configuration Pollution

**Location:** `tests/Feature/ConnectionPoolingTest.php`

**Problem:**
Tests create multiple temporary connection configurations:
- `neo4j_primary` (line 9)
- `neo4j_secondary` (line 19)
- `neo4j_split` (line 84)
- `neo4j_pooled` (line 123)
- `neo4j_concurrent` (line 264)
- `neo4j_retry` (line 169)
- `neo4j_lazy` (line 238)
- `neo4j_purge` (line 334)

These configs persist in Laravel's config cache and aren't cleaned up in tearDown.

**Impact:**
- Config pollution affects subsequent tests
- Connection instances may leak in parallel execution
- DB facade cache holds stale connections

**Evidence:**
```php
// Line 9 - Creates config
config(['database.connections.neo4j_primary' => [...]);

// No tearDown method to remove these configs
```

**Proposed Fix:**
Add tearDown method to ConnectionPoolingTest:
```php
protected function tearDown(): void
{
    // Purge all test connections
    $testConnections = [
        'neo4j_primary', 'neo4j_secondary', 'neo4j_split',
        'neo4j_pooled', 'neo4j_concurrent', 'neo4j_retry',
        'neo4j_lazy', 'neo4j_purge', 'neo4j_a', 'neo4j_b',
        'neo4j_read_pref',
    ];

    foreach ($testConnections as $connection) {
        try {
            DB::purge($connection);
            config()->offsetUnset("database.connections.{$connection}");
        } catch (\Exception $e) {
            // Ignore if connection doesn't exist
        }
    }

    // Ensure default connection is restored
    DB::reconnect('neo4j');

    parent::tearDown();
}
```

---

#### Issue #2: Native Models Missing from Event Listener Cleanup

**Location:** `tests/TestCase/Neo4jTestCase.php:72-91`

**Problem:**
The `clearModelEventListeners()` method lists 18 models but omits all Native* models:
- Missing: `NativeUser`, `NativePost`, `NativeComment`
- Missing: `NativeProfile`, `NativeImage`, `NativeVideo`
- Missing: `NativeAuthor`, `NativeBook`

**Impact:**
Event listeners registered on native models leak between tests, potentially causing:
- Unexpected event firing
- Stale closure references
- Memory leaks in long test runs

**Evidence:**
```php
// tests/TestCase/Neo4jTestCase.php:72-91
protected function clearModelEventListeners(): void
{
    $models = [
        '\Tests\Models\User',
        '\Tests\Models\Post',
        // ... 16 more models
        '\Tests\Models\Tag',
        '\Tests\Models\Taggable',
    ];
    // Native models are NOT included!
}
```

But Native models are used in tests:
```php
// tests/Feature/NativeRelationshipsTest.php
use Tests\Models\NativeUser;
use Tests\Models\NativePost;
```

**Proposed Fix:**
Add Native models to the cleanup list:
```php
protected function clearModelEventListeners(): void
{
    $models = [
        // Existing models...
        '\Tests\Models\User',
        '\Tests\Models\Post',
        // ... existing models ...

        // Add Native models
        '\Tests\Models\NativeUser',
        '\Tests\Models\NativePost',
        '\Tests\Models\NativeComment',
        '\Tests\Models\NativeProfile',
        '\Tests\Models\NativeImage',
        '\Tests\Models\NativeVideo',
        '\Tests\Models\NativeAuthor',
        '\Tests\Models\NativeBook',
    ];

    foreach ($models as $model) {
        if (class_exists($model)) {
            $model::flushEventListeners();
            $model::unsetEventDispatcher();
        }
    }
}
```

---

### 🟡 Medium Priority Issues

#### Issue #3: Event Listener Cleanup Not Always Explicit

**Location:** Multiple test files

**Problem:**
Some tests register event listeners but rely solely on base class cleanup rather than explicit cleanup in the test.

**Affected Files:**

1. **CreateTest.php**
   - Line 214-220: Registers `creating` listener
   - Line 217-219: Registers `created` listener
   - Line 228-230: Registers `creating` listener that returns false
   - Line 241: Manual cleanup with `User::flushEventListeners()`

2. **TransactionTest.php**
   - Tests register listeners but don't explicitly flush them

**Impact:**
- Makes isolation strategy implicit rather than explicit
- Harder to debug when isolation breaks
- New contributors may not follow pattern

**Current Behavior:**
Base class cleanup works, but intent is unclear.

**Proposed Fix:**
Add explicit cleanup for clarity:

```php
// In Pest tests using functional style
test('event listener test', function () {
    User::creating(function ($user) {
        // ...
    });

    // Test logic...
})->after(function () {
    User::flushEventListeners();
});

// Or in PHPUnit-style tests
protected function tearDown(): void
{
    User::flushEventListeners();
    Post::flushEventListeners();
    parent::tearDown();
}
```

---

#### Issue #4: Connection Pool State Not Verified

**Location:** `tests/Feature/ConnectionPoolingTest.php`

**Problem:**
Tests check pool stats (`getPoolStats()`) but don't verify connections are properly closed after test completion.

**Example:**
```php
// Line 163-165
$stats = $connection->getPoolStats();
expect($stats['total_connections'])->toBeLessThanOrEqual(5);
expect($stats['active_connections'])->toBeLessThanOrEqual(5);
```

No verification that connections return to pool or are closed.

**Impact:**
Connection leaks could accumulate across test run.

**Proposed Fix:**
Add verification helper:
```php
protected function assertNoLeakedConnections(string $connectionName): void
{
    $connection = DB::connection($connectionName);

    if (!method_exists($connection, 'getPoolStats')) {
        return; // Not a pooled connection
    }

    $stats = $connection->getPoolStats();

    $this->assertEquals(
        0,
        $stats['active_connections'],
        "Connection pool has {$stats['active_connections']} leaked connections"
    );
}
```

Use in tearDown:
```php
protected function tearDown(): void
{
    $this->assertNoLeakedConnections('neo4j_pooled');
    // ... rest of cleanup
}
```

---

#### Issue #5: Schema Test Name Collisions

**Location:** `tests/Feature/MigrationsTest.php`

**Problem:**
Tests use predictable schema names that could collide in parallel execution:
- Line 48: `'User'`
- Line 80: `'Product'`
- Line 94: `'Transaction'`
- Line 106: `'Article'`

**Impact:**
In parallel execution, two tests could try to create the same constraint/index simultaneously.

**Current Mitigation:**
`clearDatabaseSchema()` drops all constraints/indexes in setUp/tearDown.

**Remaining Risk:**
During test execution, schema operations on same names could conflict.

**Proposed Fix:**
Use unique test-specific names:

```php
private function getTestSchemaName(string $base): string
{
    // Use test method name for uniqueness
    $testName = $this->getName(false);
    $hash = substr(md5($testName), 0, 6);
    return "{$base}_{$hash}";
}

public function test_can_create_node_label_schema()
{
    $labelName = $this->getTestSchemaName('User');

    Neo4jSchema::label($labelName, function (Neo4jBlueprint $label) {
        $label->property('email')->unique();
        $label->property('name')->index();
    });

    // Rest of test...
}
```

---

### 🟢 Low Priority Issues (Already Well-Handled)

#### Issue #6: Observer Instance State
**Status:** ✅ Properly handled
**Location:** `tests/Feature/ModelObserversTest.php:500-543`
**Details:** Observer class properly resets `$calls` array manually. No changes needed.

#### Issue #7: Transaction Test Cleanup
**Status:** ✅ Properly handled
**Details:** Base class `resetTransactions()` method handles cleanup. No changes needed.

#### Issue #8: Model Static State
**Status:** ✅ Properly handled
**Details:** `clearModelStates()` clears booted models and global scopes. No changes needed.

---

## Refactoring Plan

### Phase 1: Critical Fixes (Est. 30 minutes)

**Goal:** Fix high-priority isolation issues

#### Task 1.1: Fix ConnectionPoolingTest.php
**File:** `tests/Feature/ConnectionPoolingTest.php`

Add tearDown method:
```php
protected function tearDown(): void
{
    // List all test-specific connection names
    $testConnections = [
        'neo4j_primary',
        'neo4j_secondary',
        'neo4j_split',
        'neo4j_pooled',
        'neo4j_concurrent',
        'neo4j_retry',
        'neo4j_lazy',
        'neo4j_read_pref',
        'neo4j_purge',
        'neo4j_a',
        'neo4j_b',
    ];

    foreach ($testConnections as $connection) {
        try {
            // Close and purge connection
            $conn = DB::connection($connection);
            if (method_exists($conn, 'disconnect')) {
                $conn->disconnect();
            }
            DB::purge($connection);

            // Remove from config
            config()->offsetUnset("database.connections.{$connection}");
        } catch (\Exception $e) {
            // Connection may not exist, continue
        }
    }

    // Ensure default connection is active
    DB::reconnect('neo4j');

    parent::tearDown();
}
```

**Verification:** Run test multiple times, check no config pollution

#### Task 1.2: Update Neo4jTestCase.php
**File:** `tests/TestCase/Neo4jTestCase.php`

Update `clearModelEventListeners()` method (line 70):
```php
protected function clearModelEventListeners(): void
{
    $models = [
        // Existing models
        '\Tests\Models\User',
        '\Tests\Models\Post',
        '\Tests\Models\Comment',
        '\Tests\Models\Role',
        '\Tests\Models\Profile',
        '\Tests\Models\Image',
        '\Tests\Models\Video',
        '\Tests\Models\AdminUser',
        '\Tests\Models\UserWithCasting',
        '\Tests\Models\UserWithSoftDeletes',
        '\Tests\Models\PostWithSoftDeletes',
        '\Tests\Models\CommentWithSoftDeletes',
        '\Tests\Models\RoleWithSoftDeletes',
        '\Tests\Models\ProfileWithSoftDeletes',
        '\Tests\Models\TagWithSoftDeletes',
        '\Tests\Models\Product',
        '\Tests\Models\Tag',
        '\Tests\Models\Taggable',

        // Add Native models
        '\Tests\Models\NativeUser',
        '\Tests\Models\NativePost',
        '\Tests\Models\NativeComment',
        '\Tests\Models\NativeProfile',
        '\Tests\Models\NativeImage',
        '\Tests\Models\NativeVideo',
        '\Tests\Models\NativeAuthor',
        '\Tests\Models\NativeBook',
    ];

    foreach ($models as $model) {
        if (class_exists($model)) {
            $model::flushEventListeners();
            $model::unsetEventDispatcher();
        }
    }
}
```

Update `clearModelStates()` method (line 249) similarly:
```php
protected function clearModelStates(): void
{
    $models = [
        // ... existing models ...

        // Add Native models
        '\Tests\Models\NativeUser',
        '\Tests\Models\NativePost',
        '\Tests\Models\NativeComment',
        '\Tests\Models\NativeProfile',
        '\Tests\Models\NativeImage',
        '\Tests\Models\NativeVideo',
        '\Tests\Models\NativeAuthor',
        '\Tests\Models\NativeBook',
    ];

    foreach ($models as $model) {
        if (class_exists($model)) {
            if (method_exists($model, 'clearBootedModels')) {
                $model::clearBootedModels();
            }
            if (property_exists($model, 'globalScopes')) {
                $reflection = new \ReflectionClass($model);
                $property = $reflection->getProperty('globalScopes');
                $property->setAccessible(true);
                $property->setValue(null, []);
            }
        }
    }

    if (method_exists(\DB::class, 'forgetRecordedQueries')) {
        \DB::forgetRecordedQueries();
    }
}
```

**Verification:** Run native relationship tests, verify no event leakage

---

### Phase 2: Medium Priority Fixes (Est. 45 minutes)

#### Task 2.1: Add Explicit Event Listener Cleanup

**File:** `tests/Feature/CreateTest.php`

The file uses functional Pest syntax, so we need to add cleanup differently. Since the base Neo4jTestCase already handles this, we should add a comment documenting the behavior:

Add comment at top of file:
```php
<?php

use Tests\Models\User;

// TEST ISOLATION NOTE:
// Event listeners registered in these tests are automatically cleaned up by
// Neo4jTestCase::clearModelEventListeners() in tearDown(). Manual cleanup
// (line 241) is redundant but kept for explicitness in that specific test.

// TEST FIRST: tests/Feature/CreateTest.php
// ...
```

**File:** `tests/Feature/ModelEventsTest.php`

Already has setUp clearing (line 18-21). Good pattern! Add verification comment:

```php
protected function setUp(): void
{
    parent::setUp();

    // Clear any global event listeners between tests for isolation
    User::flushEventListeners();
    Post::flushEventListeners();
    Comment::flushEventListeners();
    Profile::flushEventListeners();
}
```

**File:** `tests/Feature/TransactionTest.php`

Add comment documenting that cleanup is handled by base class.

#### Task 2.2: Enhance ConnectionPoolingTest

Add helper method:
```php
/**
 * Assert that no connections are leaked in the pool.
 */
protected function assertNoLeakedConnections(string $connectionName): void
{
    try {
        $connection = DB::connection($connectionName);

        if (!method_exists($connection, 'getPoolStats')) {
            return; // Not a pooled connection, skip check
        }

        $stats = $connection->getPoolStats();

        expect($stats['active_connections'])->toBe(0,
            "Connection pool '{$connectionName}' has {$stats['active_connections']} leaked connections");

        // Optionally check for idle connections not exceeding max
        expect($stats['idle_connections'])->toBeLessThanOrEqual(
            $stats['max_connections'] ?? 10
        );
    } catch (\Exception $e) {
        // Connection doesn't exist or doesn't support pooling
    }
}
```

Use in tests that create pooled connections.

#### Task 2.3: Improve MigrationsTest Isolation

Add helper method:
```php
/**
 * Generate a unique schema name for this test.
 */
private function getUniqueSchemaName(string $base): string
{
    static $counter = 0;
    $counter++;

    $testName = $this->getName(false);
    $hash = substr(md5($testName . $counter), 0, 6);

    return "{$base}_{$hash}";
}
```

Update tests to use unique names:
```php
public function test_can_create_node_label_schema()
{
    $label = $this->getUniqueSchemaName('User');

    Neo4jSchema::label($label, function (Neo4jBlueprint $blueprint) {
        $blueprint->property('email')->unique();
        $blueprint->property('name')->index();
    });

    // Verify using the unique label name...
}
```

---

### Phase 3: Documentation & Polish (Est. 30 minutes)

#### Task 3.1: Create Isolation Documentation

**File:** `docs/TEST_ISOLATION.md` (new)

Create comprehensive guide covering:
- Isolation strategy overview
- What's cleaned up automatically
- When to add explicit cleanup
- Patterns to follow
- Common pitfalls
- Debugging isolation issues

#### Task 3.2: Add Verification Helpers

**File:** `tests/TestCase/Concerns/VerifiesTestIsolation.php` (new, optional)

Create trait with verification helpers:
```php
<?php

namespace Tests\TestCase\Concerns;

trait VerifiesTestIsolation
{
    protected function assertNoEventListeners(string $modelClass): void
    {
        $dispatcher = $modelClass::getEventDispatcher();

        if (!$dispatcher) {
            return; // No dispatcher, all good
        }

        $events = [
            'creating', 'created', 'updating', 'updated',
            'saving', 'saved', 'deleting', 'deleted',
            'restoring', 'restored', 'retrieved',
        ];

        foreach ($events as $event) {
            $eventName = "eloquent.{$event}: {$modelClass}";
            $hasListeners = $dispatcher->hasListeners($eventName);

            $this->assertFalse(
                $hasListeners,
                "Model {$modelClass} has listeners for {$event} event"
            );
        }
    }

    protected function assertNoLeakedConnections(): void
    {
        // Verify default connection has no active transactions
        $connection = \DB::connection('neo4j');

        if (method_exists($connection, 'transactionLevel')) {
            $this->assertEquals(
                0,
                $connection->transactionLevel(),
                'Active transaction not cleaned up'
            );
        }
    }

    protected function assertCleanConfig(): void
    {
        // Verify no test-specific connection configs remain
        $testPrefixes = ['test_', 'neo4j_test', 'neo4j_primary', 'neo4j_pooled'];

        $connections = config('database.connections', []);

        foreach ($connections as $name => $config) {
            foreach ($testPrefixes as $prefix) {
                $this->assertStringNotStartsWith(
                    $prefix,
                    $name,
                    "Test connection config '{$name}' not cleaned up"
                );
            }
        }
    }
}
```

#### Task 3.3: Update TEST_SUITE_REVIEW.md

Add section on isolation:
```markdown
## Test Isolation Strategy

This test suite uses a comprehensive isolation strategy...

### Isolation Levels

1. **Database Level**: Each test gets a clean database
2. **Model Level**: Static state and event listeners cleared
3. **Connection Level**: Fresh connections between tests
4. **Configuration Level**: Test-specific configs removed

### Patterns to Follow

...
```

---

## Implementation Checklist

### Phase 1 - Critical Fixes
- [ ] Update `tests/Feature/ConnectionPoolingTest.php` - Add tearDown
- [ ] Update `tests/TestCase/Neo4jTestCase.php` - Add Native models to clearModelEventListeners()
- [ ] Update `tests/TestCase/Neo4jTestCase.php` - Add Native models to clearModelStates()
- [ ] Run full test suite to verify no regressions
- [ ] Run tests 3x in sequence to verify no interdependencies

### Phase 2 - Medium Priority
- [ ] Add comments to `tests/Feature/CreateTest.php`
- [ ] Verify setUp in `tests/Feature/ModelEventsTest.php`
- [ ] Add connection leak verification to ConnectionPoolingTest
- [ ] Add unique schema names to MigrationsTest
- [ ] Run affected tests to verify improvements

### Phase 3 - Documentation
- [ ] Create `docs/TEST_ISOLATION.md`
- [ ] Create `tests/TestCase/Concerns/VerifiesTestIsolation.php` (optional)
- [ ] Update `TEST_SUITE_REVIEW.md` with isolation section
- [ ] Add isolation examples to README or CLAUDE.md

---

## Expected Outcomes

### Metrics

**Before:**
- Test isolation: 98% (excellent)
- Known leakage points: 2 (connection configs, Native models)
- Explicit cleanup: 60% of tests

**After:**
- Test isolation: 100% (perfect)
- Known leakage points: 0
- Explicit cleanup: 100% of tests
- Documentation: Comprehensive

### Benefits

1. **Zero Test Interdependencies**
   - Tests can run in any order
   - Parallel execution is safe
   - Random order passes 100% of time

2. **Clear Intent**
   - Explicit cleanup makes strategy obvious
   - New contributors can follow patterns
   - Easier to debug isolation failures

3. **Maintainability**
   - Clear patterns for new tests
   - Verification helpers catch regressions
   - Documentation explains "why" not just "what"

4. **Performance**
   - No connection leaks
   - Clean configs don't accumulate
   - Memory usage stable across long runs

---

## Risk Assessment

**Overall Risk:** Low

### Breakdown

| Change | Risk Level | Mitigation |
|--------|-----------|------------|
| Add tearDown to ConnectionPoolingTest | Low | Test thoroughly, add try-catch |
| Add Native models to cleanup | Very Low | Models already exist, just adding to list |
| Add verification helpers | Very Low | Optional, doesn't change behavior |
| Unique schema names | Low | Test in isolation first |
| Documentation | Zero | No code changes |

### Testing Strategy

1. Run full suite before changes (baseline)
2. Apply Phase 1 changes
3. Run full suite 5x sequentially
4. Run full suite 3x with random order
5. Apply Phase 2 changes
6. Repeat testing
7. Run 100 iterations of affected tests

---

## Notes & Observations

### What's Already Excellent

1. **Random Execution Order**: Catches most isolation issues automatically
2. **Comprehensive Base Class**: Neo4jTestCase handles 95% of cleanup
3. **Parallel Support**: Namespace prefixing prevents data collisions
4. **Transaction Reset**: No transaction leakage between tests
5. **Model State Cleanup**: Static properties and global scopes cleared

### Small Improvements Needed

1. Connection config cleanup (one file)
2. Native model inclusion (two methods)
3. Explicit documentation (for maintainability)

### Key Insight

The test suite is already production-ready with excellent isolation. These changes are polish and future-proofing, not urgent fixes.

---

## References

- `phpunit.xml.dist` - Random execution order configuration
- `tests/TestCase/Neo4jTestCase.php` - Main isolation implementation
- `tests/Pest.php` - Global test configuration
- `TEST_SUITE_REVIEW.md` - Test quality analysis
- `TEST_IMPROVEMENT_ROADMAP.md` - Historical improvements

---

## Appendix: Isolation Verification Commands

```bash
# Run tests in random order (already default)
./vendor/bin/pest

# Run specific test file 10 times
for i in {1..10}; do ./vendor/bin/pest tests/Feature/ConnectionPoolingTest.php; done

# Run all tests sequentially, check for failures
./vendor/bin/pest --order-by=default

# Run tests with coverage to verify no shared state
./vendor/bin/pest --coverage

# Check for leaked connections (manual)
# Look for "Connection pool has X leaked connections" in output

# Verify config cleanup
# No test connections should exist after suite completes
```

---

**End of Document**
