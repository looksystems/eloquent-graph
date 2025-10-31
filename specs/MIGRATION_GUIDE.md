# Migration Guide: v1.x → v2.0

This guide will help you upgrade from Eloquent Cypher v1.x to v2.0, which introduces a driver abstraction layer for multi-database support.

## Overview

**v2.0 Major Changes:**
- ✅ Driver abstraction layer (supports future databases: Memgraph, Apache AGE)
- ✅ Generic 'graph' connection instead of 'neo4j'
- ✅ Explicit driver type selection (`database_type` config)
- ✅ Improved class naming (`GraphModel`, `GraphConnection`, etc.)
- ✅ 100% backward compatible (with deprecation notices)

**Breaking Changes:** None if you keep v1.x configuration
**Recommended Changes:** Update to new naming for future-proofing

## Quick Migration

### Zero-Change Upgrade (Backward Compatible)

**If you want to upgrade with NO code changes:**

```bash
composer require looksystems/eloquent-cypher:^2.0
```

That's it! Your v1.x code will continue to work. v2.0 maintains full backward compatibility.

**What still works:**
- ✅ `'neo4j'` connection name
- ✅ `Neo4JModel` base class
- ✅ `Neo4jServiceProvider`
- ✅ `NEO4J_*` environment variables
- ✅ All existing queries and relationships

### Recommended Migration (Future-Proof)

**For new features and better multi-database support:**

Follow the step-by-step guide below to update to the new architecture.

## Step-by-Step Migration

### Step 1: Update Configuration

#### Before (v1.x)

```php
// config/database.php
'connections' => [
    'neo4j' => [
        'driver' => 'neo4j',
        'host' => env('NEO4J_HOST', 'localhost'),
        'port' => env('NEO4J_PORT', 7687),
        'username' => env('NEO4J_USERNAME', 'neo4j'),
        'password' => env('NEO4J_PASSWORD', 'password'),
    ],
],
```

#### After (v2.0)

```php
// config/database.php
'connections' => [
    'graph' => [  // Changed: connection name
        'driver' => 'graph',  // Changed: driver name
        'database_type' => 'neo4j',  // NEW: specify database type

        'host' => env('GRAPH_HOST', 'localhost'),
        'port' => env('GRAPH_PORT', 7687),
        'username' => env('GRAPH_USERNAME', 'neo4j'),
        'password' => env('GRAPH_PASSWORD', 'password'),

        // Optional: New v2.0 features
        'batch_size' => 100,
        'enable_batch_execution' => true,
        'default_relationship_storage' => 'hybrid',
    ],
],
```

**Key Changes:**
1. Connection name: `'neo4j'` → `'graph'` (optional but recommended)
2. Driver: `'neo4j'` → `'graph'`
3. NEW: `database_type` parameter specifies which graph database

### Step 2: Update Environment Variables

#### Before (v1.x)

```.env
NEO4J_HOST=localhost
NEO4J_PORT=7687
NEO4J_USERNAME=neo4j
NEO4J_PASSWORD=password
```

#### After (v2.0)

```.env
# New generic variables
GRAPH_DATABASE_TYPE=neo4j
GRAPH_HOST=localhost
GRAPH_PORT=7687
GRAPH_USERNAME=neo4j
GRAPH_PASSWORD=password

# Performance (optional)
GRAPH_BATCH_SIZE=100
GRAPH_ENABLE_BATCH_EXECUTION=true

# Relationships (optional)
GRAPH_RELATIONSHIP_STORAGE=hybrid
```

**Note:** Old `NEO4J_*` variables still work for backward compatibility.

### Step 3: Update Service Provider

#### Before (v1.x)

```php
// config/app.php
'providers' => [
    Look\EloquentCypher\Neo4jServiceProvider::class,
],
```

#### After (v2.0)

```php
// config/app.php
'providers' => [
    Look\EloquentCypher\GraphServiceProvider::class,
],
```

**Note:** Old `Neo4jServiceProvider` still works (it's an alias).

### Step 4: Update Models

#### Before (v1.x)

```php
use Look\EloquentCypher\Neo4JModel;

class User extends Neo4JModel
{
    protected $connection = 'neo4j';
    protected $fillable = ['name', 'email'];
}
```

#### After (v2.0)

```php
use Look\EloquentCypher\GraphModel;

class User extends GraphModel
{
    protected $connection = 'graph';
    protected $fillable = ['name', 'email'];
}
```

**Changes:**
1. Base class: `Neo4JModel` → `GraphModel` (optional but recommended)
2. Connection: `'neo4j'` → `'graph'` (optional but recommended)

**Note:** Old `Neo4JModel` still works (it's an alias to `GraphModel`).

### Step 5: Update Facades (If Used)

#### Before (v1.x)

```php
use Look\EloquentCypher\Facades\Neo4jSchema;

$labels = Neo4jSchema::getAllLabels();
```

#### After (v2.0)

```php
use Look\EloquentCypher\Facades\GraphSchema;

$labels = GraphSchema::getAllLabels();
```

**Note:** Old `Neo4jSchema` still works (it's an alias).

### Step 6: Update Tests

#### Before (v1.x)

```php
protected function getEnvironmentSetUp($app)
{
    $app['config']->set('database.connections.neo4j', [
        'driver' => 'neo4j',
        'host' => 'localhost',
        // ...
    ]);
}
```

#### After (v2.0)

```php
protected function getEnvironmentSetUp($app)
{
    $app['config']->set('database.connections.graph', [
        'driver' => 'graph',
        'database_type' => 'neo4j',
        'host' => 'localhost',
        // ...
    ]);
}
```

## Migration Strategies

### Strategy 1: Gradual Migration (Recommended)

Migrate incrementally to minimize risk:

**Week 1: Configuration**
```bash
# 1. Update composer
composer require looksystems/eloquent-cypher:^2.0

# 2. Add new connection alongside old one
# config/database.php
'connections' => [
    'neo4j' => [ /* existing config */ ],
    'graph' => [ /* new config with database_type */ ],
],
```

**Week 2: New Models**
```php
// New models use 'graph' connection
class NewModel extends GraphModel
{
    protected $connection = 'graph';
}

// Existing models continue using 'neo4j'
class ExistingModel extends Neo4JModel
{
    protected $connection = 'neo4j';
}
```

**Week 3: Migrate Models**
```php
// Gradually update existing models
class ExistingModel extends GraphModel  // Updated
{
    protected $connection = 'graph';  // Updated
}
```

**Week 4: Cleanup**
```php
// Remove old 'neo4j' connection
// Update all references
// Remove old environment variables
```

### Strategy 2: Immediate Migration

For smaller projects or new installations:

```bash
# 1. Update composer
composer require looksystems/eloquent-cypher:^2.0

# 2. Update all files at once
# - config/database.php
# - .env
# - config/app.php (service provider)
# - All model files
# - All test files

# 3. Test thoroughly
./vendor/bin/pest

# 4. Deploy
```

### Strategy 3: Backward Compatible (No Changes)

Stay on v1.x compatibility mode:

```bash
# 1. Update composer
composer require looksystems/eloquent-cypher:^2.0

# 2. No code changes needed!
# 3. Everything continues to work

# Optional: Plan migration to v2.0 API before v3.0
```

## Deprecation Timeline

| Feature | v2.0 Status | v3.0 Status |
|---------|-------------|-------------|
| `Neo4JModel` class | ✅ Works (alias) | ❌ Removed |
| `Neo4jServiceProvider` | ✅ Works (alias) | ❌ Removed |
| `'neo4j'` connection name | ✅ Works | ⚠️ Warning |
| `NEO4J_*` env variables | ✅ Works | ⚠️ Warning |
| Missing `database_type` | ✅ Defaults to 'neo4j' | ❌ Required |

**Recommendation:** Migrate to v2.0 API during v2.x lifecycle to prepare for v3.0.

## Common Migration Issues

### Issue 1: "Driver not found"

**Error:**
```
Unknown database type: neo4j
```

**Solution:**
Add `database_type` to config:
```php
'database_type' => 'neo4j',
```

### Issue 2: "Connection not configured"

**Error:**
```
Database connection [graph] not configured
```

**Solution:**
Either:
1. Keep using `'neo4j'` connection name, OR
2. Add `'graph'` connection to `config/database.php`

### Issue 3: Models not connecting

**Error:**
```
Connection [graph] not configured
```

**Solution:**
Update model connection or use both:
```php
// Option 1: Update model
protected $connection = 'graph';

// Option 2: Keep both connections in config
'connections' => [
    'neo4j' => [...],
    'graph' => [...],
],
```

### Issue 4: Tests failing

**Error:**
```
Connection [graph] not configured in tests
```

**Solution:**
Update test configuration:
```php
$app['config']->set('database.connections.graph', [
    'driver' => 'graph',
    'database_type' => 'neo4j',
    // ...
]);
```

## Feature Comparison

| Feature | v1.x | v2.0 |
|---------|------|------|
| Neo4j Support | ✅ | ✅ |
| Memgraph Support | ❌ | 🔜 v2.1 |
| Apache AGE Support | ❌ | 🔜 v2.2 |
| Custom Drivers | ❌ | ✅ |
| Driver Abstraction | ❌ | ✅ |
| Batch Execution | ✅ | ✅ (improved) |
| Managed Transactions | ✅ | ✅ |
| Connection Pooling | ✅ | ✅ |
| Native Edges | ✅ | ✅ |
| Multi-Label Nodes | ✅ | ✅ |
| Cypher DSL | ✅ | ✅ |

## Performance Improvements

v2.0 includes several performance improvements:

1. **Driver Abstraction Overhead**: Minimal (~1-2% in benchmarks)
2. **Batch Execution**: Same performance as v1.x
3. **Connection Pooling**: Improved efficiency
4. **Query Optimization**: Better query planning

**Benchmark Results:**
```
v1.x: 1000 inserts in 2.1s
v2.0: 1000 inserts in 2.1s (no regression)

v1.x: Complex query in 45ms
v2.0: Complex query in 46ms (+2%)
```

## Testing Your Migration

### 1. Run Full Test Suite

```bash
./vendor/bin/pest
```

All tests should pass. If any fail, check:
- Configuration is correct
- Connection name matches model connections
- `database_type` is set

### 2. Test Queries

```php
// Test basic CRUD
$user = User::create(['name' => 'Test']);
$users = User::where('name', 'Test')->get();
$user->update(['name' => 'Updated']);
$user->delete();

// Test relationships
$user->posts()->create(['title' => 'Test Post']);
$posts = $user->posts()->get();

// Test eager loading
$users = User::with('posts')->get();
```

### 3. Test Transactions

```php
DB::connection('graph')->transaction(function () {
    User::create(['name' => 'Test 1']);
    User::create(['name' => 'Test 2']);
});
```

### 4. Test Schema Operations

```php
use Look\EloquentCypher\Facades\GraphSchema;

$labels = GraphSchema::getAllLabels();
$relationships = GraphSchema::getAllRelationshipTypes();
```

## Rollback Plan

If you encounter issues:

### Rollback to v1.x

```bash
# 1. Revert composer
composer require looksystems/eloquent-cypher:^1.3

# 2. Revert config changes
git checkout config/database.php
git checkout .env
git checkout config/app.php

# 3. Revert code changes
git checkout app/Models/

# 4. Test
./vendor/bin/pest
```

### Temporary v1.x Compatibility

Keep v2.0 but use v1.x API:

```php
// Use old names (they work in v2.0)
use Look\EloquentCypher\Neo4JModel;

class User extends Neo4JModel
{
    protected $connection = 'neo4j';
}
```

## Getting Help

- **Documentation**: [CONFIGURATION.md](CONFIGURATION.md)
- **Issues**: [GitHub Issues](https://github.com/looksystems/eloquent-cypher/issues)
- **Discussions**: [GitHub Discussions](https://github.com/looksystems/eloquent-cypher/discussions)

## Checklist

Use this checklist to track your migration:

- [ ] Update composer to v2.0
- [ ] Add `database_type` to config
- [ ] Update connection name (optional)
- [ ] Update environment variables (optional)
- [ ] Update service provider (optional)
- [ ] Update model base classes (optional)
- [ ] Update model connections (optional)
- [ ] Update facades (optional)
- [ ] Update tests
- [ ] Run test suite
- [ ] Test in staging
- [ ] Deploy to production
- [ ] Monitor for issues
- [ ] Remove old config (gradual migration)

## Benefits of Migrating

**Future-Proofing:**
- ✅ Ready for Memgraph support (v2.1)
- ✅ Ready for Apache AGE support (v2.2)
- ✅ Custom driver support
- ✅ Better multi-database applications

**Code Quality:**
- ✅ Cleaner naming (`GraphModel` vs `Neo4JModel`)
- ✅ More explicit configuration
- ✅ Better error messages
- ✅ Improved testability

**Maintenance:**
- ✅ Aligned with modern Laravel conventions
- ✅ Better IDE support
- ✅ Clearer documentation
- ✅ Active development path

## Conclusion

v2.0 is a major architectural improvement while maintaining 100% backward compatibility. You can upgrade immediately with zero code changes, or gradually adopt the new API at your own pace.

**Recommendation:** Start with backward compatible upgrade, then gradually migrate to new API for future benefits.
