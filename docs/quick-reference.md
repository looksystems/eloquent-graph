# Quick Reference Guide

**Eloquent Cypher** - Laravel Eloquent ORM for Neo4j Graph Database

This is your quick lookup guide for common operations, configuration options, and concept mappings. For detailed explanations, see the full user guides.

---

## 1. Concept Mapping

### SQL/Eloquent → Neo4j Translation Table

| SQL Concept | Neo4j Concept | Eloquent Cypher Implementation |
|-------------|---------------|-------------------------------|
| **Table** | Label | `protected $table = 'users'` creates `:users` label |
| **Row** | Node | Each Eloquent model instance is a node |
| **Column** | Property | Model attributes are node properties |
| **Primary Key** | Node ID | `protected $primaryKey = 'id'` |
| **Foreign Key** | Relationship Property | `user_id` stored on node (foreign_key mode) |
| **Join** | Relationship/Edge | `MATCH (u)-[:HAS_POSTS]->(p)` (edge mode) |
| **Index** | Index/Constraint | `Schema::index('users', 'email')` |
| **Unique Constraint** | Unique Constraint | `Schema::unique('users', 'email')` |
| **Schema Migration** | Graph Schema | Artisan commands & Schema builder |

### Eloquent API Mapping

| Standard Eloquent | Eloquent Cypher | Notes |
|-------------------|-----------------|-------|
| `class User extends Model` | `class User extends GraphModel` | Change base class |
| `$connection = 'mysql'` | `$connection = 'graph'` | Point to graph connection |
| `$incrementing = true` | `$incrementing = false` | Neo4j doesn't auto-increment |
| `find($id)` | `find($id)` | ✅ Identical API |
| `where('age', '>', 30)` | `where('age', '>', 30)` | ✅ Identical API |
| `$user->posts` | `$user->posts` | ✅ Identical API |
| `whereNotNull('email')` | `whereNotNull('email')` | ✅ Identical API |

### Storage Modes Comparison

| Mode | Foreign Keys | Native Edges | Use Case |
|------|--------------|--------------|----------|
| **foreign_key** | ✅ Yes | ❌ No | Legacy SQL migration, Eloquent compatibility |
| **edge** | ❌ No | ✅ Yes | Pure graph operations, graph traversal |
| **hybrid** | ✅ Yes | ✅ Yes | Maximum flexibility, recommended default |

---

## 2. Common Patterns

### Model Setup

```php
use Look\EloquentCypher\GraphModel;

class User extends GraphModel
{
    protected $connection = 'graph';
    protected $table = 'users';                    // Creates :users label
    protected $primaryKey = 'id';
    protected $keyType = 'int';
    public $incrementing = false;                  // Required for Neo4j

    protected $fillable = ['name', 'email', 'age'];
    protected $casts = [
        'email_verified_at' => 'datetime',
        'settings' => 'array',
    ];
}
```

### CRUD Operations

```php
// Create
$user = User::create([
    'name' => 'John Doe',
    'email' => 'john@example.com',
    'age' => 30
]);

// Read
$user = User::find(1);
$users = User::where('age', '>', 25)->get();
$active = User::where('status', 'active')->orderBy('name')->get();

// Update
$user->update(['name' => 'Jane Doe']);
User::where('age', '<', 18)->update(['category' => 'minor']);

// Delete
$user->delete();
User::where('inactive', true)->delete();
```

### Relationship Patterns

```php
// Define Relationships
class User extends GraphModel
{
    public function posts()
    {
        return $this->hasMany(Post::class);
    }

    public function profile()
    {
        return $this->hasOne(Profile::class);
    }

    public function roles()
    {
        return $this->belongsToMany(Role::class)
            ->withPivot('assigned_at')
            ->withTimestamps();
    }
}

// Use Relationships
$user->posts()->create(['title' => 'Hello World']);
$posts = $user->posts;
$author = $post->user;

// Eager Loading
$users = User::with('posts', 'profile')->get();
$users = User::with(['posts' => fn($q) => $q->where('published', true)])->get();

// Relationship Queries
User::has('posts')->get();
User::whereHas('posts', fn($q) => $q->where('published', true))->get();
User::withCount('posts')->get();
```

### Query Patterns

```php
// Basic Where Clauses
User::where('age', '>', 30)->get();
User::whereIn('status', ['active', 'premium'])->get();
User::whereBetween('age', [18, 65])->get();
User::whereNull('deleted_at')->get();

// Multiple Conditions
User::where('age', '>', 25)
    ->where('status', 'active')
    ->orWhere('role', 'admin')
    ->get();

// Nested Conditions
User::where(function ($q) {
    $q->where('status', 'active')
      ->orWhere('role', 'admin');
})->where('age', '>', 18)->get();

// Ordering & Limiting
User::orderBy('created_at', 'desc')->limit(10)->get();
User::latest()->take(20)->get();
User::oldest()->skip(10)->take(10)->get();

// Aggregations
User::count();
User::where('status', 'active')->sum('credits');
User::avg('age');
User::max('created_at');

// Neo4j-Specific Aggregates
User::percentileDisc('age', 0.95);              // 95th percentile
User::percentileCont('score', 0.5);             // Median
User::stdev('salary');                          // Standard deviation
User::collect('skills');                        // Collect into array
```

### Native Edge Relationships (Optional)

```php
// Enable native edges on model
use Look\EloquentCypher\Traits\Neo4jNativeRelationships;

class User extends GraphModel
{
    use Neo4jNativeRelationships;

    protected $useNativeRelationships = true;   // Use edges for all relationships

    public function posts()
    {
        // Creates native (:users)-[:HAS_POSTS]->(:posts) edge
        return $this->hasMany(Post::class)->useNativeEdges();
    }
}

// Query with edges
$user->posts;  // Uses graph traversal automatically
```

### Multi-Label Nodes

```php
class User extends GraphModel
{
    protected $table = 'users';                 // Primary label :users
    protected $labels = ['Person', 'Individual']; // Additional labels

    // Created nodes will have :users:Person:Individual
}

// Query with specific labels
User::withLabels(['Person'])->get();

// Check labels
$user->hasLabel('Person');  // true
$labels = $user->getLabels(); // ['users', 'Person', 'Individual']
```

---

## 3. Configuration Reference

### Basic Connection (config/database.php)

```php
'neo4j' => [
    // Required
    'driver' => 'neo4j',
    'host' => env('NEO4J_HOST', 'localhost'),
    'port' => env('NEO4J_PORT', 7687),
    'database' => env('NEO4J_DATABASE', 'neo4j'),
    'username' => env('NEO4J_USERNAME', 'neo4j'),
    'password' => env('NEO4J_PASSWORD', 'password'),

    // Performance
    'batch_size' => 100,                         // Batch size for bulk operations
    'enable_batch_execution' => true,            // 50-70% faster insert/upsert

    // Retry Configuration
    'retry' => [
        'max_attempts' => 3,                     // Retry on transient errors
        'initial_delay_ms' => 100,
        'max_delay_ms' => 5000,
        'multiplier' => 2.0,
        'jitter' => true,
    ],

    // Relationship Storage
    'default_relationship_storage' => 'hybrid',  // 'foreign_key' | 'edge' | 'hybrid'
    'auto_create_edges' => true,
    'auto_delete_edges' => true,
    'edge_naming_convention' => 'snake_case_upper', // HAS_POSTS, BELONGS_TO
],
```

### All Configuration Options

| Option | Default | Description |
|--------|---------|-------------|
| `batch_size` | `100` | Records per batch for bulk operations |
| `enable_batch_execution` | `true` | Enable automatic batching (50-70% faster) |
| `default_relationship_storage` | `'hybrid'` | Storage mode for relationships |
| `auto_create_edges` | `true` | Auto-create edges when relationships created |
| `auto_delete_edges` | `true` | Auto-delete edges when relationships deleted |
| `edge_naming_convention` | `'snake_case_upper'` | Edge type naming (HAS_POSTS) |
| `retry.max_attempts` | `3` | Max retry attempts on transient errors |
| `retry.initial_delay_ms` | `100` | Initial delay between retries |
| `retry.max_delay_ms` | `5000` | Maximum delay between retries |
| `retry.multiplier` | `2.0` | Exponential backoff multiplier |
| `retry.jitter` | `true` | Add randomness to retry delays |

### Environment Variables (.env)

```env
NEO4J_HOST=localhost
NEO4J_PORT=7687
NEO4J_DATABASE=neo4j
NEO4J_USERNAME=neo4j
NEO4J_PASSWORD=password
```

---

## 4. Artisan Commands

### Schema Introspection

```bash
# Complete schema overview
php artisan neo4j:schema
php artisan neo4j:schema --json          # JSON output

# List node labels
php artisan neo4j:schema:labels
php artisan neo4j:schema:labels --count  # With node counts

# List relationship types
php artisan neo4j:schema:relationships
php artisan neo4j:schema:relationships --count  # With relationship counts

# List property keys
php artisan neo4j:schema:properties

# List constraints
php artisan neo4j:schema:constraints
php artisan neo4j:schema:constraints --type=unique

# List indexes
php artisan neo4j:schema:indexes
php artisan neo4j:schema:indexes --type=btree

# Export schema to file
php artisan neo4j:schema:export schema.json
php artisan neo4j:schema:export schema.yaml --format=yaml
```

### Programmatic Schema Access

```php
use Look\EloquentCypher\Facades\Neo4jSchema;

// Get all labels
$labels = Neo4jSchema::getAllLabels();

// Get all relationship types
$types = Neo4jSchema::getAllRelationshipTypes();

// Get all property keys
$properties = Neo4jSchema::getAllPropertyKeys();

// Get constraints
$constraints = Neo4jSchema::getConstraints();

// Get indexes
$indexes = Neo4jSchema::getIndexes();

// Get complete schema
$schema = Neo4jSchema::introspect();
```

---

## 5. Comparison Tables

### Foreign Key vs Native Edge Mode

| Feature | Foreign Key Mode | Edge Mode | Hybrid Mode |
|---------|------------------|-----------|-------------|
| **Storage** | Node properties | Graph edges | Both |
| **Eloquent Compatibility** | 100% | 95% | 100% |
| **Graph Traversal** | Via WHERE | Native MATCH | Both options |
| **Performance** | Fast with indexes | Fast for traversals | Balanced |
| **Data Migration** | Easy from SQL | Requires conversion | Gradual migration |
| **Disk Usage** | Lower | Lower | Higher (duplicate) |
| **Recommended For** | SQL migrations | New projects | Production apps |

**Generated Cypher Examples:**

```cypher
-- Foreign Key Mode
MATCH (u:users {id: 1}), (p:posts)
WHERE p.user_id = u.id
RETURN p

-- Edge Mode
MATCH (u:users {id: 1})-[:HAS_POSTS]->(p:posts)
RETURN p

-- Hybrid Mode (uses edge, keeps foreign key for compatibility)
MATCH (u:users {id: 1})-[:HAS_POSTS]->(p:posts)
WHERE p.user_id = u.id
RETURN p
```

### Standard vs Neo4j Aggregates

| Function | Type | Description | Example |
|----------|------|-------------|---------|
| `count()` | Standard | Count records | `User::count()` |
| `sum($col)` | Standard | Sum values | `Order::sum('total')` |
| `avg($col)` | Standard | Average value | `User::avg('age')` |
| `min($col)` | Standard | Minimum value | `Product::min('price')` |
| `max($col)` | Standard | Maximum value | `Product::max('price')` |
| `percentileDisc($col, $p)` | Neo4j | Discrete percentile | `User::percentileDisc('age', 0.95)` |
| `percentileCont($col, $p)` | Neo4j | Continuous percentile | `User::percentileCont('score', 0.5)` |
| `stdev($col)` | Neo4j | Sample std deviation | `User::stdev('salary')` |
| `stdevp($col)` | Neo4j | Population std deviation | `User::stdevp('salary')` |
| `collect($col)` | Neo4j | Collect into array | `User::collect('skills')` |

### When to Use Each Feature

| Use Case | Recommended Approach |
|----------|---------------------|
| Migrating from MySQL/PostgreSQL | Start with `foreign_key` mode |
| New Neo4j project | Use `edge` or `hybrid` mode |
| Complex graph traversals | Enable native edges |
| Simple CRUD operations | Any mode works fine |
| Relationship properties (pivot data) | Use `belongsToMany` with edges |
| Performance-critical reads | Add indexes, use batch operations |
| Performance-critical writes | Use managed transactions with retry |
| Finding 95th percentile | `percentileDisc('column', 0.95)` |
| Calculating median | `percentileCont('column', 0.5)` |
| Statistical analysis | Use `stdev()` or `stdevp()` |
| Collecting related values | Use `collect()` aggregate |

---

## 6. Performance Tips

### Batch Operations

```php
// These are automatically batched (50-70% faster!)
User::insert([
    ['name' => 'User 1', 'email' => 'user1@example.com'],
    ['name' => 'User 2', 'email' => 'user2@example.com'],
    // ... 100 more records
]); // Single batch request, not 100 queries

User::upsert(
    [['email' => 'john@example.com', 'name' => 'John']],
    ['email'],  // Unique keys
    ['name']    // Columns to update
); // Automatically batched
```

### Managed Transactions

```php
use Illuminate\Support\Facades\DB;

// Write transaction with automatic retry
$user = DB::connection('graph')->write(function ($connection) {
    $user = User::create(['name' => 'John']);
    $user->posts()->create(['title' => 'Hello']);
    return $user;
}, $maxRetries = 3);

// Read transaction (uses read replicas in cluster)
$users = DB::connection('graph')->read(function ($connection) {
    return User::where('active', true)->get();
}, $maxRetries = 2);
```

### Eager Loading

```php
// Avoid N+1 queries
$users = User::with('posts')->get(); // 2 queries instead of N+1

// Nested eager loading
$users = User::with('posts.comments')->get();

// Constrained eager loading
$users = User::with(['posts' => fn($q) => $q->where('published', true)])->get();
```

### Indexing

```php
// Create indexes for frequently queried properties
Schema::connection('graph')->table('users', function ($table) {
    $table->index('email');
    $table->index('status');
    $table->unique('username');
});
```

---

## 7. Cypher DSL (Advanced)

### Quick Examples

```php
// Using the Facade
use Look\EloquentCypher\Facades\Cypher;

$users = Cypher::query()
    ->match('(u:users)')
    ->where('u.age', '>', 30)
    ->get();

// Using Model Integration (automatic hydration)
$users = User::match()
    ->where('age', '>', 30)
    ->orderBy('name')
    ->get(); // Returns Collection of User models

// Instance traversal
$posts = $user->matchFrom()
    ->outgoing('posts', 'p')
    ->get(); // Collection of Post models

// Path finding
$path = User::match()
    ->shortestPath('user', 'other', 'KNOWS', 1, 5)
    ->get();
```

For complete DSL documentation, see the [DSL Usage Guide](../specs/DSL_USAGE_GUIDE.md).

---

## 8. Array & JSON Operations

### Hybrid Storage Strategy

```php
// Flat arrays → Native Neo4j LISTs (no APOC needed!)
$user = User::create([
    'skills' => ['PHP', 'JavaScript', 'Go']
]);

// Nested structures → JSON strings
$user = User::create([
    'settings' => [
        'theme' => 'dark',
        'notifications' => ['email' => true, 'sms' => false]
    ]
]);
```

### Query JSON Data

```php
// JSON contains
User::whereJsonContains('skills', 'PHP')->get();
User::whereJsonContains('settings->theme', 'dark')->get();

// JSON length
User::whereJsonLength('skills', '>', 2)->get();
User::whereJsonLength('settings->notifications', '=', 2)->get();
```

---

## 9. Common Gotchas

### Primary Keys

```php
// ❌ Wrong - Neo4j doesn't auto-increment
public $incrementing = true;

// ✅ Correct
public $incrementing = false;
```

### Operator Mapping

```php
// ✅ Both work - automatically converted
User::where('age', '!=', 30)->get();  // Converts to <>
User::where('age', '<>', 30)->get();  // Native Cypher
```

### Raw Selects

```php
// ❌ Wrong - Missing node alias
User::selectRaw('name, email')->get();

// ✅ Correct - Include 'n.' prefix
User::selectRaw('n.name, n.email')->get();
```

### JSON Updates

```php
// ❌ Limited - Nested path updates not fully supported
$user->update(['settings->theme' => 'light']);

// ✅ Recommended - Update entire property
$settings = $user->settings;
$settings['theme'] = 'light';
$user->update(['settings' => $settings]);
```

---

## 10. Migration from SQL

### Model Conversion Checklist

```php
// Before (Standard Eloquent)
use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    protected $connection = 'mysql';
    protected $fillable = ['name', 'email'];
}

// After (Eloquent Cypher)
use Look\EloquentCypher\GraphModel;

class User extends GraphModel
{
    protected $connection = 'graph';            // Change connection
    protected $fillable = ['name', 'email'];    // ✅ Same
    public $incrementing = false;               // Add this
    protected $keyType = 'int';                 // Add this if using integers
}
```

### Relationship Migration Strategy

1. **Start with foreign_key mode** (easiest migration)
2. **Test thoroughly** (all queries work identically)
3. **Optionally migrate to hybrid** (get edge benefits)
4. **Eventually move to edge mode** (full graph power)

```php
// config/database.php
'neo4j' => [
    'default_relationship_storage' => 'foreign_key',  // Step 1: Safe migration
    // Later change to 'hybrid' or 'edge' when ready
],
```

---

## Next Steps

### For New Users
- **Getting Started**: See [getting-started.md](getting-started.md) for installation
- **Basic Usage**: See [models-and-crud.md](models-and-crud.md) for model basics
- **Relationships**: See [relationships.md](relationships.md) for relationship setup

### For SQL Developers
- **Concept Mapping**: Review section 1 of this guide frequently

### For Advanced Users
- **Performance**: See [performance.md](performance.md) for optimization
- **Neo4j Features**: See [neo4j-overview.md](neo4j-overview.md) for graph-specific features overview
- **Cypher DSL**: See [cypher-dsl.md](cypher-dsl.md) for graph traversal and pattern matching
- **Troubleshooting**: See [troubleshooting.md](troubleshooting.md) for common issues

### Documentation Hub
- **Main Index**: [index.md](index.md) - Complete navigation and overview

---

**Quick Links:**
- [Installation Guide](getting-started.md)
- [Models & CRUD](models-and-crud.md)
- [Relationships](relationships.md)
- [Querying](querying.md)
- [Neo4j Features Overview](neo4j-overview.md)
  - [Multi-Label Nodes](multi-label-nodes.md)
  - [Neo4j Aggregates](neo4j-aggregates.md)
  - [Cypher DSL](cypher-dsl.md)
  - [Schema Introspection](schema-introspection.md)
  - [Arrays & JSON](arrays-and-json.md)
- [Performance](performance.md)
- [Troubleshooting](troubleshooting.md)

---

**Package Version**: 1.2.0+
**Neo4j Compatibility**: Neo4j 4.x, 5.x (Community & Enterprise)
**Laravel Support**: 10.x, 11.x, 12.x
