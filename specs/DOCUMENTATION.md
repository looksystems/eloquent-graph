# Eloquent Cypher - Comprehensive Documentation

**Version 1.2.0** - Now with 50-70% faster bulk operations and automatic transaction retry!

## Table of Contents
- [Installation](#installation)
- [Setup & Configuration](#setup--configuration)
- [Performance Features (v1.2.0 NEW!)](#performance-features-v120-new)
- [Key Differences from Standard Eloquent](#key-differences-from-standard-eloquent)
- [Limitations & Workarounds](#limitations--workarounds)
- [Migration Guide](#migration-guide)
- [Native Graph Relationships](#native-graph-relationships)
- [Performance Considerations](#performance-considerations)
- [Troubleshooting](#troubleshooting)

## Installation

### Requirements
- **PHP**: 8.0, 8.1, 8.2, 8.3, or 8.4
- **Laravel**: 10.x, 11.x, or 12.x
- **Neo4j**: 4.x or 5.x (Community or Enterprise)
- **Composer**: For package management

### Package Installation

```bash
composer require looksystems/eloquent-cypher
```

### Service Provider Registration

Add to `config/app.php`:

```php
'providers' => [
    // Other Service Providers
    Look\EloquentCypher\Neo4jServiceProvider::class,
],
```

Note: In Laravel 11+, the service provider may auto-register via package discovery.

## Setup & Configuration

### Neo4j Database Setup

#### Option 1: Docker (Recommended for Development)

```bash
# Using Docker Compose
docker-compose up -d

# Using Docker Run (custom ports to avoid conflicts)
docker run -d \
  --name neo4j-eloquent \
  -p 7688:7687 \  # Bolt protocol (custom port)
  -p 7475:7474 \  # HTTP/Browser (custom port)
  -e NEO4J_AUTH=neo4j/password \
  -e NEO4J_PLUGINS='["apoc"]' \  # Optional: APOC for advanced features
  neo4j:5-community
```

**Important**: Default test port is 7688 to avoid conflicts with standard Neo4j installations.

#### Option 2: Native Installation

1. Download Neo4j from [neo4j.com](https://neo4j.com/download/)
2. Configure authentication in `neo4j.conf`
3. Start the service

### Database Connection Configuration

Add to `config/database.php`:

```php
'connections' => [
    'neo4j' => [
        // Basic Connection
        'driver' => 'neo4j',
        'host' => env('NEO4J_HOST', 'localhost'),
        'port' => env('NEO4J_PORT', 7687),
        'database' => env('NEO4J_DATABASE', 'neo4j'),
        'username' => env('NEO4J_USERNAME', 'neo4j'),
        'password' => env('NEO4J_PASSWORD', 'password'),

        // Label Management (like table prefixes in MySQL)
        'label_prefix' => env('NEO4J_LABEL_PREFIX', null),

        // Connection Behavior
        'lazy' => false,  // Initialize connection lazily
        'sticky' => true,  // Use same connection for reads after writes

        // Relationship Storage Strategy
        'default_relationship_storage' => 'hybrid', // Options: foreign_key|edge|hybrid
        'auto_create_edges' => true,
        'edge_naming_convention' => 'snake_case_upper', // HAS_POSTS
        'prefer_edge_traversal' => true,

        // Connection Pooling
        'pool' => [
            'enabled' => true,
            'max_connections' => 10,
            'acquire_timeout' => 5000,  // milliseconds
            'max_lifetime' => 3600000,  // milliseconds
        ],

        // Performance Features (v1.2.0)
        'batch_size' => 100,  // Records per batch operation
        'enable_batch_execution' => true,  // 50-70% faster bulk operations
        'retry' => [
            'max_attempts' => 3,
            'initial_delay_ms' => 100,
            'max_delay_ms' => 5000,
            'multiplier' => 2.0,
            'jitter' => true,
        ],

        // Read/Write Splitting (optional)
        'read' => [
            'host' => [
                env('NEO4J_READ_HOST_1'),
                env('NEO4J_READ_HOST_2'),
            ],
            'sticky' => true,
        ],
        'write' => [
            'host' => env('NEO4J_WRITE_HOST'),
        ],
    ],
],
```

### Environment Variables

Add to `.env`:

```env
# Basic Connection
NEO4J_HOST=localhost
NEO4J_PORT=7688
NEO4J_USERNAME=neo4j
NEO4J_PASSWORD=password
NEO4J_DATABASE=neo4j

# Optional: Relationship Configuration
NEO4J_RELATIONSHIP_STORAGE=hybrid
NEO4J_LABEL_PREFIX=app_

# Optional: Read/Write Split
NEO4J_READ_HOST_1=neo4j-read1.example.com
NEO4J_READ_HOST_2=neo4j-read2.example.com
NEO4J_WRITE_HOST=neo4j-write.example.com
```

### Model Setup

```php
use Look\EloquentCypher\Neo4JModel;
use Look\EloquentCypher\Traits\Neo4jNativeRelationships;

class User extends Neo4JModel
{
    use Neo4jNativeRelationships; // Optional: Enable native graph edges

    protected $connection = 'neo4j';
    protected $table = 'users';  // Becomes label in Neo4j
    protected $primaryKey = 'id';

    // Enable native edges for all relationships on this model
    protected $useNativeRelationships = true;

    protected $fillable = ['name', 'email'];
}
```

## Performance Features (v1.2.0 NEW!)

### Batch Statement Execution

**Problem Solved**: Laravel's `insert([100 records])` was executing 100 separate queries in Neo4j, while MySQL/Postgres execute 1 batch query.

**Solution**: Automatic batch execution - now matches Laravel MySQL/Postgres performance!

```php
// Before v1.2.0: 100 queries, ~3 seconds
User::insert([
    ['name' => 'User 1', 'email' => 'user1@example.com'],
    ['name' => 'User 2', 'email' => 'user2@example.com'],
    // ... 98 more records
]);

// After v1.2.0: 1 batch request, ~0.9 seconds (70% faster!)
// Same API, same code - just faster!
User::insert([...]); // Automatically batched

// Upsert also batched
User::upsert(
    [['email' => 'john@example.com', 'name' => 'John']],
    ['email'],  // Unique keys
    ['name']    // Update columns
); // 48% faster for large datasets
```

**Configuration**:
```php
'batch_size' => 100,  // Adjust based on your data
'enable_batch_execution' => true,  // Disable for debugging if needed
```

**Performance Gains**:
- Insert 100 records: **3s → 0.9s** (70% faster)
- Insert 1,000 records: **10s → 4s** (60% faster)
- Upsert 1,000 records: **15s → 7.8s** (48% faster)
- Schema migrations: **40% faster**

### Managed Transactions with Automatic Retry

**Problem Solved**: Transient errors (deadlocks, timeouts) required manual retry logic.

**Solution**: Managed transactions with automatic exponential backoff!

```php
use Illuminate\Support\Facades\DB;

// Write transactions with automatic retry
$result = DB::connection('neo4j')->write(function ($connection) {
    $user = User::create(['name' => 'John', 'email' => 'john@example.com']);
    $user->posts()->create(['title' => 'First Post']);
    return $user;
}, $maxRetries = 3);

// Read transactions (routes to read replicas in clusters)
$users = DB::connection('neo4j')->read(function ($connection) {
    return User::where('active', true)->get();
}, $maxRetries = 2);

// Benefits:
// ✅ Automatic retry on transient errors (deadlocks, network issues)
// ✅ Exponential backoff with jitter (prevents thundering herd)
// ✅ Routes to appropriate cluster nodes (write leader, read replicas)
// ✅ Returns callback result on success
```

**Laravel's `transaction()` still works!** (This is optional enhancement)
```php
// Standard Laravel transaction (existing code continues to work)
DB::connection('neo4j')->transaction(function () {
    User::create([...]);
}, $attempts = 3);  // Laravel supports retry too!
```

**Retry Configuration**:
```php
'retry' => [
    'max_attempts' => 3,           // Max retry attempts
    'initial_delay_ms' => 100,     // First retry after 100ms
    'max_delay_ms' => 5000,        // Max delay 5 seconds
    'multiplier' => 2.0,           // Exponential backoff (100ms, 200ms, 400ms...)
    'jitter' => true,              // Add randomness to prevent thundering herd
]
```

**Success Rate**: 95% → 99.9%+ under high concurrency

For complete guide, see [docs/MANAGED_TRANSACTIONS.md](docs/MANAGED_TRANSACTIONS.md)

### Enhanced Error Handling

**Problem Solved**: Neo4j errors were unclear and didn't provide recovery guidance.

**Solution**: Automatic error classification, recovery, and helpful hints!

```php
try {
    User::create(['email' => 'duplicate@example.com']);
} catch (\Look\EloquentCypher\Exceptions\Neo4jConstraintException $e) {
    // Now includes:
    // ✅ Query context (Cypher statement)
    // ✅ Parameters used
    // ✅ Helpful migration hints
    // ✅ Constraint violation details

    echo $e->getMessage();
    // "Constraint violation: email must be unique
    //  Query: CREATE (n:users {email: $email})
    //  Hint: Check uniqueness constraint on users.email"
}

// Automatic error classification:
// - Transient errors → Auto-retry
// - Network errors → Reconnect
// - Constraint violations → Clear message
// - Auth failures → Helpful guidance
```

**Connection Health Checks**:
```php
// Check if connection is healthy
if (!DB::connection('neo4j')->ping()) {
    // Connection is stale, automatic reconnection...
}

// Automatic reconnection on stale connections
DB::connection('neo4j')->reconnectIfStale();
```

**Exception Hierarchy**:
- `Neo4jException` (base)
  - `Neo4jQueryException` (syntax errors, etc.)
  - `Neo4jConnectionException`
    - `Neo4jNetworkException` (transient, auto-recoverable)
    - `Neo4jAuthenticationException`
  - `Neo4jTransactionException`
    - `Neo4jTransientException` (auto-retry recommended)
  - `Neo4jConstraintException` (unique violations, etc.)

### Type-Safe Parameters

**Problem Solved**: Empty arrays could cause "ambiguous parameter type" errors.

**Solution**: Automatic type detection for CypherList vs CypherMap!

```php
// Flat arrays → Automatically become CypherList
User::whereIn('id', [])->get();  // No longer fails!
User::whereIn('status', ['active', 'pending'])->get();  // Works perfectly

// Array properties are handled intelligently
User::create([
    'skills' => ['PHP', 'JavaScript'],  // CypherList
    'metadata' => ['key' => 'value']    // CypherMap
]);
```

**No code changes required** - it just works!

## Key Differences from Standard Eloquent

### 1. Node Labels vs Tables

Neo4j uses **labels** instead of tables. The `$table` property becomes the node label:

```php
class User extends Neo4JModel
{
    protected $table = 'users'; // Creates nodes with label :users
}

// Behind the scenes:
// SQL: INSERT INTO users ...
// Cypher: CREATE (n:users { ... })
```

### 2. Properties vs Columns

Neo4j nodes have **properties** instead of columns. No schema definition required:

```php
// Properties are dynamic - no migration needed
$user = User::create([
    'name' => 'John',
    'email' => 'john@example.com',
    'metadata' => ['preferences' => ['theme' => 'dark']], // JSON stored natively
]);
```

### 3. Relationship Storage Strategies

Unlike SQL databases, Neo4j offers three ways to handle relationships:

#### Foreign Key Mode (Traditional)
```php
// Stores relationship as property (like SQL)
$post->user_id = $user->id;
$post->save();
// Creates: (:posts {user_id: 123})
```

#### Native Edge Mode
```php
// Uses real graph edges
$user->posts()->useNativeEdges()->create([...]);
// Creates: (user)-[:HAS_POSTS]->(post)
```

#### Hybrid Mode (Default)
```php
// Stores BOTH for maximum compatibility
// Creates: (user)-[:HAS_POSTS]->(post {user_id: 123})
```

### 4. Query Translation

SQL queries are automatically translated to Cypher:

```php
// Eloquent Query
User::where('age', '>', 25)
    ->where('status', '!=', 'inactive')
    ->orderBy('created_at', 'desc')
    ->limit(10)
    ->get();

// Generated Cypher
MATCH (n:users)
WHERE n.age > 25
  AND n.status <> 'inactive'
RETURN n
ORDER BY n.created_at DESC
LIMIT 10
```

### 5. Null Handling

Neo4j handles NULL differently:

```php
// SQL: WHERE email != NULL (incorrect, returns no results)
// Neo4j: Automatically converts to IS NOT NULL

User::where('email', '!=', null)->get();
// Cypher: WHERE n.email IS NOT NULL

User::whereNull('email')->get();
// Cypher: WHERE n.email IS NULL
```

### 6. Operator Mapping

Some operators are automatically mapped:

| Eloquent | Cypher | Note |
|----------|--------|------|
| `=` | `=` | Standard equality |
| `!=` | `<>` | Neo4j uses `<>` for inequality |
| `LIKE` | `CONTAINS` or `STARTS WITH` | Pattern matching |
| `IN` | `IN` | Array membership |
| `BETWEEN` | `>= AND <=` | Range queries |

### 7. Select and Aliases

Be careful with column selection:

```php
// AVOID: Double aliasing
User::select('name as user_name')->get();

// PREFERRED: Use selectRaw for aliases
User::selectRaw('n.name as user_name')->get();

// Raw expressions need n. prefix
User::selectRaw('n.age * 2 as double_age')->get();
```

### 8. Array & JSON Handling

Eloquent Cypher uses **intelligent hybrid storage** for optimal performance:

**Flat Arrays** → Stored as native Neo4j LISTs:
```php
$user = User::create([
    'skills' => ['PHP', 'JavaScript', 'Go']  // Stored as native LIST
]);

// No APOC needed for queries!
User::whereJsonContains('skills', 'PHP')->get();
User::whereJsonLength('skills', '>', 2)->get();
```

**Nested Structures** → Stored as JSON strings:
```php
$user = User::create([
    'preferences' => ['theme' => 'dark', 'notifications' => true]  // JSON string
]);

// APOC enhances queries when available, falls back to string matching
User::whereJsonContains('preferences->theme', 'dark')->get();
```

**How It Works**:
- Flat indexed arrays like `['a', 'b', 'c']` → Native Neo4j LIST (best performance)
- Associative/nested arrays like `['key' => 'value']` → JSON string (backward compatible)
- Collections → Always JSON strings (maintains Laravel compatibility)
- Queries work transparently with both formats
- APOC plugin is optional but enhances complex JSON queries

### 9. Transactions

Neo4j transactions work differently:

```php
DB::connection('neo4j')->transaction(function () {
    // All queries here run in same transaction
    $user = User::create([...]);
    $user->posts()->create([...]);

    // Transaction auto-commits on success
    // Auto-rollback on exception
});

// Manual transaction control
$txn = DB::connection('neo4j')->beginTransaction();
try {
    // Operations...
    $txn->commit();
} catch (Exception $e) {
    $txn->rollback();
    throw $e;
}
```

## Limitations & Workarounds

### Fundamental Limitations

#### 1. No Cursor Support
**Issue**: The `cursor()` method requires PDO streaming, which Neo4j doesn't support.

**Workaround**: Use `lazy()` instead:
```php
// DOESN'T WORK
User::cursor()->each(function ($user) { });

// WORKS - Use lazy() for memory-efficient iteration
User::lazy()->each(function ($user) { });
User::lazy(100)->each(function ($user) { }); // Batch size of 100
```

#### 2. No Schema Migrations (Traditional)
**Issue**: Neo4j is schemaless - no columns to migrate.

**Workaround**: Use indexes and constraints instead:
```php
use Look\EloquentCypher\Facades\Neo4jSchema;

Neo4jSchema::label('users', function ($label) {
    $label->property('email')->unique();
    $label->property('name')->index();
    $label->index(['name', 'created_at']); // Composite index
});
```

### Schema Introspection

Neo4j provides powerful schema introspection capabilities to explore your graph structure programmatically.

#### Getting All Labels (Node Types)

```php
use Look\EloquentCypher\Facades\Neo4jSchema;

$labels = Neo4jSchema::getAllLabels();
// Returns: ['User', 'Post', 'Comment', 'Tag']
```

**Note**: Neo4j's metadata persists even after deleting all nodes with a label. Once a label has been used, it remains in the database metadata.

#### Getting All Relationship Types

```php
$relationshipTypes = Neo4jSchema::getAllRelationshipTypes();
// Returns: ['WROTE', 'LIKES', 'FOLLOWS', 'TAGGED_WITH']
```

This is useful for:
- Generating documentation
- Building dynamic UI components
- Validating graph structure
- Migration planning

#### Getting All Property Keys

```php
$propertyKeys = Neo4jSchema::getAllPropertyKeys();
// Returns: ['id', 'name', 'email', 'title', 'content', 'created_at', 'updated_at']
```

Property keys persist in metadata even after properties are removed from all nodes.

#### Getting Constraints

```php
$constraints = Neo4jSchema::getConstraints();
// Returns array of constraint details:
// [
//     [
//         'name' => 'user_email_unique',
//         'type' => 'UNIQUENESS',
//         'entityType' => 'NODE',
//         'labelsOrTypes' => ['User'],
//         'properties' => ['email']
//     ],
//     [
//         'name' => 'post_slug_unique',
//         'type' => 'UNIQUENESS',
//         'entityType' => 'NODE',
//         'labelsOrTypes' => ['Post'],
//         'properties' => ['slug']
//     ]
// ]
```

Constraint types include:
- `UNIQUENESS` - Unique constraint (fully supported in Community Edition)

#### Getting Indexes

```php
$indexes = Neo4jSchema::getIndexes();
// Returns array of index details:
// [
//     [
//         'name' => 'user_name_index',
//         'type' => 'RANGE',
//         'entityType' => 'NODE',
//         'labelsOrTypes' => ['User'],
//         'properties' => ['name'],
//         'state' => 'ONLINE'
//     ],
//     [
//         'name' => 'post_title_text_index',
//         'type' => 'TEXT',
//         'entityType' => 'NODE',
//         'labelsOrTypes' => ['Post'],
//         'properties' => ['title'],
//         'state' => 'ONLINE'
//     ]
// ]
```

Index types include:
- `RANGE` - Standard B-tree index for range queries
- `TEXT` - Full-text search index
- `POINT` - Spatial index for geographic data
- `LOOKUP` - System indexes (usually start with `__`)

Index states:
- `ONLINE` - Index is ready to use
- `POPULATING` - Index is being built
- `FAILED` - Index creation failed

#### Complete Schema Introspection

Get everything in one call:

```php
$schema = Neo4jSchema::introspect();

// Returns:
// [
//     'labels' => ['User', 'Post', 'Comment'],
//     'relationshipTypes' => ['WROTE', 'LIKES'],
//     'propertyKeys' => ['id', 'name', 'email'],
//     'constraints' => [...],
//     'indexes' => [...]
// ]
```

#### Practical Use Cases

**1. Schema Documentation Generator**
```php
$schema = Neo4jSchema::introspect();

foreach ($schema['labels'] as $label) {
    echo "## Label: $label\n";

    // Find all constraints for this label
    $labelConstraints = array_filter($schema['constraints'], function($c) use ($label) {
        return in_array($label, $c['labelsOrTypes']);
    });

    foreach ($labelConstraints as $constraint) {
        echo "  - {$constraint['type']}: " . implode(', ', $constraint['properties']) . "\n";
    }
}
```

**2. Schema Validation**
```php
// Ensure required indexes exist
$indexes = Neo4jSchema::getIndexes();
$indexNames = array_column($indexes, 'name');

$requiredIndexes = ['user_email_index', 'post_slug_index'];
$missingIndexes = array_diff($requiredIndexes, $indexNames);

if (!empty($missingIndexes)) {
    throw new \Exception("Missing indexes: " . implode(', ', $missingIndexes));
}
```

**3. Dynamic Schema Discovery**
```php
// Build dynamic query builder based on available properties
$properties = Neo4jSchema::getAllPropertyKeys();

foreach ($properties as $property) {
    if (str_ends_with($property, '_at')) {
        // Likely a timestamp field
        $filters[$property] = 'date';
    } elseif (str_ends_with($property, '_id')) {
        // Likely a foreign key
        $filters[$property] = 'integer';
    }
}
```

**4. Migration Safety Checks**
```php
// Before dropping a label, check for relationships
$labels = Neo4jSchema::getAllLabels();
$relationshipTypes = Neo4jSchema::getAllRelationshipTypes();

if (in_array('User', $labels) && in_array('WROTE', $relationshipTypes)) {
    echo "Warning: User label has WROTE relationships that may be affected\n";
}
```

**5. Graph Visualization Metadata**
```php
// Prepare data for graph visualization tools
$schema = Neo4jSchema::introspect();

$graphMetadata = [
    'nodes' => array_map(function($label) use ($schema) {
        $constraints = array_filter($schema['constraints'], fn($c) =>
            in_array($label, $c['labelsOrTypes'])
        );

        return [
            'label' => $label,
            'uniqueProperties' => array_filter(array_map(
                fn($c) => $c['type'] === 'UNIQUENESS' ? $c['properties'][0] : null,
                $constraints
            ))
        ];
    }, $schema['labels']),

    'edges' => $schema['relationshipTypes']
];
```

### Performance Limitations

#### 1. HasManyThrough Without Native Edges
**Issue**: Uses reflection when not using native edges, causing slow queries.

**Solution**: Enable native edges:
```php
class Country extends Neo4JModel
{
    use Neo4jNativeRelationships;

    public function posts()
    {
        return $this->hasManyThrough(Post::class, User::class)
            ->useNativeEdges(); // Direct graph traversal
    }
}
```

#### 2. Complex Aggregations
**Issue**: Some GROUP BY operations may be slower than SQL.

**Workaround**: Use raw Cypher for complex aggregations:
```php
$results = DB::connection('neo4j')->cypher('
    MATCH (u:users)-[:POSTED]->(p:posts)
    WITH u, count(p) as post_count, avg(p.views) as avg_views
    WHERE post_count > 10
    RETURN u.name, post_count, avg_views
    ORDER BY avg_views DESC
');
```

#### 3. Large Offset Pagination
**Issue**: SKIP becomes slow with large offsets.

**Workaround**: Use cursor-based pagination:
```php
// Avoid large offsets
User::paginate(20, ['*'], 'page', 10000); // Slow!

// Use cursor pagination
User::where('id', '>', $lastId)
    ->limit(20)
    ->orderBy('id')
    ->get();
```

## Migration Guide

### From MySQL/PostgreSQL to Neo4j

#### Step 1: Update Models

```php
// Before (MySQL)
use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    protected $table = 'users';
}

// After (Neo4j)
use Look\EloquentCypher\Neo4JModel;

class User extends Neo4JModel
{
    protected $connection = 'neo4j';
    protected $table = 'users'; // Becomes label
}
```

#### Step 2: Migrate Data

```php
// One-time data migration script
use App\Models\MySQLUser;
use App\Models\Neo4jUser;

MySQLUser::chunk(1000, function ($users) {
    foreach ($users as $user) {
        Neo4jUser::create($user->toArray());
    }
});
```

#### Step 3: Update Relationships (Optional)

```bash
# Check compatibility
php artisan neo4j:check-compatibility

# Migrate to native edges
php artisan neo4j:migrate-to-edges --model=User --relation=posts
```

### Schema Introspection with Artisan Commands

Eloquent Cypher provides comprehensive artisan commands for exploring your Neo4j database schema interactively from the command line.

#### Complete Schema Overview

```bash
# Display complete schema with all components
php artisan neo4j:schema

# JSON output for programmatic use
php artisan neo4j:schema --json

# Minimal output without property details
php artisan neo4j:schema --compact
```

#### Node Labels

```bash
# List all node labels
php artisan neo4j:schema:labels

# Show node labels with counts
php artisan neo4j:schema:labels --count
```

Output example:
```
┌─────────────┬───────┐
│ Label       │ Count │
├─────────────┼───────┤
│ User        │ 1523  │
│ Post        │ 8412  │
│ Comment     │ 24158 │
└─────────────┴───────┘
```

#### Relationship Types

```bash
# List all relationship types
php artisan neo4j:schema:relationships

# Show relationship types with counts
php artisan neo4j:schema:relationships --count
```

#### Property Keys

```bash
# List all property keys used across nodes and relationships
php artisan neo4j:schema:properties
```

#### Constraints

```bash
# List all constraints with details
php artisan neo4j:schema:constraints

# Filter by constraint type
php artisan neo4j:schema:constraints --type=UNIQUENESS
php artisan neo4j:schema:constraints --type=NODE_KEY
```

Output example:
```
┌─────────────────────┬────────────┬─────────────┬──────────┬────────────┐
│ Name                │ Type       │ Entity Type │ Label    │ Properties │
├─────────────────────┼────────────┼─────────────┼──────────┼────────────┤
│ user_email_unique   │ UNIQUENESS │ NODE        │ User     │ email      │
│ post_slug_unique    │ UNIQUENESS │ NODE        │ Post     │ slug       │
└─────────────────────┴────────────┴─────────────┴──────────┴────────────┘
```

#### Indexes

```bash
# List all indexes (excluding system indexes)
php artisan neo4j:schema:indexes

# Filter by index type
php artisan neo4j:schema:indexes --type=RANGE
php artisan neo4j:schema:indexes --type=FULLTEXT
```

Output example:
```
┌──────────────────┬───────┬─────────────┬───────┬────────────┬────────┐
│ Name             │ Type  │ Entity Type │ Label │ Properties │ State  │
├──────────────────┼───────┼─────────────┼───────┼────────────┼────────┤
│ user_name_index  │ RANGE │ NODE        │ User  │ name       │ ONLINE │
│ post_title_index │ RANGE │ NODE        │ Post  │ title      │ ONLINE │
└──────────────────┴───────┴─────────────┴───────┴────────────┴────────┘
```

#### Export Schema

```bash
# Export complete schema to JSON file
php artisan neo4j:schema:export schema.json

# Export as YAML
php artisan neo4j:schema:export schema.yaml --format=yaml

# Creates directory if it doesn't exist
php artisan neo4j:schema:export exports/prod/schema.json
```

The exported schema includes:
- All node labels
- All relationship types
- All property keys
- Complete constraint definitions
- Complete index definitions

#### Programmatic Schema Introspection

For programmatic access in your application code:

```php
use Look\EloquentCypher\Facades\Neo4jSchema;

// Get complete schema
$schema = Neo4jSchema::introspect();

// Access specific components
$labels = Neo4jSchema::getAllLabels();
$relationships = Neo4jSchema::getAllRelationshipTypes();
$properties = Neo4jSchema::getAllPropertyKeys();
$constraints = Neo4jSchema::getConstraints();
$indexes = Neo4jSchema::getIndexes();

// Check for specific schema elements
if (Neo4jSchema::hasLabel('User')) {
    // Label exists
}

if (Neo4jSchema::hasConstraint('user_email_unique')) {
    // Constraint exists
}

if (Neo4jSchema::hasIndex('user_name_index')) {
    // Index exists
}
```

### Gradual Migration Strategy

1. **Dual-Write Pattern**
```php
class User extends Model
{
    protected static function booted()
    {
        static::created(function ($user) {
            // Sync to Neo4j
            Neo4jUser::create($user->toArray());
        });
    }
}
```

2. **Feature Flags**
```php
class UserRepository
{
    public function find($id)
    {
        if (config('features.use_neo4j')) {
            return Neo4jUser::find($id);
        }
        return MySQLUser::find($id);
    }
}
```

3. **Read from Both**
```php
$mysqlUser = MySQLUser::find($id);
$neo4jUser = Neo4jUser::find($id);

// Compare and log differences
if ($mysqlUser->toArray() !== $neo4jUser->toArray()) {
    Log::warning('Data mismatch', [...]);
}
```

## Native Graph Relationships

### Enabling Native Edges

#### Model-Level Configuration
```php
class User extends Neo4JModel
{
    use Neo4jNativeRelationships;

    // Enable for all relationships on this model
    protected $useNativeRelationships = true;
}
```

#### Relationship-Level Configuration
```php
public function posts()
{
    return $this->hasMany(Post::class)
        ->useNativeEdges()  // Just this relationship
        ->withEdgeType('AUTHORED'); // Custom edge type
}
```

#### Query-Time Configuration
```php
// Temporary override
$posts = $user->posts()->useForeignKeys()->get();
$posts = $user->posts()->useNativeEdges()->get();
```

### Edge Properties (Pivot Data)

```php
// BelongsToMany with edge properties
public function roles()
{
    return $this->belongsToMany(Role::class)
        ->useNativeEdges()
        ->withPivot(['granted_at', 'expires_at'])
        ->withTimestamps();
}

// Accessing edge properties
$user = User::find(1);
foreach ($user->roles as $role) {
    echo $role->pivot->granted_at;
    echo $role->pivot->expires_at;
}

// Updating edge properties
$user->roles()->updateExistingPivot($roleId, [
    'expires_at' => now()->addYear()
]);
```

### Graph Traversal Queries

```php
// Direct graph traversal with HasManyThrough
class Country extends Neo4JModel
{
    public function posts()
    {
        return $this->hasManyThrough(Post::class, User::class)
            ->useNativeEdges();
        // Generates: (country)-[:HAS_USERS]->(user)-[:HAS_POSTS]->(post)
    }
}

// Complex graph patterns
$results = User::joinPattern('
    (u:users)-[:FRIEND_OF]-(friend:users),
    (friend)-[:POSTED]->(p:posts)
')
->where('p.created_at', '>', now()->subDays(7))
->select(['u.name', 'friend.name as friend_name', 'count(p) as recent_posts'])
->get();
```

### Migration Commands

```bash
# Check if models are compatible with native edges
php artisan neo4j:check-compatibility

# Dry run to see what would be migrated
php artisan neo4j:migrate-to-edges --model=User --dry-run

# Migrate specific relationship
php artisan neo4j:migrate-to-edges --model=User --relation=posts

# Migrate all relationships for a model
php artisan neo4j:migrate-to-edges --model=User

# Rollback to foreign keys
php artisan neo4j:migrate-to-edges --model=User --rollback
```

## Performance Considerations

### Query Optimization

#### 1. Use Indexes
```php
// Create indexes for frequently queried properties
Schema::connection('neo4j')->create('users', function ($blueprint) {
    $blueprint->index('email');
    $blueprint->index('created_at');
    $blueprint->index(['status', 'created_at'])->composite();
});
```

#### 2. Eager Loading
```php
// Avoid N+1 queries
$users = User::with(['posts', 'comments'])->get();

// Nested eager loading
$users = User::with(['posts.comments.author'])->get();

// Conditional eager loading
$users = User::with(['posts' => function ($query) {
    $query->where('published', true);
}])->get();
```

#### 3. Select Only Needed Properties
```php
// Don't fetch everything
$users = User::select(['id', 'name', 'email'])->get();

// For counts, use aggregation
$count = User::where('status', 'active')->count();
```

#### 4. Use Native Edges for Complex Queries
```php
// Slow: Foreign key traversal
$posts = Country::find(1)
    ->users()
    ->get()
    ->flatMap->posts;

// Fast: Direct graph traversal
$posts = Country::find(1)
    ->posts()  // HasManyThrough with native edges
    ->get();
```

### Connection Pooling

Configure for high-traffic applications:

```php
'pool' => [
    'enabled' => true,
    'max_connections' => 20,  // Increase for high traffic
    'acquire_timeout' => 10000,  // Wait up to 10 seconds
    'max_lifetime' => 3600000,  // Refresh connections hourly
]
```

### Read/Write Splitting

Distribute load across multiple instances:

```php
'read' => [
    'host' => [
        'neo4j-read1.example.com',
        'neo4j-read2.example.com',
        'neo4j-read3.example.com',
    ],
    'sticky' => true,  // Use same read server after writes
],
'write' => [
    'host' => 'neo4j-write.example.com',
]
```

### Memory Management

```php
// Process large datasets in chunks
User::chunk(1000, function ($users) {
    foreach ($users as $user) {
        // Process user
    }
});

// Or use lazy loading
User::lazy(500)->each(function ($user) {
    // Process user
    // Automatically frees memory after each batch
});
```

## Troubleshooting

### Common Issues

#### Connection Refused
```bash
# Check if Neo4j is running
docker ps | grep neo4j

# Check logs
docker logs neo4j-eloquent

# Verify port binding
netstat -an | grep 7688

# Test connection with cypher-shell
cypher-shell -a bolt://localhost:7688 -u neo4j -p password
```

#### Authentication Failed
```php
// Verify credentials
DB::connection('neo4j')->cypher('RETURN 1');

// Check Neo4j auth settings
// In neo4j.conf:
// dbms.security.auth_enabled=true
```

#### Class Not Found
```bash
# Regenerate autoload
composer dump-autoload

# Clear Laravel cache
php artisan cache:clear
php artisan config:clear
```

#### Slow Queries
```php
// Enable query logging
DB::connection('neo4j')->enableQueryLog();

// Run queries...

// Check execution
$queries = DB::connection('neo4j')->getQueryLog();
foreach ($queries as $query) {
    echo "Query: " . $query['query'] . "\n";
    echo "Time: " . $query['time'] . "ms\n";
}

// Use EXPLAIN to analyze
$plan = DB::connection('neo4j')->cypher('EXPLAIN ' . $cypher);
```

#### Transaction Deadlocks
```php
// Increase transaction timeout
'neo4j' => [
    'transaction_timeout' => 30000,  // 30 seconds
]

// Use retry logic
$attempts = 0;
$maxAttempts = 3;

while ($attempts < $maxAttempts) {
    try {
        DB::transaction(function () {
            // Your operations
        });
        break;
    } catch (\Exception $e) {
        if (++$attempts >= $maxAttempts) {
            throw $e;
        }
        sleep(1);  // Wait before retry
    }
}
```

### Debugging Tools

#### Query Debugging
```php
// Get generated Cypher
$cypher = User::where('age', '>', 25)->toCypher();
echo $cypher;

// Dump queries
User::where('age', '>', 25)->dump();

// Die and dump
User::where('age', '>', 25)->dd();
```

#### Neo4j Browser
Access the web interface:
- Default: http://localhost:7474
- Custom: http://localhost:7475 (if using port 7688 for Bolt)

#### APOC Procedures
If APOC is installed:
```php
// Check APOC availability
$procedures = DB::connection('neo4j')->cypher('
    CALL dbms.procedures()
    YIELD name
    WHERE name STARTS WITH "apoc"
    RETURN name
');

// Use APOC for advanced operations
$result = DB::connection('neo4j')->cypher('
    CALL apoc.meta.stats() YIELD nodeCount, relCount
');
```

### Getting Help

1. **Check Test Suite**: Browse `tests/` directory for usage examples
2. **Enable Debug Mode**: Set `APP_DEBUG=true` in `.env`
3. **Query Logs**: Enable query logging to see generated Cypher
4. **GitHub Issues**: Report bugs at [github.com/looksystems/eloquent-cypher](https://github.com/looksystems/eloquent-cypher/issues)

## Best Practices

1. **Always use indexes** for frequently queried properties
2. **Prefer native edges** for relationship-heavy queries
3. **Use eager loading** to prevent N+1 queries
4. **Chunk large operations** to manage memory
5. **Monitor query performance** with logging
6. **Use transactions** for data consistency
7. **Configure connection pooling** for production
8. **Set appropriate timeouts** for long-running queries
9. **Use raw Cypher** for complex graph algorithms
10. **Test migrations** thoroughly before production deployment