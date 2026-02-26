# Eloquent Cypher - Graph Database for Laravel

> [!WARNING]
> This is an experimental/learning ai coding project only and is under development.

[![Latest Version](https://img.shields.io/packagist/v/looksystems/eloquent-cypher.svg)](https://packagist.org/packages/looksystems/eloquent-cypher)
[![MIT License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![Tests](https://img.shields.io/badge/tests-1520%20total%20(28%20skipped)-brightgreen.svg)](#testing)
[![Laravel 10.x-12.x](https://img.shields.io/badge/Laravel-10.x--12.x-FF2D20.svg)](https://laravel.com)
[![PHP 8.0+](https://img.shields.io/badge/PHP-8.0%2B-777BB4.svg)](https://php.net)

**A graph database adapter that's a true drop-in replacement for Laravel Eloquent.**

Switch your Laravel application from MySQL/PostgreSQL to graph databases with zero code changes. Keep using the Eloquent API you know and love while gaining the power of graph databases.

The project has approximately 1,520 tests with 24,000+ assertions across 152 test files.

## Table of Contents

- [Installation](#installation)
- [Docker Setup](#docker-setup-recommended)
- [Configuration](#configuration)
- [Quick Start](#quick-start)
- [Usage Examples](#usage-examples)
- [Features](#features)
- [Requirements](#requirements)
- [Documentation](#documentation)
- [Known Limitations](#known-limitations)
- [Testing](#testing)
- [Contributing](#contributing)
- [License](#license)

---

## Installation

```bash
composer require looksystems/eloquent-cypher
```

Register the service provider in `config/app.php`:

```php
'providers' => [
    Look\EloquentCypher\GraphServiceProvider::class,
],
```

## Docker Setup (Recommended)

```bash
# Using Docker Compose
docker-compose up -d

# Or using Docker run with custom ports
docker run -d \
  --name neo4j-test \
  -p 7688:7687 \
  -p 7475:7474 \
  -e NEO4J_AUTH=neo4j/password \
  neo4j:5-community
```

Note: Default test port is 7688. You can customize ports if needed.

## Configuration

Add your graph database connection to `config/database.php`:

```php
'connections' => [
    'graph' => [
        'driver' => 'graph',
        'database_type' => env('GRAPH_DATABASE_TYPE', 'neo4j'),

        // Connection details
        'host' => env('GRAPH_HOST', 'localhost'),
        'port' => env('GRAPH_PORT', 7687),
        'database' => env('GRAPH_DATABASE', 'neo4j'),
        'username' => env('GRAPH_USERNAME', 'neo4j'),
        'password' => env('GRAPH_PASSWORD', 'password'),

        // Performance
        'batch_size' => env('GRAPH_BATCH_SIZE', 100),
        'enable_batch_execution' => env('GRAPH_ENABLE_BATCH_EXECUTION', true),

        // Native Graph Relationships
        'default_relationship_storage' => env('GRAPH_RELATIONSHIP_STORAGE', 'hybrid'),
        'auto_create_edges' => true,
        'edge_naming_convention' => 'snake_case_plural', // e.g., HAS_POSTS

        // Retry configuration
        'retry' => [
            'max_attempts' => 3,
            'initial_delay_ms' => 100,
            'max_delay_ms' => 5000,
            'multiplier' => 2.0,
            'jitter' => true,
        ],
    ],
],
```

See [Configuration Guide](specs/CONFIGURATION.md) for all options including retry strategies and relationship storage modes.

## Quick Start

Change your model's base class from `Model` to `GraphModel`:

```php
use Look\EloquentCypher\GraphModel;

class User extends GraphModel
{
    protected $connection = 'graph';
    protected $fillable = ['name', 'email'];

    public function posts()
    {
        return $this->hasMany(Post::class);
    }
}
```

That's it! All your existing Eloquent code continues to work.

## Usage Examples

### Multi-Label Nodes

```php
class User extends GraphModel
{
    protected $connection = 'graph';
    protected $table = 'users';                    // Primary label
    protected $labels = ['Person', 'Individual'];  // Additional labels
}

// Creates node: (:users:Person:Individual)
$user = User::create(['name' => 'John', 'email' => 'john@example.com']);
$user->getLabels();        // ['users', 'Person', 'Individual']
$user->hasLabel('Person'); // true

// Query with specific labels
$users = User::withLabels(['users', 'Person'])->get();
```

All CRUD, relationships, and eager loading preserve labels automatically.

### Performance Features

#### Batch Operations
```php
// Bulk inserts now execute as single batch request (70% faster!)
User::insert([
    ['name' => 'John', 'email' => 'john@example.com'],
    ['name' => 'Jane', 'email' => 'jane@example.com'],
    // ... 100+ records
]); // Before: 100 queries, After: 1 batch request

// Efficient upserts with batch execution
User::upsert(
    [...1000 records...],
    ['email'],        // Unique by
    ['name', 'age']   // Update columns
); // 48% faster with batching
```

#### Managed Transactions with Automatic Retry
```php
// Graph database-optimized write transactions (automatic retry on transient errors)
$result = DB::connection('graph')->write(function ($connection) {
    $user = User::create(['name' => 'John']);
    $post = $user->posts()->create(['title' => 'Hello World']);
    return $post->id;
}, $maxRetries = 3);

// Read-only transactions (routes to read replicas in cluster)
$users = DB::connection('graph')->read(function ($connection) {
    return User::where('active', true)->with('posts')->get();
}, $maxRetries = 2);
```

#### Enhanced Error Handling
```php
// Automatic error classification and recovery
try {
    User::create(['name' => 'John']);
} catch (Neo4jTransientException $e) {
    // Automatically retried 3 times before throwing
    // Contains helpful context and hints
    echo $e->getQuery();      // The Cypher query that failed
    echo $e->getParameters();  // Parameters used
    echo $e->getHint();       // Helpful migration hints
}

// Connection health checks
if (!DB::connection('graph')->ping()) {
    DB::connection('graph')->reconnect();
}
```

> **Note:** Exception classes are database-specific (e.g., `Neo4jTransientException` for Neo4j driver).

### Native Graph Relationships

```php
use Look\EloquentCypher\GraphModel;
use Look\EloquentCypher\Traits\Neo4jNativeRelationships;

class User extends GraphModel
{
    use Neo4jNativeRelationships;

    protected $connection = 'graph';
    protected $useNativeRelationships = true; // Enable native edges globally

    public function posts()
    {
        return $this->hasMany(Post::class)
            ->useNativeEdges()  // Use graph edges instead of foreign keys
            ->withEdgeType('AUTHORED'); // Custom edge type (optional)
    }

    public function roles()
    {
        return $this->belongsToMany(Role::class)
            ->useNativeEdges()  // BelongsToMany with real edges!
            ->withPivot(['granted_at', 'expires_at']); // Edge properties
    }
}

// Native edge traversal for complex queries
$activeAuthors = User::whereHas('posts', function ($query) {
    $query->where('published', true);
})->get(); // Uses: (user)-[:AUTHORED]->(post) pattern

// HasManyThrough with direct graph traversal
$userComments = User::hasManyThrough(Comment::class, Post::class)
    ->useNativeEdges()
    ->get(); // Uses: (user)-[:HAS_POSTS]->(post)-[:HAS_COMMENTS]->(comment)
```

#### Migration Tools

```bash
# Check model compatibility for native edges
php artisan neo4j:check-compatibility

# Migrate existing foreign keys to native edges
php artisan neo4j:migrate-to-edges --model=User --dry-run
php artisan neo4j:migrate-to-edges --model=User --relation=posts
```

#### Schema Introspection Commands

```bash
php artisan neo4j:schema                    # Full schema overview
php artisan neo4j:schema:labels --count     # Node labels with counts
php artisan neo4j:schema:relationships      # Relationship types
php artisan neo4j:schema:constraints        # Constraints
php artisan neo4j:schema:indexes            # Indexes
php artisan neo4j:schema:export schema.json # Export to file
```

### Polymorphic Relationships

All Eloquent polymorphic relationship methods work fully:

```php
// morphOne, morphMany, morphTo, morphToMany all work
$post->comments()->create(['body' => 'Great post!']);
$post->tags()->attach([1, 2, 3]);
$comment->commentable; // Returns Post or Video
```

**Note:** Polymorphic relationships use foreign key storage (not native graph edges) for performance and full Eloquent compatibility. See [Relationships docs](docs/relationships.md) for details.

### Cypher DSL Query Builder

Build type-safe Cypher queries with IDE autocomplete:

```php
use WikibaseSolutions\CypherDSL\Query;

// Model-based queries with automatic hydration
$users = User::match()
    ->where(Query::variable('n')->property('age')->gt(Query::literal(25)))
    ->get(); // Collection<User>

// Graph traversals from specific nodes
$following = $user->matchFrom()->outgoing('FOLLOWS', 'users')->get();

// Path finding
$path = $user->matchFrom()->shortestPath($target, 'KNOWS')->get();
```

See [Cypher DSL Guide](docs/cypher-dsl.md) for traversals, macros, and advanced patterns.

### Neo4j-Specific Features

#### Schema Introspection (Programmatic)
```php
use Look\EloquentCypher\Facades\GraphSchema;

GraphSchema::getAllLabels();           // ['User', 'Post', 'Comment']
GraphSchema::getAllRelationshipTypes(); // ['WROTE', 'LIKES', 'FOLLOWS']
GraphSchema::getConstraints();          // Constraint details
GraphSchema::introspect();              // Complete schema in one call
```

#### Graph Patterns and Paths
```php
// Pattern matching
$results = User::joinPattern('(u:users), (p:posts)')
    ->where('p.user_id = u.id')
    ->get();

// Shortest path
$path = User::shortestPath()->from($userA->id)->to($userB->id, 'friends')->get();

// Raw Cypher
$results = DB::connection('graph')->cypher('MATCH (u:users) RETURN u LIMIT 10');
```

### Driver Abstraction & Custom Drivers

Create your own database drivers by implementing the `GraphDriverInterface`:

```php
use Look\EloquentCypher\Contracts\GraphDriverInterface;
use Look\EloquentCypher\Drivers\DriverManager;

// Register your custom driver
DriverManager::register('memgraph', MemgraphDriver::class);

// Configure in config/database.php
'connections' => [
    'graph' => [
        'driver' => 'graph',
        'database_type' => 'memgraph',  // Use your custom driver
        'host' => 'localhost',
        'port' => 7687,
        // ... other config ...
    ],
],
```

**Built-in Drivers:**
- **Neo4j** - Full support

**Custom Driver Requirements:**
- Implement `GraphDriverInterface`
- Provide `ResultSetInterface`, `TransactionInterface`, `CapabilitiesInterface`, `SchemaIntrospectorInterface`
- Register with `DriverManager::register()`

Support for other graph databases to follow eg. Memgraph, Kuzu, FalkorDB.

## Features

### Complete Eloquent Compatibility
- **All CRUD operations** - create, read, update, delete, upsert
- **All relationship types** - hasOne, hasMany, belongsTo, belongsToMany, hasManyThrough
- **Polymorphic relationships** - morphOne, morphMany, morphTo, morphToMany, morphedByMany - all fully working!
- **Query builder** - where, whereIn, whereBetween, orderBy, groupBy, having, limit, etc.
- **Relationship queries** - whereHas, doesntHave, whereDoesntHave, orWhereHas, has, withCount
- **Eager loading** - with(), load(), loadCount() with complex constraints
- **Batch operations** - chunk, chunkById, cursor, lazy, lazyById
- **Model operations** - increment, decrement, touch, push, replicate, fresh, refresh
- **Aggregations** - sum, avg, min, max, count + Neo4j-specific (percentileDisc, percentileCont, stdev, stdevp, collect)
- **First/Create methods** - firstOrNew, firstOrCreate, updateOrCreate
- **Debugging** - toCypher, dump, dd, explain
- **Transactions** - with rollback support
- **Soft deletes** - with trash management
- **Timestamps** - automatic created_at/updated_at
- **Attribute casting** - all cast types supported
- **Model events & observers** - all events work
- **Query scopes** - global and local
- **Array & JSON operations** - Hybrid native/JSON storage (APOC optional)
  - Flat arrays stored as native Neo4j LISTs for best performance
  - Nested structures as JSON strings (APOC enhances queries when available)
  - Automatic type detection and transparent querying
  - `whereJsonContains()`, `whereJsonLength()` work seamlessly

### Neo4j-Specific Features
- **Cypher DSL** - Type-safe query builder with graph traversals and path finding
- **Neo4j Aggregates** - percentileDisc, percentileCont, stdev, stdevp, collect
- **Native Graph Relationships** - Real Neo4j edges with edge properties and migration tools
- **Hybrid Array Storage** - Flat arrays as native LISTs, nested as JSON (APOC optional)
- **Schema Introspection** - GraphSchema facade + 7 artisan commands
- **Graph Patterns** - joinPattern(), shortestPath, variable-length relationships
- **Raw Cypher** - DB::connection('graph')->cypher() for complex queries

## Requirements

- PHP 8.0+
- Laravel 10.x, 11.x or 12.x
- Neo4j 4.x or 5.x

## Documentation

- **[User Documentation](docs/index.md)** - Complete guides and reference
- **[Getting Started](docs/getting-started.md)** - Installation and setup
- **[Cypher DSL Guide](docs/cypher-dsl.md)** - Type-safe query builder
- **[Configuration Guide](specs/CONFIGURATION.md)** - All options
- **[Compatibility Matrix](docs/COMPATIBILITY_MATRIX.md)** - Method-by-method status
- **[Contributing](specs/CONTRIBUTING.md)** - How to contribute

## Known Limitations

### Platform Differences
- **cursor()** - Use `lazy()` instead (Neo4j doesn't support PDO streaming)
- **APOC** - Optional enhancement for nested JSON queries; all tests pass without it
- **Nested JSON updates** - Update entire parent property, not nested paths
- **Schema DDL** - Constraints/indexes execute sequentially for reliability

### When to Use Graph Databases
Graph databases excel at:
- Deep relationship traversals (social networks, recommendations)
- Variable-length path queries (friend-of-friend, shortest path)
- Schema flexibility with evolving data models

Consider SQL for simple CRUD with minimal relationships.

See [Limitations](docs/LIMITATIONS.md) for workarounds and details.

## Contributing

See [Contributing Guide](specs/CONTRIBUTING.md) for development process and coding standards.

## Testing

```bash
./vendor/bin/pest              # Run all tests
./vendor/bin/pest --coverage   # With coverage report
```

Tests require Neo4j on port 7688. Use `docker-compose up -d` to start.

## License

This package is open-source software licensed under the [MIT license](LICENSE).

## Credits

Built by [Look Systems](https://look.systems)

Special thanks to the Laravel and Neo4j communities for their amazing tools and support.
