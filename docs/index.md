# Eloquent Cypher: Neo4j for Laravel Developers

Welcome to Eloquent Cypher—a Laravel package that brings the full power of Eloquent ORM to Neo4j graph databases. If you know Laravel's Eloquent, you already know how to use this package.

## What is Eloquent Cypher?

Eloquent Cypher provides **100% Eloquent API compatibility** for Neo4j. Change your base class from `Model` to `GraphModel`, configure your connection, and everything else works identically:

```php
// Standard Eloquent
class User extends Model
{
    protected $fillable = ['name', 'email'];
}

// Eloquent Cypher - Same API, Different Database
class User extends GraphModel
{
    protected $connection = 'graph';
    protected $fillable = ['name', 'email'];
}

// Everything else is identical
$user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
$friends = $user->friends()->where('active', true)->get();
```

## Philosophy: Eloquent First, Graph Benefits Second

**Same Code, Graph Database**: All standard Eloquent operations work identically—CRUD, relationships, eager loading, query scopes, soft deletes, attribute casting, timestamps, mass assignment protection.

**Graph Superpowers When You Need Them**: Multi-label nodes, native Neo4j edges, Cypher DSL for graph traversal, percentile/collect aggregates, managed transactions with auto-retry.

**Zero Learning Curve**: Laravel developers can start immediately. Learn Neo4j-specific features progressively as your use cases demand them.

## Eloquent Concepts Map Directly to Neo4j

Your mental model translates perfectly:

| SQL/Eloquent Concept | Neo4j Equivalent | Eloquent Cypher Behavior |
|---------------------|------------------|-------------------------|
| **Table** | Label | Your model's table name becomes a Neo4j label (e.g., `users`) |
| **Row** | Node | Each Eloquent model instance represents a Neo4j node |
| **Column** | Property | Model attributes map to node properties |
| **Foreign Key** | Property + Optional Edge | Can use property-based (like SQL) or native edges |
| **Join** | Relationship/Edge | Use property-based or native graph relationships |
| **Index** | Index/Constraint | Same schema builder API (`Schema::table()`) |
| **Primary Key** | Node Property | Auto-generated unique IDs (not auto-incrementing) |
| **Pivot Table** | Intermediate Node/Edge | Supports both approaches with `belongsToMany()` |

**Key Difference**: Neo4j uses unique string/integer IDs instead of auto-incrementing integers. Set `$incrementing = false` on your models.

## When to Use Eloquent Cypher

**Ideal Use Cases**:
- **Complex Relationships**: Social networks, recommendation engines, permission systems
- **Graph Traversal**: Finding paths, connections, communities, or influence chains
- **Multi-Hop Queries**: "Friends of friends who like similar products"
- **Evolving Schemas**: Add new relationship types without migrations
- **Laravel Compatibility**: Existing Laravel app that needs graph capabilities

**What You Gain Over SQL**:
- **Performant Traversals**: Multi-hop relationships are O(1) lookups, not exponential JOINs
- **Flexible Relationships**: Add new relationship types without altering table structure
- **Native Graph Queries**: Cypher DSL for shortest paths, pattern matching, community detection
- **Rich Edges**: Store properties on relationships (more than pivot tables)

**What Stays the Same**:
- All Eloquent APIs, features, and patterns you already know
- Familiar Laravel ecosystem (migrations, seeders, factories, tests)
- Drop-in replacement for MySQL/PostgreSQL in relationship-heavy domains

## Quick Example: Eloquent vs Eloquent Cypher

The code is **identical**. Only the base class changes:

```php
// app/Models/User.php (Eloquent Cypher)
use Look\EloquentCypher\GraphModel;

class User extends GraphModel
{
    protected $connection = 'graph';
    protected $fillable = ['name', 'email', 'password'];
    protected $hidden = ['password'];

    public function posts()
    {
        return $this->hasMany(Post::class);
    }

    public function friends()
    {
        return $this->belongsToMany(User::class, 'friendships');
    }
}

// All standard Eloquent operations work identically:

// Create
$user = User::create([
    'name' => 'Alice',
    'email' => 'alice@example.com',
    'password' => bcrypt('secret'),
]);

// Read
$alice = User::where('email', 'alice@example.com')->first();
$activeUsers = User::where('active', true)
    ->orderBy('created_at', 'desc')
    ->take(10)
    ->get();

// Update
$alice->update(['name' => 'Alice Smith']);
$alice->email = 'alice.smith@example.com';
$alice->save();

// Delete
$alice->delete(); // Soft delete if using GraphSoftDeletes trait
$alice->forceDelete(); // Permanent deletion

// Relationships (100% Eloquent API)
$posts = $alice->posts; // Lazy loading
$alice->posts()->create(['title' => 'My First Post']); // Create related
$friends = $alice->friends()->with('posts')->get(); // Eager loading

// Eloquent features work identically
User::firstOrCreate(['email' => 'bob@example.com'], ['name' => 'Bob']);
User::updateOrCreate(['email' => 'charlie@example.com'], ['name' => 'Charlie']);
User::where('created_at', '<', now()->subDays(30))->chunk(100, function ($users) {
    foreach ($users as $user) {
        // Process old users
    }
});
```

**Neo4j-Specific Enhancements** (when you need them):

```php
// Multi-label nodes (organize and optimize queries)
class User extends GraphModel
{
    protected $labels = ['Person', 'Individual']; // Node is :users:Person:Individual
}

// Native Neo4j aggregates
$medianAge = User::percentileCont('age', 0.5); // Median
$p95ResponseTime = Log::percentileDisc('response_time', 0.95); // 95th percentile
$allTags = Post::collect('tags'); // Collect all tag arrays into one

// Cypher DSL for graph traversal
use Look\EloquentCypher\Builders\Neo4jCypherDslBuilder;

$friendsOfFriends = User::match()
    ->outgoing('FRIENDS_WITH', 'friend')
    ->outgoing('FRIENDS_WITH', 'fof')
    ->where('fof', '<>', $user->id)
    ->get(); // Friends of friends excluding self

$shortestPath = $user->matchFrom()
    ->shortestPath('person', 'KNOWS', 1, 5)
    ->to(Person::class, $target->id)
    ->get();

// Managed transactions with automatic retry
use Illuminate\Support\Facades\DB;

DB::connection('graph')->write(function ($tx) use ($user, $amount) {
    // Write transaction with automatic retry on transient errors
    $user->increment('balance', $amount);
    Transaction::create(['user_id' => $user->id, 'amount' => $amount]);
}, maxRetries: 5);

DB::connection('graph')->read(function ($tx) {
    // Read-only transaction (routed to read replicas if configured)
    return User::with('friends')->where('active', true)->get();
});
```

## Complete Feature Compatibility

**Standard Eloquent Features** (100% compatible):

✅ **CRUD Operations**: `create()`, `find()`, `update()`, `delete()`, `destroy()`
✅ **Query Builder**: `where()`, `orWhere()`, `whereIn()`, `orderBy()`, `limit()`, `offset()`
✅ **Aggregates**: `count()`, `sum()`, `avg()`, `min()`, `max()`
✅ **Relationships**: `hasMany()`, `hasOne()`, `belongsTo()`, `belongsToMany()`, `hasManyThrough()`, `hasOneThrough()`, `morphMany()`, `morphTo()`, `morphToMany()`
✅ **Eager Loading**: `with()`, `load()`, `loadMissing()`, `withCount()`, `loadCount()`
✅ **Timestamps**: Automatic `created_at` and `updated_at`
✅ **Soft Deletes**: `GraphSoftDeletes` trait with `deleted_at`, `withTrashed()`, `restore()`
✅ **Attribute Casting**: `int`, `string`, `bool`, `array`, `json`, `datetime`, `encrypted`, custom casters
✅ **Mutators & Accessors**: `getAttribute`, `setAttribute`, modern attribute classes
✅ **Mass Assignment**: `$fillable`, `$guarded` protection
✅ **Query Scopes**: Local and global scopes
✅ **Model Events**: `creating`, `created`, `updating`, `updated`, `deleting`, `deleted`
✅ **Factories & Seeders**: Full compatibility with Laravel's testing tools

**Neo4j-Specific Features** (when you need graph superpowers):

🎯 **Multi-Label Nodes**: Organize nodes with multiple labels (`:users:Person:Individual`)
🎯 **Native Edges**: Optional native Neo4j relationships with properties
🎯 **Neo4j Aggregates**: `percentileDisc()`, `percentileCont()`, `stdev()`, `stdevp()`, `collect()`
🎯 **Cypher DSL**: Type-safe graph traversal, pattern matching, shortest paths
🎯 **Batch Operations**: 70% faster bulk inserts/updates with automatic batching
🎯 **Managed Transactions**: Auto-retry on transient errors with exponential backoff
🎯 **Schema Introspection**: Programmatic API and Artisan commands for exploring schema
🎯 **Hybrid Storage**: Flat arrays as native Neo4j lists, nested as JSON strings

## Your Journey Starts Here

### For New Users: Getting Started

Start with the fundamentals and build your first Neo4j-powered Laravel app:

1. **[Getting Started](getting-started.md)** - Installation, setup, first model (20 minutes)
2. **[Models and CRUD](models-and-crud.md)** - Creating models, CRUD operations, timestamps, casting (30 minutes)
3. **[Relationships](relationships.md)** - All relationship types, foreign keys vs native edges (45 minutes)
4. **[Querying](querying.md)** - Query builder, eager loading, scopes, aggregates (40 minutes)

**Recommended Path**: Follow the order above. Each guide builds on the previous, with complete working examples.

### For Experienced Laravel Developers: Jump In

If you're already comfortable with Eloquent, jump to specific topics:

- **[Neo4j Features Overview](neo4j-overview.md)** - Multi-labels, Cypher DSL, schema introspection, Neo4j aggregates
- **[Performance](performance.md)** - Batch operations, managed transactions, indexing strategies
- **[Troubleshooting](troubleshooting.md)** - Common issues, debugging techniques, solutions
- **[Quick Reference](quick-reference.md)** - Cheat sheets, comparison tables, configuration reference

### Complete Guide Navigation

**Foundation** (Start Here):
- **[Getting Started](getting-started.md)** - Installation, Neo4j setup, configuration, first model and queries
- **[Models and CRUD](models-and-crud.md)** - Model creation, CRUD operations, timestamps, casting, mutators, soft deletes
- **[Relationships](relationships.md)** - All 8 relationship types, foreign keys vs native edges, pivot operations
- **[Querying](querying.md)** - Query builder API, where clauses, eager loading, aggregates, scopes

**Advanced Features** (Level Up):
- **[Neo4j Features Overview](neo4j-overview.md)** - Multi-label nodes, Neo4j aggregates, Cypher DSL, schema introspection
- **[Performance](performance.md)** - Batch operations (70% faster), managed transactions, indexing, connection pooling

**Support** (Get Unstuck):
- **[Troubleshooting](troubleshooting.md)** - Connection issues, query problems, debugging techniques, getting help
- **[Quick Reference](quick-reference.md)** - Concept mapping, common patterns, configuration options, Artisan commands

## What Makes Eloquent Cypher Different

**Not a Fork**: Built on top of Laravel's Eloquent, extending it rather than replacing it. Use both SQL and Neo4j in the same app.

**Not a New API**: If you've used Eloquent, you already know 95% of this package. The API is identical.

**Not Just Query Translation**: Native Neo4j features (multi-labels, native edges, Cypher DSL, percentile aggregates) when you need them, standard Eloquent when you don't.

**Production-Ready**: Comprehensive test suite (1,513 tests), battle-tested in real applications, Laravel 10-12 support.

## Core Design Decisions

**Foreign Keys by Default**: Relationships use property-based foreign keys (like SQL) for 100% Eloquent compatibility and performance. Native edges are opt-in via `useNativeRelationships()`.

**Automatic Batching**: Bulk operations automatically batch (configurable size) for 50-70% performance improvement.

**Hybrid Array Storage**: Flat arrays stored as native Neo4j lists (fast), nested structures as JSON strings (APOC-compatible).

**Non-Incrementing IDs**: Neo4j doesn't auto-increment. Models use `$incrementing = false` and generate unique IDs (configurable).

**Multi-Label Support**: Models can have multiple Neo4j labels via `$labels` property for better organization and query optimization.

## What's Not Covered Here

This guide focuses on **using** Eloquent Cypher. For deeper topics, see:

- **Neo4j Fundamentals**: Learn Cypher and graph concepts at [neo4j.com/docs](https://neo4j.com/docs/)
- **Laravel Basics**: Eloquent, migrations, routing at [laravel.com/docs](https://laravel.com/docs)
- **Package Internals**: Architecture, contributing guidelines in repository source
- **Production Deployment**: Neo4j hosting, scaling, monitoring (separate DevOps topic)

## Next Steps

**New to Graph Databases?** Start with **[Getting Started](getting-started.md)** for installation and your first working model.

**Experienced with Eloquent?** Jump to **[Neo4j Features Overview](neo4j-overview.md)** to see what's possible beyond standard Eloquent.

**Need Quick Answers?** Check the **[Quick Reference](quick-reference.md)** for cheat sheets and comparison tables.

---

**Ready?** Let's build something with graphs. Start with **[Getting Started →](getting-started.md)**
