# Eloquent Cypher - Limitations & Workarounds

> **📢 Note**: Many performance limitations have been resolved! See sections marked with **✅ IMPROVED** for details on batch execution (70% faster), managed transactions with auto-retry, and enhanced error handling.

## Table of Contents
- [Fundamental Incompatibilities](#fundamental-incompatibilities)
- [Enterprise-Only Features](#enterprise-only-features)
- [Performance Limitations](#performance-limitations)
- [Behavioral Differences](#behavioral-differences)
- [Feature Limitations](#feature-limitations)
- [Workarounds & Solutions](#workarounds--solutions)
- [Migration Strategies](#migration-strategies)

## Fundamental Incompatibilities

These are core limitations that cannot be worked around due to fundamental differences between Neo4j and SQL databases.

### 1. Cursor Streaming Not Supported

**Issue**: The `cursor()` method requires PDO streaming capabilities that Neo4j doesn't provide.

**Impact**:
- Cannot use `cursor()` for memory-efficient iteration
- Affects applications processing millions of records

**Error Example**:
```php
// This will throw an exception
User::cursor()->each(function ($user) {
    // Process user
});
// Error: Cursor operations are not supported by Neo4j
```

**Workaround**: Use `lazy()` or `lazyById()` instead:

```php
// Option 1: lazy() - Chunks with automatic memory management
User::lazy()->each(function ($user) {
    // Processes in chunks of 1000 (default)
});

// Option 2: lazy() with custom chunk size
User::lazy(500)->each(function ($user) {
    // Processes in chunks of 500
});

// Option 3: lazyById() - More efficient for large datasets
User::lazyById(1000, 'id')->each(function ($user) {
    // Uses ID-based chunking for better performance
});

// Option 4: chunk() for more control
User::chunk(1000, function ($users) {
    foreach ($users as $user) {
        // Process user
    }
});
```

**Performance Comparison**:
| Method | Memory Usage | Speed | Use Case |
|--------|-------------|-------|----------|
| `cursor()` | ⭐⭐⭐⭐⭐ (Lowest) | ⭐⭐⭐ | Not available |
| `lazy()` | ⭐⭐⭐⭐ (Low) | ⭐⭐⭐⭐ | General use |
| `lazyById()` | ⭐⭐⭐⭐ (Low) | ⭐⭐⭐⭐⭐ | Large ordered sets |
| `chunk()` | ⭐⭐⭐ (Moderate) | ⭐⭐⭐⭐ | Batch processing |
| `get()` | ⭐ (Highest) | ⭐⭐⭐⭐⭐ | Small datasets |

### 2. No Traditional Schema Migrations

**Issue**: Neo4j is schemaless - nodes don't have predefined columns.

**Impact**:
- Cannot use traditional Laravel migrations
- No column definitions or alterations
- No foreign key constraints at database level

**What Doesn't Work**:
```php
// Traditional migration won't work
Schema::create('users', function (Blueprint $table) {
    $table->id();                    // No columns
    $table->string('name');           // No columns
    $table->timestamps();             // No columns
});
```

**Workaround**: Use indexes and constraints instead:

```php
use Look\EloquentCypher\Schema\Neo4jBlueprint;

// Create indexes and constraints
Schema::connection('graph')->create('users', function (Neo4jBlueprint $blueprint) {
    // Indexes for performance
    $blueprint->index('email')->unique();
    $blueprint->index('username')->unique();
    $blueprint->index(['last_name', 'first_name'])->composite();

    // Full-text search index
    $blueprint->fulltext(['bio', 'description'])->named('user_search');

    // Spatial index for location data
    $blueprint->point('location')->spatial();
});

// Add indexes to existing labels
Schema::connection('graph')->label('posts', function (Neo4jBlueprint $blueprint) {
    $blueprint->index('slug')->unique();
    $blueprint->index('published_at');
});
```

**Property Validation Alternative**:
```php
class User extends GraphModel
{
    protected static function booted()
    {
        // Validate required properties
        static::saving(function ($user) {
            $required = ['name', 'email'];
            foreach ($required as $field) {
                if (empty($user->$field)) {
                    throw new \Exception("Field {$field} is required");
                }
            }
        });
    }
}
```

## Neo4j Edition Compatibility

**This package is 100% compatible with Neo4j Community Edition.** No Enterprise Edition features are required or used.

All constraint and schema features available in this package work with the free Community Edition:
- ✅ Unique constraints
- ✅ Composite unique constraints
- ✅ Property indexes
- ✅ Composite indexes
- ✅ Text indexes
- ✅ Relationship indexes
- ✅ Schema introspection

**Note on Enterprise Edition**: Neo4j Enterprise Edition offers additional database-level features like:
- Node key constraints (composite uniqueness with existence)
- Property existence constraints (database-level NOT NULL)
- Role-based access control (RBAC)

These features are not implemented in this package as they would break Community Edition compatibility. Instead, use application-level validation as shown below.

### Application-Level Validation (Recommended)

For validation that works with both Community and Enterprise editions:

```php
class User extends GraphModel
{
    protected $required = ['email', 'name'];

    protected static function booted()
    {
        static::saving(function ($model) {
            // Required field validation
            foreach ($model->required as $property) {
                if (is_null($model->$property)) {
                    throw new \InvalidArgumentException(
                        "Property {$property} is required for " . class_basename($model)
                    );
                }
            }

            // Composite uniqueness validation
            $exists = static::where('email', $model->email)
                ->where('username', $model->username)
                ->where('id', '!=', $model->id)
                ->exists();

            if ($exists) {
                throw new \Exception('Email and username combination must be unique');
            }
        });
    }
}

// Or use Laravel's validation in controllers
class UserController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email|unique:neo4j,users,email',
            'name' => 'required|string',
        ]);

        return User::create($validated);
    }
}
```

### Role-Based Access Control (RBAC)

**Issue**: Database-level access control requires Enterprise Edition.

**Community Workaround**:
```php
// Implement access control in application layer
class SecureGraphModel extends GraphModel
{
    public static function bootSecureGraphModel()
    {
        // Apply tenant filtering
        static::addGlobalScope('tenant', function ($builder) {
            if (auth()->check()) {
                $builder->where('tenant_id', auth()->user()->tenant_id);
            }
        });
    }

    protected static function booted()
    {
        // Ensure tenant_id on creation
        static::creating(function ($model) {
            if (auth()->check() && !$model->tenant_id) {
                $model->tenant_id = auth()->user()->tenant_id;
            }
        });
    }
}
```

## Performance Limitations

### 1. HasManyThrough Without Native Edges

**Issue**: Foreign key mode uses complex reflection queries that are slow.

**Slow Query Example**:
```php
// Without native edges - uses reflection
class Country extends GraphModel
{
    public function posts()
    {
        return $this->hasManyThrough(Post::class, User::class);
    }
}

// Generated Cypher (inefficient):
MATCH (c:countries {id: 1})
MATCH (u:users) WHERE u.country_id = c.id
MATCH (p:posts) WHERE p.user_id = u.id
RETURN p
```

**Solution**: Enable native edges:
```php
class Country extends GraphModel
{
    use Neo4jNativeRelationships;

    public function posts()
    {
        return $this->hasManyThrough(Post::class, User::class)
            ->useNativeEdges();
    }
}

// Generated Cypher (efficient):
MATCH (c:countries {id: 1})-[:HAS_USERS]->(u:users)-[:HAS_POSTS]->(p:posts)
RETURN p
```

**Performance Improvement**: 10x-100x faster for deep relationships.

### 2. Large SKIP Values (Pagination)

**Issue**: Neo4j's SKIP operation becomes slow with large offsets.

**Problem Example**:
```php
// Very slow for large page numbers
$users = User::paginate(20, ['*'], 'page', 5000); // Page 5000 = SKIP 99980
```

**Solutions**:

**Option 1: Cursor-based pagination**:
```php
// Use ID-based pagination
$users = User::where('id', '>', $lastId)
    ->orderBy('id')
    ->limit(20)
    ->get();

// Implementation helper
class CursorPaginator
{
    public static function paginate($query, $perPage = 20, $cursor = null)
    {
        if ($cursor) {
            $query->where('id', '>', $cursor);
        }

        $results = $query->orderBy('id')->limit($perPage + 1)->get();

        $hasMore = $results->count() > $perPage;
        if ($hasMore) {
            $results->pop();
        }

        return [
            'data' => $results,
            'next_cursor' => $hasMore ? $results->last()->id : null,
            'has_more' => $hasMore,
        ];
    }
}
```

**Option 2: Keyset pagination**:
```php
// Use multiple columns for stable ordering
$users = User::where('created_at', '>', $lastCreatedAt)
    ->orWhere(function ($q) use ($lastCreatedAt, $lastId) {
        $q->where('created_at', $lastCreatedAt)
          ->where('id', '>', $lastId);
    })
    ->orderBy('created_at')
    ->orderBy('id')
    ->limit(20)
    ->get();
```

### 3. Complex GROUP BY Operations

**Issue**: Neo4j's aggregation is different from SQL GROUP BY.

**Problem Example**:
```php
// May not work as expected
User::select('city', DB::raw('COUNT(*) as count'))
    ->groupBy('city')
    ->having('count', '>', 10)
    ->get();
```

**Solution**: Use raw Cypher for complex aggregations:
```php
$results = DB::connection('graph')->cypher('
    MATCH (u:users)
    WITH u.city as city, COUNT(*) as user_count
    WHERE user_count > 10
    RETURN city, user_count
    ORDER BY user_count DESC
');

// Or use collection methods
$cities = User::get()
    ->groupBy('city')
    ->map->count()
    ->filter(fn($count) => $count > 10)
    ->sortByDesc(fn($count) => $count);
```

### 4. JOIN Operations

**Issue**: Neo4j doesn't have SQL-style JOINs.

**What Doesn't Work**:
```php
// SQL-style joins don't work
User::join('posts', 'users.id', '=', 'posts.user_id')->get();
```

**Solutions**:

**Option 1: Use relationships**:
```php
// Eager load relationships
$users = User::with('posts')->get();

// Query through relationships
$users = User::whereHas('posts', function ($q) {
    $q->where('published', true);
})->get();
```

**Option 2: Use graph patterns**:
```php
// Custom pattern matching
$results = User::joinPattern('(u:users)-[:POSTED]->(p:posts)')
    ->where('p.published', true)
    ->select(['u.*', 'p.title as post_title'])
    ->get();
```

**Option 3: Raw Cypher**:
```php
$results = DB::connection('graph')->cypher('
    MATCH (u:users)-[:POSTED]->(p:posts)
    WHERE p.published = true
    RETURN u, collect(p) as posts
');
```

## Behavioral Differences

### 1. NULL Handling

**SQL vs Neo4j**:
```php
// SQL: Returns no results (NULL != NULL)
User::where('email', '!=', null)->get();

// Neo4j: Automatically converted to IS NOT NULL
User::where('email', '!=', null)->get();
// Cypher: WHERE n.email IS NOT NULL
```

**Best Practice**:
```php
// Be explicit about NULL checks
User::whereNotNull('email')->get();
User::whereNull('email')->get();
```

### 2. Case Sensitivity

**Issue**: Neo4j is case-sensitive by default.

```php
// Case-sensitive search
User::where('email', 'JOHN@EXAMPLE.COM')->first(); // Won't find 'john@example.com'
```

**Solutions**:
```php
// Option 1: Store lowercase
class User extends GraphModel
{
    protected static function booted()
    {
        static::saving(function ($user) {
            $user->email = strtolower($user->email);
        });
    }
}

// Option 2: Case-insensitive search
User::whereRaw('toLower(n.email) = ?', [strtolower($email)])->first();

// Option 3: Use CONTAINS for partial matching
User::where('email', 'CONTAINS', 'john')->get();
```

### 3. Transaction Isolation

**Issue**: Neo4j has different transaction isolation than SQL databases.

**Neo4j Default**: READ_COMMITTED (can't change in Community Edition)

**✅ IMPROVED**: Managed transactions with automatic retry!

**Handling Concurrent Updates**:
```php
// Use managed transactions with automatic retry
DB::connection('graph')->write(function ($connection) use ($userId, $amount) {
    $user = User::find($userId);
    $user->balance += $amount;
    $user->save();
}, $maxRetries = 3);

// Automatic features:
// - Exponential backoff with jitter
// - Transient error detection
// - Automatic reconnection on stale connections
// - Query context in error messages

// Or use the standard Laravel approach (also enhanced)
DB::transaction(function () use ($userId, $amount) {
    $user = User::lockForUpdate()->find($userId);
    $user->balance += $amount;
    $user->save();
}, $attempts = 3);
```

## Feature Limitations

### 1. Subqueries

**Limited Support**: Neo4j doesn't support subqueries like SQL.

**What Doesn't Work**:
```php
// SQL-style subquery
User::whereIn('id', function ($query) {
    $query->select('user_id')
          ->from('posts')
          ->where('published', true);
})->get();
```

**Workaround**:
```php
// Option 1: Two queries
$userIds = Post::where('published', true)->pluck('user_id');
$users = User::whereIn('id', $userIds)->get();

// Option 2: Use relationships
$users = User::whereHas('posts', function ($query) {
    $query->where('published', true);
})->get();

// Option 3: Raw Cypher with WITH clause
$users = DB::connection('graph')->cypher('
    MATCH (p:posts {published: true})
    WITH DISTINCT p.user_id as user_id
    MATCH (u:users {id: user_id})
    RETURN u
');
```

### 2. Database Functions

**Issue**: Limited SQL function compatibility.

**Function Mapping**:
| SQL Function | Neo4j Equivalent | Notes |
|-------------|-----------------|--------|
| `CONCAT()` | `+` operator | String concatenation |
| `LENGTH()` | `size()` | String/collection length |
| `UPPER()` | `toUpper()` | Convert to uppercase |
| `LOWER()` | `toLower()` | Convert to lowercase |
| `SUBSTRING()` | `substring()` | Extract substring |
| `NOW()` | `datetime()` | Current datetime |
| `DATE()` | `date()` | Current date |
| `YEAR()` | `date().year` | Extract year |

**Using Functions**:
```php
// Use selectRaw for Neo4j functions
User::selectRaw('n.*, toUpper(n.name) as upper_name')->get();

// Date functions
User::whereRaw('date(n.created_at) = date()')->get(); // Today's users

// String functions
User::whereRaw('size(n.name) > 10')->get(); // Long names
```

### 3. Stored Procedures

**Issue**: No stored procedures like SQL databases.

**Alternative**: Use APOC procedures or custom extensions:

```php
// Check APOC availability
$hasApoc = DB::connection('graph')->cypher('
    CALL dbms.procedures()
    YIELD name
    WHERE name STARTS WITH "apoc"
    RETURN count(*) > 0 as has_apoc
')->first()->has_apoc;

// Use APOC procedures
if ($hasApoc) {
    // Generate UUID
    $uuid = DB::connection('graph')->cypher('
        CALL apoc.create.uuid() YIELD uuid RETURN uuid
    ')->first()->uuid;

    // JSON operations
    $json = DB::connection('graph')->cypher('
        CALL apoc.convert.toJson($data) YIELD value RETURN value
    ', ['data' => $complexData]);
}

// Custom logic in application layer
class Neo4jProcedures
{
    public static function calculateUserScore($userId)
    {
        // Complex logic that would be a stored procedure
        $user = User::find($userId);
        $postCount = $user->posts()->count();
        $commentCount = $user->comments()->count();
        $likeCount = $user->likes()->count();

        return ($postCount * 10) + ($commentCount * 5) + ($likeCount * 1);
    }
}
```

## Workarounds & Solutions

### General Strategies

#### 1. Use Native Graph Features
```php
// Instead of foreign keys, use edges
class User extends GraphModel
{
    use Neo4jNativeRelationships;
    protected $useNativeRelationships = true;
}
```

#### 2. Leverage Raw Cypher
```php
// For complex operations, use raw Cypher
$results = DB::connection('graph')->cypher('
    MATCH path = shortestPath(
        (a:users {id: $userA})-[:FRIEND_OF*]-(b:users {id: $userB})
    )
    RETURN path, length(path) as distance
', ['userA' => 1, 'userB' => 100]);
```

#### 3. Application-Level Solutions
```php
// Implement missing features in PHP
class Neo4jHelpers
{
    public static function median($collection, $property)
    {
        $values = $collection->pluck($property)->sort()->values();
        $count = $values->count();

        if ($count === 0) return null;
        if ($count === 1) return $values[0];

        $middle = floor($count / 2);

        if ($count % 2 === 0) {
            return ($values[$middle - 1] + $values[$middle]) / 2;
        }

        return $values[$middle];
    }
}
```

### Performance Optimization Techniques

#### 1. Batch Operations

**✅ IMPROVED**: Native batch execution now matches MySQL/Postgres performance!

```php
// Batch operations execute as single batch request (70% faster)
User::insert($data); // Single batch request, not N individual queries

// Batch upserts also optimized (48% faster)
User::upsert(
    [...1000 records...],
    ['email'],        // Unique by
    ['name', 'age']   // Update columns
);

// Configuration options
'neo4j' => [
    'batch_size' => 100,  // Chunk size for very large operations
    'enable_batch_execution' => true,  // Can disable if needed
]
```

#### 2. Query Result Caching
```php
// Cache expensive queries
$users = Cache::remember('active-users', 3600, function () {
    return User::where('status', 'active')
        ->with(['posts', 'comments'])
        ->get();
});
```

#### 3. Use Indexes Effectively
```php
// Ensure indexes exist for frequently queried fields
Schema::connection('graph')->label('users', function ($blueprint) {
    $blueprint->index('email');
    $blueprint->index('status');
    $blueprint->index(['status', 'created_at'])->composite();
});

// Query will use indexes automatically
User::where('status', 'active')
    ->where('created_at', '>', now()->subDays(30))
    ->get();
```

## Migration Strategies

### Moving from SQL to Neo4j

#### Phase 1: Parallel Running
```php
// Dual-write to both databases
class UserService
{
    public function createUser($data)
    {
        // Write to SQL (primary)
        $sqlUser = SqlUser::create($data);

        // Write to Neo4j (secondary)
        try {
            Neo4jUser::create($data);
        } catch (\Exception $e) {
            Log::error('Neo4j sync failed', ['error' => $e->getMessage()]);
        }

        return $sqlUser;
    }
}
```

#### Phase 2: Gradual Migration
```php
// Feature flag for gradual rollout
class UserRepository
{
    public function find($id)
    {
        $percentage = config('features.neo4j_percentage', 0);

        if (rand(1, 100) <= $percentage) {
            return $this->findInNeo4j($id);
        }

        return $this->findInSql($id);
    }
}
```

#### Phase 3: Full Migration
```php
// Final cutover with fallback
class UserRepository
{
    public function find($id)
    {
        try {
            return Neo4jUser::find($id);
        } catch (\Exception $e) {
            // Fallback to SQL if Neo4j fails
            Log::error('Neo4j query failed, falling back', ['error' => $e]);
            return SqlUser::find($id);
        }
    }
}
```

### Handling Migration Issues

#### Data Type Conversions
```php
// Handle data type differences
class DataMigrator
{
    public static function migrateUser($sqlUser)
    {
        return [
            'id' => (string) $sqlUser->id,  // Neo4j prefers strings
            'created_at' => $sqlUser->created_at->toIso8601String(),
            'metadata' => json_decode($sqlUser->metadata, true), // Native JSON
            'tags' => explode(',', $sqlUser->tags), // Array property
        ];
    }
}
```

#### Relationship Migration
```php
// Migrate foreign keys to edges
class RelationshipMigrator
{
    public static function migratePosts()
    {
        Post::chunk(1000, function ($posts) {
            foreach ($posts as $post) {
                // Create edge
                DB::connection('graph')->cypher('
                    MATCH (u:users {id: $userId})
                    MATCH (p:posts {id: $postId})
                    MERGE (u)-[:HAS_POST {created_at: $createdAt}]->(p)
                ', [
                    'userId' => $post->user_id,
                    'postId' => $post->id,
                    'createdAt' => $post->created_at,
                ]);
            }
        });
    }
}
```

## Summary

While Eloquent Cypher provides excellent compatibility with Laravel Eloquent, understanding these limitations and their workarounds is crucial for successful implementation. Most limitations can be addressed through:

1. **Alternative methods** (e.g., `lazy()` instead of `cursor()`)
2. **Application-level solutions** (validation, business logic)
3. **Native graph features** (edges, patterns, traversals)
4. **Raw Cypher queries** for complex operations
5. **Proper indexing** and query optimization

The key is to embrace Neo4j's graph nature while maintaining Eloquent compatibility where it makes sense. For graph-heavy operations, don't hesitate to use Neo4j's native capabilities through raw Cypher or the native relationship features.