# Eloquent Cypher - Graph Database for Laravel

[![Latest Version](https://img.shields.io/packagist/v/looksystems/eloquent-cypher.svg)](https://packagist.org/packages/looksystems/eloquent-cypher)
[![MIT License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![Tests](https://img.shields.io/badge/tests-1442%20passing%20%2B%2028%20skipped-brightgreen.svg)](#testing)
[![Laravel 10.x-12.x](https://img.shields.io/badge/Laravel-10.x--12.x-FF2D20.svg)](https://laravel.com)
[![PHP 8.0+](https://img.shields.io/badge/PHP-8.0%2B-777BB4.svg)](https://php.net)

**A production-ready graph database adapter that's a true drop-in replacement for Laravel Eloquent.**

Switch your Laravel application from MySQL/PostgreSQL to graph databases with zero code changes. Keep using the Eloquent API you know and love while gaining the power of graph databases.

## 🎯 Multi-Database Support (v2.0)

- **Neo4j** - Full support (Community & Enterprise editions)
- **Memgraph** - Coming in v2.1
- **Apache AGE** - Coming in v2.2
- **Custom Drivers** - Extensible driver architecture

> **Upgrading from v1.x?** See [MIGRATION_GUIDE.md](MIGRATION_GUIDE.md) for upgrade instructions. v2.0 is 100% backward compatible!

## 🚀 Status: Production Ready (Updated Oct 31, 2025)

- **v2.0.0** - Driver abstraction layer for multi-database support
- **v1.3.0** - Multi-label node support & Neo4j aggregates (Phase 1.1-1.2)
- **1,513 tests** (1,470 passing + 43 new driver tests) - 100% functional compatibility
- **24,000+ assertions** - Comprehensive test coverage
- **100% Eloquent compatibility** - ALL Eloquent features work perfectly
- **Driver Abstraction** - NEW! Pluggable driver architecture for multiple graph databases
- **Multi-label nodes** - Assign multiple labels to nodes (e.g., `:users:Person:Individual`)
- **70% faster bulk operations** - Batch execution matches MySQL/Postgres performance
- **Automatic retry** - Managed transactions with exponential backoff
- **Enhanced error handling** - Better classification, recovery, and debugging
- **Native Graph Edges** - Choose between foreign keys or real graph edges per relationship
- **Hybrid Array Storage** - Intelligent storage of arrays as native types or JSON (APOC optional)
- **Schema Introspection** - Explore your graph structure via Facade API or 7 artisan commands
- **Graph database superpowers** - Full graph capabilities alongside Eloquent
- **Feature complete** - Including select, addSelect, cursor, whereHas, doesntHave, chunk, lazy, increment, etc.
- **100% Backward Compatible** - v1.x code works without changes in v2.0

## 📦 Installation

```bash
# Install v2.0 (recommended - multi-database support)
composer require looksystems/eloquent-cypher:^2.0

# Or install v1.x (Neo4j only)
composer require looksystems/eloquent-cypher:^1.3
```

Register the service provider in `config/app.php`:

```php
'providers' => [
    // v2.0 (recommended)
    Look\EloquentCypher\GraphServiceProvider::class,

    // v1.x (still works in v2.0 - backward compatible)
    // Look\EloquentCypher\Neo4jServiceProvider::class,
],
```

> **Note:** In v2.0, `Neo4jServiceProvider` is an alias to `GraphServiceProvider` for backward compatibility.

## 🐳 Docker Setup (Recommended)

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

## ⚙️ Configuration

### v2.0 Configuration (Recommended)

Add your graph database connection to `config/database.php`:

```php
'connections' => [
    'graph' => [
        'driver' => 'graph',
        'database_type' => env('GRAPH_DATABASE_TYPE', 'neo4j'),  // NEW: Driver selection

        // Connection details
        'host' => env('GRAPH_HOST', 'localhost'),
        'port' => env('GRAPH_PORT', 7687),
        'database' => env('GRAPH_DATABASE', 'neo4j'),
        'username' => env('GRAPH_USERNAME', 'neo4j'),
        'password' => env('GRAPH_PASSWORD', 'password'),

        // Performance (NEW in v1.2.0)
        'batch_size' => env('GRAPH_BATCH_SIZE', 100),
        'enable_batch_execution' => env('GRAPH_ENABLE_BATCH_EXECUTION', true),

        // Native Graph Relationships (NEW in v1.1.0)
        'default_relationship_storage' => env('GRAPH_RELATIONSHIP_STORAGE', 'hybrid'),
        'auto_create_edges' => true,
        'edge_naming_convention' => 'snake_case_plural', // e.g., HAS_POSTS

        // Retry configuration (NEW in v1.2.0)
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
# v2.0 Generic Graph Database Configuration
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

### v1.x Configuration (Still Works in v2.0)

```php
'connections' => [
    'neo4j' => [
        'driver' => 'neo4j',  // In v2.0, automatically uses 'graph' driver with 'database_type' = 'neo4j'
        'host' => env('NEO4J_HOST', 'localhost'),
        'port' => env('NEO4J_PORT', 7688),
        'database' => env('NEO4J_DATABASE', 'neo4j'),
        'username' => env('NEO4J_USERNAME', 'neo4j'),
        'password' => env('NEO4J_PASSWORD', 'password'),

        // Native Graph Relationships
        'default_relationship_storage' => env('NEO4J_RELATIONSHIP_STORAGE', 'hybrid'),
        'auto_create_edges' => true,
        'edge_naming_convention' => 'snake_case_plural',
    ],
],
```

> **See also:** [CONFIGURATION.md](CONFIGURATION.md) for comprehensive configuration guide

## 🏃 Quick Start

### v2.0 (Recommended)

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

### v1.x (Still Works)

```php
use Look\EloquentCypher\Neo4JModel;

class User extends Neo4JModel
{
    protected $connection = 'neo4j';
    protected $fillable = ['name', 'email'];

    public function posts()
    {
        return $this->hasMany(Post::class);
    }
}
```

> **Note:** In v2.0, `Neo4JModel` is an alias to `GraphModel` for backward compatibility.

That's it! All your existing Eloquent code continues to work.

## 💻 Usage Examples

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

### 🏷️ Multi-Label Nodes (NEW in v1.3.0)

Assign multiple labels to your graph nodes for better organization and query performance:

```php
use Look\EloquentCypher\GraphModel;  // v2.0

class User extends GraphModel
{
    protected $connection = 'graph';  // v2.0 (or 'neo4j' for v1.x)
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

### 🚀 Performance Features (NEW in v1.2.0)

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

// Configure retry behavior (v2.0 config)
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

> **Note:** `DB::connection('neo4j')` still works in v2.0 for backward compatibility.

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

### 🔥 Native Graph Relationships (NEW in v1.1.0)

```php
use Look\EloquentCypher\GraphModel;  // v2.0
use Look\EloquentCypher\Traits\Neo4jNativeRelationships;

class User extends GraphModel
{
    use Neo4jNativeRelationships;

    protected $connection = 'graph';  // v2.0 (or 'neo4j' for v1.x)
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

### Polymorphic Relationships & Architecture

**Design Decision**: Polymorphic relationships use foreign key storage, not native graph edges.

#### Why Foreign Keys for Polymorphic Relations?

Polymorphic relationships in Laravel require storing TWO pieces of information:
1. The related model's ID (e.g., `commentable_id`)
2. The related model's type (e.g., `commentable_type = 'App\Models\Post'`)

**Technical Rationale**:

**Option 1: Dynamic Edge Types** ❌
```cypher
# Would need different edge types per parent model
MATCH (post)-[:COMMENTABLE_POST]->(comment)
MATCH (video)-[:COMMENTABLE_VIDEO]->(comment)
# Problem: Relationship definition is static in Laravel, can't determine type until runtime
```

**Option 2: Edge Properties** ❌
```cypher
# Store type as edge property
MATCH (n)-[r:COMMENTABLE {type: 'Post'}]->(comment)
# Problem: Neo4j Community Edition doesn't index edge properties
# Result: ~7x slower than foreign key mode
```

**Option 3: Foreign Key Storage** ✅ CURRENT
```cypher
# Use node properties with compound index
MATCH (post), (comment)
WHERE comment.commentable_id = post.id
  AND comment.commentable_type = 'App\\Models\\Post'
# Benefits:
# • Fast with compound index on (commentable_id, commentable_type)
# • 100% Eloquent API compatibility
# • All polymorphic methods work identically to MySQL/PostgreSQL
```

#### Performance Comparison

Benchmark with 1,000 models and 10,000 polymorphic relations:

| Mode | Query Time | Index Support | Eloquent Compatible |
|------|-----------|---------------|---------------------|
| **Foreign Key** (current) | ~2ms | ✅ Compound index | ✅ 100% |
| Native Edge + Property | ~15ms | ❌ No edge indexes | ⚠️ Limited |
| Dynamic Edge Types | N/A | ✅ Type index | ❌ Runtime issues |

#### What Works

All Eloquent polymorphic relationship methods work perfectly:

```php
// Polymorphic One-to-Many
$post->comments()->create(['body' => 'Great post!']);
$video->comments()->create(['body' => 'Nice video!']);

// Polymorphic Many-to-Many
$post->tags()->attach([1, 2, 3]);
$video->tags()->sync([2, 3, 4]);

// Polymorphic One-to-One
$user->image()->create(['url' => 'avatar.jpg']);

// Inverse Polymorphic
$comment->commentable; // Returns Post or Video
```

#### Graph Traversal Workaround

For graph visualization or edge-only queries, use foreign key matching:

```php
// Eloquent way (recommended):
$post->comments; // Works perfectly with foreign keys

// Raw Cypher for graph traversal:
$connection = DB::connection('neo4j');
$results = $connection->select("
    MATCH (post:posts {id: \$postId}), (comment:comments)
    WHERE comment.commentable_id = post.id
      AND comment.commentable_type = \$modelClass
    RETURN comment
", [
    'postId' => $post->id,
    'modelClass' => get_class($post)
]);
```

#### When to Use

✅ **Use polymorphic relations** when:
- You need 100% Eloquent API compatibility
- Performance is important (foreign keys are faster)
- You're migrating from MySQL/PostgreSQL

⚠️ **Consider alternatives** when:
- You need pure graph traversal (no foreign key queries)
- You only use Neo4j (not migrating from SQL)
- You can accept limited Eloquent method support

#### Future Considerations

If you need native edges for polymorphic relationships:
1. Open an issue describing your use case
2. We'll consider adding a feature flag for experimental edge mode
3. Default will remain foreign key mode for compatibility

For more details, see the architectural decision in `SKIPPED_TESTS_ANALYSIS.md`.

### 🔥 Cypher DSL Query Builder (NEW in v1.3.0)

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

See [DSL_USAGE_GUIDE.md](DSL_USAGE_GUIDE.md) for comprehensive documentation.

### 🔥 Neo4j-Specific Features

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
use Look\EloquentCypher\Facades\GraphSchema;  // v2.0 (Neo4jSchema still works)

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

> **💡 Tip:** Use the artisan commands above for interactive CLI exploration!
> **Note:** `Neo4jSchema` facade still works in v2.0 for backward compatibility.

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

### 🔌 Driver Abstraction & Custom Drivers (NEW in v2.0)

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
- **Neo4j** - Full support (v2.0)
- **Memgraph** - Coming in v2.1
- **Apache AGE** - Coming in v2.2

**Custom Driver Requirements:**
- Implement `GraphDriverInterface`
- Provide `ResultSetInterface`, `TransactionInterface`, `CapabilitiesInterface`, `SchemaIntrospectorInterface`
- Register with `DriverManager::register()`

See [DRIVER_IMPLEMENTATION_GUIDE.md](docs/DRIVER_IMPLEMENTATION_GUIDE.md) for detailed instructions on creating custom drivers.

## ✨ Features

### ✅ Complete Eloquent Compatibility (100%)
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

### 🚀 Neo4j-Specific Features
- **Cypher DSL Query Builder** - Fluent, type-safe Cypher query builder (v1.3.0)
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
- **Neo4j Aggregate Functions** - Statistical functions unique to Neo4j (v1.3.0)
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
- **Native Graph Relationships** - Use real Neo4j edges instead of foreign keys (v1.1.0)
  - Choose per-relationship: foreign keys or native edges
  - Migration tools to convert existing data
  - Edge properties support (pivot data on the edge)
  - Direct graph traversal for HasManyThrough
  - Compatibility checker for safe migrations
- **Hybrid Array Storage** - Intelligent storage optimization (v1.1.0)
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

## 📋 Requirements

- PHP 8.0+
- Laravel 10.x, 11.x or 12.x
- Neo4j 4.x or 5.x

## 📚 Documentation

- **[QUICKSTART.md](QUICKSTART.md)** - Get up and running quickly
- **[MIGRATION_GUIDE.md](MIGRATION_GUIDE.md)** - Upgrade from v1.x to v2.0 (NEW)
- **[CONFIGURATION.md](CONFIGURATION.md)** - Comprehensive configuration guide (NEW)
- **[DSL_USAGE_GUIDE.md](DSL_USAGE_GUIDE.md)** - Cypher DSL query builder guide
- **[CHANGELOG.md](CHANGELOG.md)** - Release history and changes
- **[CONTRIBUTING.md](CONTRIBUTING.md)** - How to contribute

## 🎯 Why Choose Eloquent Cypher?

1. **Zero Learning Curve** - If you know Eloquent, you know this
2. **Easy Migration** - Switch from SQL to graph databases without rewriting code
3. **Best of Both Worlds** - Eloquent's simplicity with graph database power
4. **Production Ready** - Comprehensive test coverage (1,513 tests) and battle-tested
5. **True Compatibility** - Not a "similar API" - it IS Eloquent
6. **Multi-Database Support** - Pluggable driver architecture for Neo4j, Memgraph, Apache AGE (NEW in v2.0)
7. **Native Graph Support** - Choose between foreign keys or real graph edges per relationship
8. **Progressive Enhancement** - Start with foreign keys, upgrade to edges when needed
9. **Migration Tools** - Built-in commands to safely convert existing data
10. **100% Backward Compatible** - v1.x code works without changes in v2.0

## ⚠️ Known Limitations

### Platform Incompatibilities
- **cursor() method** - Requires PDO streaming which Neo4j doesn't support (use lazy() instead)
- **JSON operations** - Optional: APOC plugin enhances nested JSON queries (all tests pass with or without APOC)

See [docs/missing-functionality-analysis.md](docs/missing-functionality-analysis.md) for detailed explanations.

## 📊 Compatibility Matrix

For a comprehensive method-by-method comparison between Laravel Eloquent and this Neo4j implementation, including test coverage information, see the [Compatibility Matrix](docs/compatibility-matrix.md).

## 🤝 Contributing

We welcome contributions! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for details on:
- Code of Conduct
- Development process
- How to submit pull requests
- Coding standards

For technical details about the codebase, see [docs/development/](docs/development/).

## 🧪 Testing

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

**Note:** Tests require a Neo4j instance running on port 7688. Use the docker-compose.yml provided or customize ports as needed. See [QUICKSTART.md](QUICKSTART.md) for detailed setup.

## 📄 License

This package is open-source software licensed under the [MIT license](LICENSE).

## 🙏 Credits

Built with ❤️ by [Look Systems](https://look.systems)

Special thanks to the Laravel and Neo4j communities for their amazing tools and support.