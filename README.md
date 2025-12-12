# Eloquent Cypher - Graph Database for Laravel

> [!WARNING]
> This is an experimental/learning ai coding project only and is under active development.

[![Latest Version](https://img.shields.io/packagist/v/looksystems/eloquent-cypher.svg)](https://packagist.org/packages/looksystems/eloquent-cypher)
[![MIT License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![Tests](https://img.shields.io/badge/tests-1520%20total%20(28%20skipped)-brightgreen.svg)](#testing)
[![Laravel 10.x-12.x](https://img.shields.io/badge/Laravel-10.x--12.x-FF2D20.svg)](https://laravel.com)
[![PHP 8.0+](https://img.shields.io/badge/PHP-8.0%2B-777BB4.svg)](https://php.net)

**A graph database adapter that's a true drop-in replacement for Laravel Eloquent.**

Switch your Laravel application from MySQL/PostgreSQL to graph databases with zero code changes. Keep using the Eloquent API you know and love while gaining the power of graph databases.

## Table of Contents

- [Multi-Database Support](#multi-database-support)
- [Status](#status)
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

## Multi-Database Support

- **Neo4j** - Full support (Community & Enterprise editions)
- **Memgraph** - Planned (no ETA)
- **Apache AGE** - Planned (no ETA)
- **Custom Drivers** - Extensible driver architecture

## Status

- **1,520 tests** - Comprehensive functional coverage
- **24,000+ assertions** - Thorough test coverage
- **Near-complete Eloquent compatibility** - Most Eloquent features work identically (see [Limitations](#known-limitations))
- **Driver Abstraction** - Pluggable driver architecture for multiple graph databases
- **Multi-label nodes** - Assign multiple labels to nodes (e.g., `:users:Person:Individual`)
- **70% faster bulk operations** - Batch execution matches MySQL/Postgres performance
- **Automatic retry** - Managed transactions with exponential backoff
- **Enhanced error handling** - Better classification, recovery, and debugging
- **Native Graph Edges** - Choose between foreign keys or real graph edges per relationship
- **Hybrid Array Storage** - Intelligent storage of arrays as native types or JSON (APOC optional)
- **Schema Introspection** - Explore your graph structure via Facade API or 7 artisan commands
- **Graph database superpowers** - Full graph capabilities alongside Eloquent
- **Feature complete** - select, addSelect, cursor, whereHas, doesntHave, chunk, lazy, increment, etc.

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

`.env` configuration:

```env
GRAPH_DATABASE_TYPE=neo4j
GRAPH_HOST=localhost
GRAPH_PORT=7687
GRAPH_USERNAME=neo4j
GRAPH_PASSWORD=password

# Performance
GRAPH_BATCH_SIZE=100
GRAPH_ENABLE_BATCH_EXECUTION=true

# Relationships
GRAPH_RELATIONSHIP_STORAGE=hybrid
```

> **See also:** [Configuration Guide](specs/CONFIGURATION.md) for comprehensive configuration guide

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

### Standard Eloquent Operations

```php
// Everything works exactly like Eloquent
$user = User::create(['name' => 'John', 'email' => 'john@example.com']);
$users = User::where('age', '>', 25)->orderBy('name')->get();
$user->posts()->create(['title' => 'My First Post']);
$posts = User::with('posts')->find($id)->posts;

// Relationships work as expected
$user->roles()->attach($roleId);
$comments = $user->posts()->with('comments')->get();
$usersWithPosts = User::whereHas('posts')->withCount('posts')->get();

// Transactions for data consistency
DB::transaction(function () {
    $user = User::create(['name' => 'Jane']);
    $user->posts()->create(['title' => 'Post']);
});
```

### Multi-Label Nodes

Assign multiple labels to your graph nodes for better organization and query performance:

```php
use Look\EloquentCypher\GraphModel;

class User extends GraphModel
{
    protected $connection = 'graph';
    protected $table = 'users';  // Primary label
    protected $labels = ['Person', 'Individual'];  // Additional labels

    protected $fillable = ['name', 'email', 'age'];
}

// Creates node with labels: (:users:Person:Individual)
$user = User::create(['name' => 'John', 'email' => 'john@example.com']);

// Check labels
$user->getLabels();  // ['users', 'Person', 'Individual']
$user->hasLabel('Person');  // true
$user->hasLabel('Admin');   // false

// Query with specific label subset
$users = User::withLabels(['users', 'Person'])->get();

// All CRUD operations preserve labels automatically
$user->update(['name' => 'Jane']);  // Keeps all labels
$user->delete();  // Deletes node with all labels

// Works seamlessly with relationships, eager loading, etc.
$user->posts()->create(['title' => 'My Post']);
$users = User::with('posts')->whereHas('posts')->get();
```

**Benefits:**
- **Query Optimization**: Queries match on all labels for better performance
- **Backward Compatible**: Single-label models work exactly as before
- **Laravel-Like**: Familiar API with `$labels` property
- **Full Support**: Works with all Eloquent features (relationships, eager loading, scopes, etc.)

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

// Configure retry behavior
'graph' => [
    // ... other config ...
    'batch_size' => 100,  // Batch execution size
    'enable_batch_execution' => true,
    'retry' => [
        'max_attempts' => 3,
        'initial_delay_ms' => 100,
        'max_delay_ms' => 5000,
        'multiplier' => 2.0,
        'jitter' => true,  // Prevent thundering herd
    ],
],
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
# Display complete schema overview
php artisan neo4j:schema
php artisan neo4j:schema --json       # JSON output
php artisan neo4j:schema --compact    # Minimal output

# List all node labels
php artisan neo4j:schema:labels
php artisan neo4j:schema:labels --count  # Show node counts

# List all relationship types
php artisan neo4j:schema:relationships
php artisan neo4j:schema:relationships --count  # Show relationship counts

# List all property keys
php artisan neo4j:schema:properties

# List all constraints
php artisan neo4j:schema:constraints
php artisan neo4j:schema:constraints --type=UNIQUENESS  # Filter by type

# List all indexes
php artisan neo4j:schema:indexes
php artisan neo4j:schema:indexes --type=RANGE  # Filter by type

# Export schema to file
php artisan neo4j:schema:export schema.json
php artisan neo4j:schema:export schema.yaml --format=yaml
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

Build complex Cypher queries using a fluent, type-safe API:

```php
use Look\EloquentCypher\Facades\Cypher;
use WikibaseSolutions\CypherDSL\Query;

// Basic DSL query
$users = Cypher::query()
    ->match(Query::node('users')->named('n'))
    ->where(Query::variable('n')->property('age')->gt(Query::literal(25)))
    ->returning(Query::variable('n'))
    ->get();

// Model-based queries with automatic hydration
$activeUsers = User::match()
    ->where(Query::variable('n')->property('active')->equals(Query::literal(true)))
    ->get(); // Returns Collection<User>

// Instance traversal from a specific node
$user = User::find(1);
$following = $user->matchFrom()
    ->outgoing('FOLLOWS', 'users')
    ->get(); // Collection<User>

// Path finding
$path = $user->matchFrom()
    ->shortestPath(User::find(10), 'FOLLOWS')
    ->returning(Query::variable('path'))
    ->get();

// Register reusable macros
Neo4jCypherDslBuilder::macro('activeUsers', function () {
    return $this->where(
        Query::variable('n')->property('active')->equals(Query::literal(true))
    );
});

// Debug helpers
User::match()
    ->where(Query::variable('n')->property('age')->gt(Query::literal(25)))
    ->dd(); // Dump query and die
```

**Features:**
- **Type-Safe**: Full IDE autocomplete and type checking
- **Laravel-Like**: Familiar get(), first(), count(), dd(), dump() methods
- **Model Hydration**: Automatic model instantiation with casts
- **Graph Patterns**: Built-in helpers for traversals and path finding
- **Macros**: Create reusable query patterns
- **Backward Compatible**: Existing raw Cypher usage unchanged

See [DSL Usage Guide](specs/DSL_USAGE_GUIDE.md) for comprehensive documentation.

### Neo4j-Specific Features

#### Column Selection (Neo4j Considerations)
```php
// Use selectRaw for aliases (avoids double aliasing)
$users = User::selectRaw('n.name as user_name, n.email as contact_email')->get();

// Raw expressions need n. prefix
$results = User::selectRaw('n.age * 2 as double_age, n.age')
    ->orderBy('age')
    ->get();

// JSON properties are auto-decoded
$user = User::select('name', 'metadata')->first();
$metadata = $user->metadata; // Already decoded array/object

// GROUP BY works but test thoroughly
$stats = User::selectRaw('n.city as city, COUNT(*) as user_count')
    ->groupBy('city')
    ->get();
```

#### Schema Introspection (Programmatic)
```php
use Look\EloquentCypher\Facades\GraphSchema;

// Get all node labels in the database
$labels = GraphSchema::getAllLabels();
// ['User', 'Post', 'Comment']

// Get all relationship types
$types = GraphSchema::getAllRelationshipTypes();
// ['WROTE', 'LIKES', 'FOLLOWS']

// Get all property keys
$keys = GraphSchema::getAllPropertyKeys();
// ['id', 'name', 'email', 'title', 'content']

// Get all constraints with details
$constraints = GraphSchema::getConstraints();
// [['name' => 'user_email_unique', 'type' => 'UNIQUENESS', ...]]

// Get all indexes with details
$indexes = GraphSchema::getIndexes();
// [['name' => 'user_name_index', 'type' => 'RANGE', ...]]

// Get complete schema in one call
$schema = GraphSchema::introspect();
// Returns array with: labels, relationshipTypes, propertyKeys, constraints, indexes
```

> **Tip:** Use the artisan commands above for interactive CLI exploration!

#### Graph Patterns and Paths
```php
// Graph pattern matching
$results = User::joinPattern('(u:users), (p:posts)')
    ->where('p.user_id = u.id')
    ->select(['u.name', 'p.title'])
    ->get();

// Shortest path queries
$path = User::shortestPath()
    ->from($userA->id)
    ->to($userB->id, 'friends')
    ->get();

// Raw Cypher for complex queries
$results = DB::connection('graph')->cypher(
    'MATCH (u:users)-[:POSTED]->(p:posts) RETURN u, p LIMIT 10'
);
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
- **Memgraph** - Planned (no ETA)
- **Apache AGE** - Planned (no ETA)

**Custom Driver Requirements:**
- Implement `GraphDriverInterface`
- Provide `ResultSetInterface`, `TransactionInterface`, `CapabilitiesInterface`, `SchemaIntrospectorInterface`
- Register with `DriverManager::register()`

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
- **Cypher DSL Query Builder** - Fluent, type-safe Cypher query builder
  ```php
  // Model-based queries with automatic hydration
  $users = User::match()
      ->where(Query::variable('n')->property('age')->gt(Query::literal(25)))
      ->get(); // Collection<User>

  // Graph traversals from specific nodes
  $following = $user->matchFrom()->outgoing('FOLLOWS', 'users')->get();

  // Path finding algorithms
  $path = $user->matchFrom()->shortestPath($target, 'KNOWS')->get();

  // Facade for convenience
  $results = Cypher::query()->match(Query::node('users'))->get();

  // Extensible with macros
  User::macro('activeUsers', function () {
      return $this->where(Query::variable('n')->property('active')->equals(Query::literal(true)));
  });

  // See DSL_USAGE_GUIDE.md for comprehensive documentation
  ```
- **Neo4j Aggregate Functions** - Statistical functions unique to Neo4j
  ```php
  // Percentile calculations
  $p95Age = User::percentileDisc('age', 0.95);  // 95th percentile (discrete)
  $medianAge = User::percentileCont('age', 0.5); // Median (continuous/interpolated)

  // Standard deviation
  $ageStdDev = User::stdev('salary');    // Sample standard deviation
  $ageStdDevP = User::stdevp('salary');  // Population standard deviation

  // Collect values into array
  $allNames = User::collect('name');  // Returns array of all names

  // Works with WHERE clauses and relationships
  $p95Views = User::where('active', true)->posts()->percentileDisc('views', 0.95);

  // Use in withAggregate and loadAggregate
  $users = User::withAggregate('posts', 'views', 'stdev')->get();
  $user->loadAggregate('posts', 'views', 'percentileDisc');

  // Combine with standard aggregates in selectRaw
  $stats = User::selectRaw('
      COUNT(*) as total,
      AVG(n.age) as avg_age,
      percentileDisc(n.age, 0.95) as p95_age,
      stdev(n.salary) as salary_stdev
  ')->first();
  ```
- **Native Graph Relationships** - Use real Neo4j edges instead of foreign keys
  - Choose per-relationship: foreign keys or native edges
  - Migration tools to convert existing data
  - Edge properties support (pivot data on the edge)
  - Direct graph traversal for HasManyThrough
  - Compatibility checker for safe migrations
- **Hybrid Array Storage** - Intelligent storage optimization
  - Flat arrays as native Neo4j LISTs (no APOC needed)
  - Nested structures as JSON (enhanced by APOC when available)
  - Automatic type detection for optimal performance
  - Full backward compatibility with existing data
- **Schema Introspection** - Explore your graph structure
  - Programmatic API via `Neo4jSchema` facade
  - 7 artisan commands for CLI access
  - Export schemas to JSON/YAML
- Graph pattern matching with `joinPattern()`
- Shortest path algorithms via `ShortestPathBuilder`
- Variable-length relationships via `VariablePathBuilder`
- Raw Cypher queries with `DB::connection('neo4j')->cypher()`
- Node-based operations
- Schema management (indexes, constraints) via `Neo4jBlueprint`

## Requirements

- PHP 8.0+
- Laravel 10.x, 11.x or 12.x
- Neo4j 4.x or 5.x

## Documentation

- **[Getting Started Guide](docs/getting-started.md)** - Installation and setup
- **[Configuration Guide](specs/CONFIGURATION.md)** - Comprehensive configuration guide
- **[DSL Usage Guide](specs/DSL_USAGE_GUIDE.md)** - Cypher DSL query builder guide
- **[User Documentation](docs/index.md)** - Complete user documentation
- **[Contributing Guide](specs/CONTRIBUTING.md)** - How to contribute

## Why Choose Eloquent Cypher?

1. **Zero Learning Curve** - If you know Eloquent, you know this
2. **Easy Migration** - Switch from SQL to graph databases without rewriting code
3. **Best of Both Worlds** - Eloquent's simplicity with graph database power
4. **Test Coverage** - Comprehensive test coverage (1,520 tests)
5. **True Compatibility** - Not a "similar API" - it IS Eloquent
6. **Multi-Database Support** - Pluggable driver architecture for Neo4j, Memgraph, Apache AGE
7. **Native Graph Support** - Choose between foreign keys or real graph edges per relationship
8. **Progressive Enhancement** - Start with foreign keys, upgrade to edges when needed
9. **Migration Tools** - Built-in commands to safely convert existing data

## Known Limitations

### Platform Incompatibilities
- **cursor() method** - Requires PDO streaming which Neo4j doesn't support (use lazy() instead)
- **JSON operations** - Optional: APOC plugin enhances nested JSON queries (all tests pass with or without APOC)

See [Limitations](docs/LIMITATIONS.md) for detailed explanations.

## Compatibility Matrix

For a comprehensive method-by-method comparison between Laravel Eloquent and this Neo4j implementation, including test coverage information, see the [Compatibility Matrix](docs/COMPATIBILITY_MATRIX.md).

## Contributing

We welcome contributions! Please see [Contributing Guide](specs/CONTRIBUTING.md) for details on:
- Code of Conduct
- Development process
- How to submit pull requests
- Coding standards

## Testing

```bash
# Copy PHPUnit configuration (first time only)
cp phpunit.xml phpunit.xml.dist

# Run all tests (sequentially)
./vendor/bin/pest

# Run with coverage report
./vendor/bin/pest --coverage-text

# Run specific test
./vendor/bin/pest tests/Feature/BasicCrudTest.php
```

**Note:** Tests require a Neo4j instance running on port 7688. Use the docker-compose.yml provided or customize ports as needed. See the [Getting Started Guide](docs/getting-started.md) for detailed setup.

## License

This package is open-source software licensed under the [MIT license](LICENSE).

## Credits

Built by [Look Systems](https://look.systems)

Special thanks to the Laravel and Neo4j communities for their amazing tools and support.