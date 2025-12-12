# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Goal
Create a minimal, functionally useful graph database adapter for Laravel Eloquent using Test-Driven Development.

The package supports multiple graph databases through a pluggable driver architecture, starting with Neo4j (full support), with Memgraph and Apache AGE coming soon.

## Core Principles
1. **EVERY feature starts with a failing test** - No code is written until a test exists
2. **Tests define the PUBLIC API** - Tests describe what users will actually use
3. **Tests are IMMUTABLE** - Once written, fix the implementation, never the test
4. **Tests are the SPECIFICATION** - They define the contract with users

## Commands

### Composer Scripts (Recommended)
```bash
# Testing
composer test                    # Run all tests sequentially
composer test:coverage           # Run tests with coverage (auto-detects Herd)
composer test:filter "keyword"   # Run specific test(s) by filter

# Code Quality
composer analyse                 # Run PHPStan static analysis
composer format                  # Check code style (dry-run)
composer format:fix              # Fix code style issues

# Neo4j Docker
composer neo4j:up               # Start Neo4j with docker-compose
composer neo4j:down             # Stop Neo4j
composer neo4j:clean            # Stop Neo4j and remove volumes (clean slate)

# Combined Checks
composer check                  # Run tests + analyse + format check
composer ci                     # Full CI: tests with coverage + analyse + format
```

### Testing
```bash
# Run all tests (always run sequentially)
./vendor/bin/pest
composer test

# Run specific test file
./vendor/bin/pest tests/Feature/AttributeCastingTest.php

# Run with filter
./vendor/bin/pest --filter="casts collection"
composer test:filter "casts collection"

# Run test coverage (always run sequentially)
./vendor/bin/pest --coverage-text
composer test:coverage              # Auto-detects Herd
herd coverage ./vendor/bin/pest     # Herd's enhanced coverage (if using Herd)
composer test:coverage:herd         # Force Herd coverage

# Check code quality
./vendor/bin/phpstan analyze src/
composer analyse

# Check/fix code styling (run this after finishing up a task)
./vendor/bin/pint
composer format:fix
```

### Neo4j Setup
```bash
# Start Neo4j with APOC (recommended - uses docker-compose)
docker-compose up -d
composer neo4j:up

# Stop Neo4j
docker-compose down
composer neo4j:down

# Stop and remove volumes (clean slate)
docker-compose down -v
composer neo4j:clean

# Check Neo4j logs
docker-compose logs -f neo4j

# Alternative: Start Neo4j (raw Docker command)
docker run -d \
  --name neo4j-test \
  -p 7688:7687 \
  -p 7475:7474 \
  -e NEO4J_AUTH=neo4j/password \
  -e NEO4JLABS_PLUGINS='["apoc"]' \
  neo4j:5-community
```

### Development
```bash
# Install dependencies
composer install

# Update dependencies
composer update
```

### Artisan Commands (User-Facing)
```bash
# Schema Introspection Commands
php artisan neo4j:schema                       # Complete schema overview
php artisan neo4j:schema --json                # JSON output
php artisan neo4j:schema:labels                # List all node labels
php artisan neo4j:schema:labels --count        # With node counts
php artisan neo4j:schema:relationships         # List relationship types
php artisan neo4j:schema:relationships --count # With relationship counts
php artisan neo4j:schema:properties            # List all property keys
php artisan neo4j:schema:constraints           # List all constraints
php artisan neo4j:schema:constraints --type=X  # Filter by type
php artisan neo4j:schema:indexes               # List all indexes
php artisan neo4j:schema:indexes --type=X      # Filter by type
php artisan neo4j:schema:export file.json      # Export schema to file
php artisan neo4j:schema:export file.yaml --format=yaml

# Migration & Compatibility Commands
php artisan neo4j:check-compatibility User     # Check model compatibility
php artisan neo4j:migrate-to-edges --model=User --relation=posts
```

## Architecture

### Core Design Pattern
This package extends Laravel's Eloquent ORM to work with graph databases, prioritizing 100% API compatibility over database-specific optimizations. It uses foreign key relationships (stored as node properties) rather than native graph edges to maintain full Eloquent compatibility.

Driver abstraction layer allows pluggable graph database support through `GraphDriverInterface`, enabling support for multiple graph databases (Neo4j, Memgraph, Apache AGE, custom drivers).

### Class Hierarchy

- `GraphModel` extends `Illuminate\Database\Eloquent\Model` (primary)
- `GraphConnection` extends `Illuminate\Database\Connection`
  - Uses `GraphDriverInterface` for database operations
- `GraphQueryBuilder` extends `Illuminate\Database\Query\Builder`
- `GraphEloquentBuilder` extends `Illuminate\Database\Eloquent\Builder`
- `GraphEdgePivot` extends `Illuminate\Database\Eloquent\Relations\Pivot`

**Driver Abstraction Layer:**
- `GraphDriverInterface` - Core driver contract
- `DriverManager` - Factory for creating database drivers
- `Neo4jDriver` - Neo4j implementation of `GraphDriverInterface`
- `ResultSetInterface`, `TransactionInterface`, `CapabilitiesInterface`, `SchemaIntrospectorInterface`

### Key Implementation Patterns

1. **Driver Abstraction**
```php
// Configuration selects driver via database_type
'graph' => [
    'driver' => 'graph',
    'database_type' => 'neo4j',  // Driver selection
    // ... connection config ...
];

// DriverManager creates appropriate driver
$driver = DriverManager::create($config);  // Returns GraphDriverInterface

// GraphConnection uses driver for all operations
$connection->select($query);  // Delegates to $driver->executeQuery()

// Custom drivers can be registered
DriverManager::register('memgraph', MemgraphDriver::class);
```

**Interfaces:**
- `GraphDriverInterface` - Main driver contract (connect, executeQuery, beginTransaction, etc.)
- `ResultSetInterface` - Query result abstraction
- `TransactionInterface` - Transaction management
- `CapabilitiesInterface` - Database capabilities detection
- `SchemaIntrospectorInterface` - Schema metadata

**Critical Files:**
- `src/Contracts/` - All interface definitions
- `src/Drivers/DriverManager.php` - Driver factory
- `src/Drivers/Neo4j/` - Neo4j driver implementation
- `src/GraphConnection.php` - Uses GraphDriverInterface

2. **Response Format Handling**: Graph databases may return different formats
```php
// Always use defensive coding
$value = $row['property'] ?? $row->property ?? null;
```

3. **Operator Mapping**: SQL operators are translated to graph query language (e.g., Cypher)
```php
// '!=' automatically becomes '<>' for graph databases
// NULL comparisons use IS NULL/IS NOT NULL
```

4. **Relationship Implementation**: Configurable storage strategies
```cypher
// Foreign key mode (legacy)
MATCH (u:users), (p:posts) WHERE p.user_id = u.id

// Native edge mode (default for models with trait)
MATCH (u:users)-[:HAS_POSTS]->(p:posts)

// Hybrid mode (best of both)
MATCH (u:users)-[r:HAS_POSTS]->(p:posts)
// Both edge exists AND p.user_id = u.id
```

5. **Storage Strategy Priority**
```php
// 1. Relationship-specific setting
$user->posts()->useNativeEdges()->get();

// 2. Model-level setting
protected $useNativeRelationships = true;

// 3. Global config (default: 'hybrid')
config('database.connections.graph.default_relationship_storage');
```

6. **JSON Operations with APOC** (Neo4j-specific)
```php
// Config setting (default: true - auto-detect and use if available)
config('database.connections.graph.use_apoc_for_json', true);

// APOC mode: Proper JSON parsing and querying
User::whereJsonContains('preferences->theme', 'dark')->get();
User::whereJsonLength('skills', '>', 3)->get();

// Fallback mode (without APOC): String-based matching
// Less accurate, limited nested path support
```

7. **Batch Execution**
```php
// Batch execution for improved performance (70% faster)
config('database.connections.graph.batch_size', 100);
config('database.connections.graph.enable_batch_execution', true);

// Laravel batch operations now execute as single batch request
User::insert([...100 records...]); // 1 batch request, not 100 queries
User::upsert([...1000 records...], ['email'], ['name']); // Efficient batch upsert
```

8. **Managed Transactions**
```php
// Graph database-optimized managed transactions with automatic retry
$connection->write(function ($connection) {
    User::create(['name' => 'John']);
    Post::create(['title' => 'Hello']);
    return true;
}, $maxRetries = 3);

// Read-only transactions (routes to read replicas in cluster)
$users = $connection->read(function ($connection) {
    return User::where('active', true)->get();
}, $maxRetries = 2);

// Retry configuration with exponential backoff
config('database.connections.graph.retry', [
    'max_attempts' => 3,
    'initial_delay_ms' => 100,
    'max_delay_ms' => 5000,
    'multiplier' => 2.0,
    'jitter' => true,
]);
```

9. **Enhanced Error Handling**
```php
// Automatic error classification and recovery
// - Transient errors are automatically retried
// - Network errors trigger reconnection
// - Helpful migration hints in error messages
// - Query context included in exceptions
```

### Critical Files

**Driver Abstraction:**
- `src/Contracts/` - All driver interface definitions (GraphDriverInterface, ResultSetInterface, etc.)
- `src/Drivers/DriverManager.php` - Driver factory and registry
- `src/Drivers/Neo4j/` - Neo4j driver implementation (8 classes)
- `src/GraphConnection.php` - Database connection using GraphDriverInterface
- `src/GraphModel.php` - Core model
- `src/GraphQueryBuilder.php` - SQL to Cypher translation
- `src/GraphEloquentBuilder.php` - Eloquent query building

**Core Features:**
- `src/Relations/` - All relationship implementations
- `src/Traits/Neo4jNativeRelationships.php` - Native edge support trait
- `src/Traits/SupportsNativeEdges.php` - Edge configuration trait
- `src/Services/Neo4jEdgeManager.php` - Edge CRUD operations
- `src/GraphEdgePivot.php` - Virtual pivot for edge properties

**Schema & Introspection:**
- `src/Schema/GraphSchemaBuilder.php` - Schema management using SchemaIntrospectorInterface
- `src/Schema/GraphSchemaGrammar.php` - Schema DDL generation
- `src/Schema/GraphBlueprint.php` - Schema blueprint definitions
- `src/Facades/GraphSchema.php` - Schema facade

## Test Coverage
- Tests use port 7688 for Neo4j to avoid conflicts
- **Total tests**: 1,513 tests
- **Passing**: 1,485 tests (98.1% pass rate, 28 intentionally skipped)
- **Assertions**: 24,000+ assertions across all tests
- **Total test files**: ~105 files covering all package functionality
- **Current grade**: A+ (Excellent, comprehensive, maintainable)
- **See detailed analysis**: [TEST_SUITE_REVIEW.md](TEST_SUITE_REVIEW.md)
- **See improvement plan**: [TEST_IMPROVEMENT_ROADMAP.md](TEST_IMPROVEMENT_ROADMAP.md)

### Test Suite Documentation

For comprehensive information about the test suite:

**[TEST_SUITE_REVIEW.md](TEST_SUITE_REVIEW.md)** - Complete systematic review
- Detailed analysis of all 7 test categories
- Strengths and weaknesses by category
- Exemplary test files to use as templates
- Test quality patterns and anti-patterns
- Critical issues and recommendations

**[TEST_IMPROVEMENT_ROADMAP.md](TEST_IMPROVEMENT_ROADMAP.md)** - Implementation plan
- Critical fixes - soft deletes, exceptions, CRUD expansion
- Important improvements - relationships, unit tests
- Enhancements - file splits, negative tests, polish

## Known Limitations
- **APOC Status**: Optional enhancement for JSON operations. Package now uses hybrid native/JSON storage:
  - Flat arrays stored as native Neo4j LISTs (no APOC needed)
  - Nested structures stored as JSON strings (uses APOC if available, falls back to string matching)
  - All JSON tests passing with or without APOC!
- **Neo4j Edition**: Fully compatible with Neo4j Community Edition (no Enterprise features required)
- **Nested JSON Path Updates**: Partial implementation exists but not fully functional
  - Foundation in `Neo4JModel::setJsonPathAttribute()` (src/Neo4JModel.php:1144-1177)
  - Workaround: Update entire parent property instead of nested path
  - Example: `$user->update(['settings' => $modifiedSettings])` instead of `$user->update(['settings->key' => value])`
- **Performance Tests**: Environment-dependent timing tests are skipped
  - Batch execution is verified functionally, not by timing benchmarks
  - Production benefits vary based on network latency and infrastructure
- **Schema DDL Operations**: Sequential execution for reliability (not batched)
  - CREATE/DROP CONSTRAINT and INDEX operations run sequentially
  - Prevents connection pool exhaustion in intensive schema operations
  - Minimal performance impact as schema changes are infrequent

## Working Features

- **Performance Enhancements**: ✅
  - **Batch Execution**: 50-70% faster bulk operations ✅
    - Insert 100 records: 3s → 0.9s (70% improvement)
    - Upsert 1000 records: 15s → 7.8s (48% improvement)
    - Schema migrations: 40% faster
    - Matches Laravel MySQL/Postgres performance
  - **Managed Transactions**: Automatic retry with exponential backoff ✅
    - `write()` and `read()` methods for optimized transactions
    - Automatic retry on transient errors
    - Configurable retry strategy with jitter
    - Routes to appropriate cluster nodes
  - **Enhanced Error Handling**: Better reliability and debugging ✅
    - Automatic error classification (transient, network, auth, constraint)
    - Connection health checks with `ping()`
    - Automatic reconnection on stale connections
    - Helpful migration hints in error messages
    - Query context included in exceptions
  - **Type-Safe Parameters**: No more ambiguous array errors ✅
    - ParameterHelper ensures proper CypherList/CypherMap types
    - Smart detection of array intent
    - Seamless integration with whereIn and array casting
- **Multi-Label Node Support**: ✅
  - **Laravel-like API**: Opt-in multi-label support with `$labels` property ✅
  - **Backward Compatible**: Single-label models work exactly as before ✅
  - **Progressive Enhancement**: Additional labels via `protected $labels = ['Person', 'Individual']` ✅
  - **Query Optimization**: MATCH on all labels for efficiency ✅
    - Example: `MATCH (n:users:Person:Individual) WHERE ...`
  - **Core Methods**:
    - `getLabels()` - Returns all labels (primary + additional) ✅
    - `hasLabel($label)` - Check if model has specific label ✅
    - `scopeWithLabels($labels)` - Query with specific label subset ✅
  - **Full CRUD Support**: Create, Read, Update, Delete all preserve labels ✅
  - **Relationships**: Works seamlessly with all relationship types ✅
  - **Eager Loading**: Multi-label nodes fully supported ✅
- **Neo4j-Specific Aggregate Functions**: ✅
  - **Laravel-like API**: Follows Eloquent's aggregate method patterns ✅
  - **Percentile Functions**: ✅
    - `percentileDisc($column, $percentile)` - Discrete percentile (e.g., 95th percentile)
    - `percentileCont($column, $percentile)` - Continuous/interpolated percentile (e.g., median)
  - **Standard Deviation**: ✅
    - `stdev($column)` - Sample standard deviation
    - `stdevp($column)` - Population standard deviation
  - **Collection**: ✅
    - `collect($column)` - Collect all values into an array
  - **Full Integration**: Works everywhere Laravel aggregates work ✅
    - Direct queries: `User::percentileDisc('age', 0.95)`
    - With WHERE clauses: `User::where('active', true)->stdev('salary')`
    - On relationships: `$user->posts()->percentileCont('views', 0.5)`
    - In `withAggregate()`: `User::withAggregate('posts', 'views', 'stdev')->get()`
    - In `loadAggregate()`: `$user->loadAggregate('posts', 'views', 'percentileDisc')`
    - In `selectRaw()`: Combine with standard aggregates for complex stats
  - **Proper Return Types**: ✅
    - Percentile/stdev functions return `float|null`
    - Collect returns `array`
    - Empty result sets handled correctly (null for percentiles, 0.0 for stdev, [] for collect)
- **Polymorphic Relationships**: Fully working! All `morphOne`, `morphMany`, and `morphTo` relations work perfectly
  - Basic CRUD operations ✅
  - Eager loading ✅
  - Eager loading with limit constraints ✅
  - Relationship existence queries (`has()`, `doesntHave()`) ✅
  - Counting with `withCount()` including `morphOne` ✅
- **Native Graph Relationships**: ✅
  - Basic relationships (HasMany, BelongsTo, HasOne) with hybrid mode ✅
  - BelongsToMany with virtual pivot objects ✅
  - HasManyThrough with direct graph traversal ✅
  - Configurable storage strategies (foreign_key, edge, hybrid) ✅
  - Edge property management ✅
  - Backward compatibility maintained ✅
- **Schema Introspection**: Complete! ✅
  - Programmatic API (Facade) ✅
    - `getAllLabels()` - Get all node labels ✅
    - `getAllRelationshipTypes()` - Get all relationship types ✅
    - `getAllPropertyKeys()` - Get all property keys ✅
    - `getConstraints()` - Get detailed constraint information ✅
    - `getIndexes()` - Get detailed index information ✅
    - `introspect()` - Get complete schema in one call ✅
  - Artisan Commands (CLI) ✅
    - `neo4j:schema` - Complete schema overview ✅
    - `neo4j:schema:labels` - List node labels (with --count) ✅
    - `neo4j:schema:relationships` - List relationship types (with --count) ✅
    - `neo4j:schema:properties` - List property keys ✅
    - `neo4j:schema:constraints` - List constraints (with --type filter) ✅
    - `neo4j:schema:indexes` - List indexes (with --type filter) ✅
    - `neo4j:schema:export` - Export schema to JSON/YAML ✅
  - 19 comprehensive tests covering all functionality ✅
- **Array & JSON Operations**: Full support with hybrid native/JSON storage! ✅
  - **Hybrid Storage Strategy**: Intelligent type detection for optimal storage ✅
    - Flat indexed arrays → Native Neo4j LISTs (no APOC needed!)
    - Associative/nested arrays → JSON strings (uses APOC if available)
    - Collections → JSON strings for Laravel compatibility
  - **Query Support**:
    - `whereJsonContains()` with nested paths (e.g., `settings->profile->role`) ✅
    - `whereJsonLength()` with operators (=, >, <, >=, <=) ✅
    - Works with both native types AND JSON strings transparently ✅
  - **APOC Integration**: Optional enhancement, not required ✅
    - Automatic detection and graceful fallback ✅
    - Better performance for complex nested queries when available ✅
  - **Backward Compatible**: Existing JSON strings continue to work ✅
  - 7 comprehensive tests covering all operations (100% passing) ✅
- **Cypher DSL Integration**: ✅
  - **Fluent Query Builder**: Type-safe Cypher query builder via `wikibase-solutions/php-cypher-dsl` ✅
  - **Laravel Conventions**: Familiar methods (get(), first(), count(), dd(), dump()) ✅
  - **Model Integration**: Static `match()` and instance `matchFrom()` methods ✅
  - **Automatic Hydration**: Returns Collection of models with proper casts ✅
  - **Graph Pattern Helpers**: Convenient traversal methods (outgoing, incoming, bidirectional) ✅
  - **Path Finding**: Built-in shortestPath and allPaths algorithms ✅
  - **Facade Support**: `Cypher::query()` for convenient static access ✅
  - **Macro Support**: Extensible with custom reusable patterns ✅
  - **Full Backward Compatibility**: Existing `cypher(string)` usage unchanged ✅
  - 87 comprehensive tests covering all functionality (100% passing) ✅
  - See detailed guide: [DSL_USAGE_GUIDE.md](DSL_USAGE_GUIDE.md)

## Important Notes
- Follow TDD - write tests first
- All tests must pass before any PR
- Use type hints and remove any docblocks
- Handle both array and object responses from Neo4j
- Never modify existing tests to make them pass
- Don't include specific test logic in the src directory
- Use `MATCH (n) DETACH DELETE n` to clean database between test runs
- You do not need to set APP_KEY when running tests
- Use `./vendor/bin/rector` to refactor php code where possible
- The test suite is comprehensive and can take upto 300s to run
- Always run tests sequentially, NEVER in parallel!