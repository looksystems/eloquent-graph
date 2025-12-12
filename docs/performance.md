# Performance and Optimization Guide

This guide covers performance optimization techniques for Eloquent Cypher, from batch operations to indexing strategies. Learn how to make your Neo4j queries fast and reliable.

## Table of Contents
- [Batch Operations](#batch-operations)
- [Managed Transactions](#managed-transactions)
- [Indexing and Constraints](#indexing-and-constraints)
- [Connection Pooling](#connection-pooling)
- [Query Optimization](#query-optimization)
- [Next Steps](#next-steps)

---

## Batch Operations

Batch execution provides 50-70% performance improvement for bulk operations by sending multiple records in a single request to Neo4j.

### Automatic Batching

Batching is enabled by default and works automatically with Laravel's bulk methods:

```php
// Insert 100 users - automatically batched
User::insert([
    ['name' => 'Alice', 'email' => 'alice@example.com'],
    ['name' => 'Bob', 'email' => 'bob@example.com'],
    // ... 98 more records
]);
// Result: 1 batch request instead of 100 individual queries (70% faster!)

// Upsert 1000 records - automatically batched
User::upsert([
    ['email' => 'alice@example.com', 'name' => 'Alice Updated'],
    ['email' => 'bob@example.com', 'name' => 'Bob Updated'],
    // ... 998 more records
], ['email'], ['name', 'updated_at']);
// Result: 2 batch requests (500 each) instead of 1000 queries (48% faster!)
```

**✅ Same as Eloquent**: Use the same `insert()` and `upsert()` methods you already know. Batching happens automatically behind the scenes.

### Configuration

Control batch behavior in your `config/database.php`:

```php
'connections' => [
    'neo4j' => [
        'driver' => 'neo4j',
        // ... other settings ...

        // Batch execution settings
        'batch_size' => 500,              // Records per batch (default: 500)
        'enable_batch_execution' => true, // Enable batching (default: true)
    ],
],
```

| Setting | Default | Description |
|---------|---------|-------------|
| `batch_size` | 500 | Number of records processed per batch request |
| `enable_batch_execution` | true | Enable/disable automatic batching |

### Batch Size Guidelines

Choose batch size based on your data and network:

| Record Size | Recommended Batch Size | Use Case |
|-------------|------------------------|----------|
| Small (< 1KB) | 1000 | Simple records with few properties |
| Medium (1-10KB) | 500 | Standard models with relationships |
| Large (> 10KB) | 100 | Models with many properties/JSON |
| Very Large | 50 | Records with embedded documents |

### Performance Comparison

Real-world benchmarks:

```php
// Without batching (enable_batch_execution = false)
$start = microtime(true);
User::insert($records); // 100 records
$duration = microtime(true) - $start;
// Result: ~3.0 seconds (100 separate queries)

// With batching (enable_batch_execution = true, batch_size = 100)
$start = microtime(true);
User::insert($records); // 100 records
$duration = microtime(true) - $start;
// Result: ~0.9 seconds (1 batch request) - 70% improvement!
```

### When to Use Batching

**✅ Use batching for**:
- Initial data imports
- Bulk user registrations
- Data migrations
- Seeding test data
- ETL operations

**⚠️ Be careful with**:
- Individual record validation needs
- Complex error handling requirements
- Very large records (> 100KB each)
- Operations requiring immediate feedback per record

### Batch Operations Best Practices

```php
// Good: Use chunks for very large datasets
User::query()->chunk(500, function ($users) {
    // Process 500 users at a time
    $updates = $users->map(function ($user) {
        return [
            'id' => $user->id,
            'status' => 'processed',
            'updated_at' => now(),
        ];
    })->toArray();

    User::upsert($updates, ['id'], ['status', 'updated_at']);
});

// Good: Disable batching for real-time operations
config(['database.connections.graph.enable_batch_execution' => false]);
User::insert($singleRecord); // Immediate insertion
config(['database.connections.graph.enable_batch_execution' => true]);

// Good: Monitor batch performance
$start = microtime(true);
User::insert($records);
$duration = (microtime(true) - $start) * 1000;
Log::info("Batch insert completed", [
    'records' => count($records),
    'duration_ms' => $duration,
    'records_per_second' => count($records) / ($duration / 1000),
]);
```

---

## Managed Transactions

Managed transactions provide automatic retry with exponential backoff for handling transient errors like deadlocks and network issues.

### Write Transactions

Use `write()` for any data modification operations:

```php
use Illuminate\Support\Facades\DB;

// Create user with posts - automatic retry on transient errors
$userId = DB::connection('graph')->write(function ($connection) {
    $user = User::create([
        'name' => 'Alice',
        'email' => 'alice@example.com',
    ]);

    $user->posts()->create([
        'title' => 'My First Post',
        'content' => 'Hello Neo4j!',
    ]);

    return $user->id; // Return value is passed through
});
```

**Key Features**:
- Automatic retry on transient errors (deadlocks, network timeouts)
- Exponential backoff with configurable jitter
- Connection health checks before retry
- Returns callback result on success

### Read Transactions

Use `read()` for read-only operations - routes to read replicas in cluster:

```php
// Analytics query - uses read replicas
$stats = DB::connection('graph')->read(function ($connection) {
    return [
        'total_users' => User::count(),
        'active_users' => User::where('active', true)->count(),
        'recent_posts' => Post::where('created_at', '>', now()->subDays(7))->count(),
    ];
});
```

**Key Features**:
- Routes to read replicas in Neo4j cluster
- Lower default retry count (reads are safer)
- Better performance for read-heavy workloads
- Prevents accidental writes

### Retry Configuration

Configure retry behavior in `config/database.php`:

```php
'connections' => [
    'neo4j' => [
        'driver' => 'neo4j',
        // ... other settings ...

        // Retry configuration
        'retry' => [
            'max_attempts' => 3,          // Maximum retry attempts (default: 3)
            'initial_delay_ms' => 100,    // First retry delay in ms (default: 100)
            'max_delay_ms' => 5000,       // Maximum delay between retries (default: 5000)
            'multiplier' => 2.0,          // Exponential backoff multiplier (default: 2.0)
            'jitter' => true,             // Add random jitter to prevent thundering herd (default: true)
        ],
    ],
],
```

### Retry Delay Calculation

With default settings, retry delays work as follows:

```
Attempt 1: 100ms (± jitter)
Attempt 2: 200ms (± jitter)  [100ms * 2.0]
Attempt 3: 400ms (± jitter)  [200ms * 2.0]

Formula: min(initial_delay * (multiplier ^ attempt), max_delay)
Jitter: delay * random(0.5, 1.5)
```

### Override Retry Count

Override max retries for specific operations:

```php
// Critical operation - more retries
DB::connection('graph')->write(function ($connection) {
    return $this->processPayment($orderId);
}, $maxRetries = 5);

// One-shot operation - no retries
DB::connection('graph')->write(function ($connection) {
    return $this->sendNotification($userId);
}, $maxRetries = 1);
```

### Idempotency Requirements

**⚠️ Important**: Managed transactions may retry your code. Ensure operations are idempotent:

```php
// Bad: Not idempotent (counter increments multiple times on retry)
DB::connection('graph')->write(function () {
    $user = User::find(1);
    $user->login_count++;  // Will increment 2-3 times on retry!
    $user->save();
});

// Good: Idempotent (same result regardless of retries)
DB::connection('graph')->write(function () {
    User::where('id', 1)->update([
        'last_login' => now(),
        'login_count' => DB::raw('n.login_count + 1'), // Neo4j handles increment
    ]);
});

// Good: Use upsert patterns
DB::connection('graph')->write(function () {
    return User::updateOrCreate(
        ['email' => 'user@example.com'],
        ['name' => 'John Doe', 'last_seen' => now()]
    );
});
```

### Error Handling

Catch specific exceptions to handle different failure scenarios:

```php
use Look\EloquentCypher\Exceptions\Neo4jTransientException;
use Look\EloquentCypher\Exceptions\Neo4jException;

try {
    $result = DB::connection('graph')->write(function () {
        return $this->criticalOperation();
    });

} catch (Neo4jTransientException $e) {
    // Failed after all retries (transient error)
    Log::error('Transaction failed after retries', [
        'attempts' => $e->getAttempts(),
        'last_error' => $e->getMessage(),
    ]);
    throw $e;

} catch (Neo4jException $e) {
    // Non-retryable error (constraint violation, syntax error, etc.)
    Log::error('Permanent transaction error', [
        'message' => $e->getMessage(),
        'hint' => $e->getHint(),
    ]);
    throw $e;
}
```

### Connection Health Checks

Managed transactions include automatic health checks:

```php
// Manual health check
if (!DB::connection('graph')->ping()) {
    Log::warning('Neo4j connection unhealthy');
    DB::connection('graph')->reconnect();
}

// Automatic health check in transactions
DB::connection('graph')->write(function () {
    // Connection automatically checked and reconnected if needed
    return User::create(['name' => 'John']);
});
```

### Comparison with Standard Transactions

**✅ Same API**: Both approaches work, choose based on your needs:

```php
// Standard Laravel transaction (still works!)
DB::transaction(function () {
    User::create(['name' => 'Jane']);
}, $attempts = 5);

// Managed transaction (Neo4j-optimized)
DB::connection('graph')->write(function ($connection) {
    User::create(['name' => 'Jane']);
}, $maxRetries = 3);
```

**Managed transactions add**:
- Exponential backoff (vs linear retry)
- Neo4j-specific error handling
- Connection health checks
- Better error context
- Cluster-aware routing

---

## Indexing and Constraints

Indexes dramatically improve query performance. Always index foreign keys and frequently queried properties.

### Creating Indexes

Use Laravel's schema builder with Neo4j syntax:

```php
use Illuminate\Support\Facades\Schema;

// Create index on users.email
Schema::connection('graph')->label('users', function ($label) {
    $label->index('email');
});

// Create composite index
Schema::connection('graph')->label('posts', function ($label) {
    $label->index(['user_id', 'created_at']);
});

// Create fulltext index (Neo4j 5.0+)
Schema::connection('graph')->label('articles', function ($label) {
    $label->fulltext(['title', 'content'], 'article_search');
});
```

### Creating Constraints

Constraints enforce data integrity and create indexes automatically:

```php
// Unique constraint (also creates index)
Schema::connection('graph')->label('users', function ($label) {
    $label->unique('email');
});

// Node key constraint (composite unique)
Schema::connection('graph')->label('products', function ($label) {
    $label->nodeKey(['sku', 'warehouse_id']);
});

// Existence constraint (property must exist)
Schema::connection('graph')->label('orders', function ($label) {
    $label->required('customer_id');
});
```

### Dropping Indexes and Constraints

```php
// Drop index by name
Schema::connection('graph')->dropIndex('users_email_index');

// Drop constraint by name
Schema::connection('graph')->dropConstraint('users_email_unique');

// Drop all indexes/constraints for a label
Schema::connection('graph')->dropLabel('old_users');
```

### Index Strategy for Relationships

**Foreign Key Mode**: Index foreign key properties on nodes:

```php
// Index foreign keys for fast relationship queries
Schema::connection('graph')->label('posts', function ($label) {
    $label->index('user_id');      // For $user->posts()
    $label->index('category_id');  // For $category->posts()
});

Schema::connection('graph')->label('comments', function ($label) {
    $label->index('post_id');      // For $post->comments()
    $label->index('user_id');      // For $user->comments()
});
```

**Native Edge Mode**: Indexes not needed - traversal uses graph structure:

```php
// No foreign key indexes needed
// Relationship traversal uses native graph edges
$posts = $user->posts()->get(); // Fast without indexes!
```

**Hybrid Mode**: Index foreign keys for flexibility:

```php
// Index foreign keys even though edges exist
// Allows query optimizer to choose best path
Schema::connection('graph')->label('posts', function ($label) {
    $label->index('user_id');
});
```

### When to Create Indexes

**✅ Always index**:
- Primary keys (id)
- Foreign keys (user_id, post_id, etc.)
- Unique identifiers (email, username, sku)
- Frequently used WHERE conditions
- ORDER BY columns
- Timestamp columns (created_at, updated_at)

**⚠️ Consider indexing**:
- Status/type fields used in filters
- Geographic coordinates
- Numeric ranges (price, age)

**❌ Avoid indexing**:
- High-cardinality text (descriptions, content)
- Rarely queried properties
- Properties that change frequently
- Boolean flags (low selectivity)

### Checking Existing Indexes

```php
// Get all indexes for a connection
$indexes = DB::connection('graph')->select('SHOW INDEXES');

// Get indexes for specific label
$userIndexes = collect($indexes)
    ->filter(fn($idx) => in_array('users', $idx->labelsOrTypes ?? []))
    ->all();

// Check if index exists
Schema::connection('graph')->hasIndex('users_email_index');
```

---

## Connection Pooling

Connection pooling reuses database connections for better performance under load.

### Basic Configuration

Enable connection pooling in `config/database.php`:

```php
'connections' => [
    'neo4j' => [
        'driver' => 'neo4j',
        // ... other settings ...

        // Connection pooling
        'pool' => [
            'enabled' => true,        // Enable connection pooling
            'max_connections' => 10,  // Maximum pool size
            'min_connections' => 1,   // Minimum idle connections
            'connection_timeout' => 5000,  // Timeout in ms
        ],
    ],
],
```

### Pool Sizing Guidelines

| Application Type | min_connections | max_connections |
|-----------------|-----------------|-----------------|
| Development | 1 | 5 |
| Small API | 2 | 10 |
| Medium Web App | 5 | 20 |
| Large Web App | 10 | 50 |
| High-Traffic API | 20 | 100 |

### Advanced Pool Settings

```php
'pool' => [
    'enabled' => true,
    'max_connections' => 20,
    'min_connections' => 2,
    'connection_timeout' => 5000,      // Wait 5s for available connection
    'idle_timeout' => 600000,          // Close idle connections after 10min
    'max_lifetime' => 3600000,         // Force refresh after 1 hour
    'validation_query' => 'RETURN 1',  // Test connection health
],
```

### Health Checks

Connection pools include automatic health monitoring:

```php
// Manual health check
if (!DB::connection('graph')->ping()) {
    Log::warning('Connection unhealthy, reconnecting...');
    DB::connection('graph')->reconnect();
}

// Pool automatically validates connections using validation_query
// No manual intervention needed for most cases
```

---

## Query Optimization

General optimization techniques for fast Neo4j queries.

### Eager Loading (N+1 Prevention)

**⚠️ Critical**: Always use eager loading to avoid N+1 query problems:

```php
// Bad: N+1 queries (1 + 10 = 11 queries for 10 users)
$users = User::all();
foreach ($users as $user) {
    echo $user->posts->count(); // Separate query per user!
}

// Good: Eager loading (2 queries total)
$users = User::with('posts')->get();
foreach ($users as $user) {
    echo $user->posts->count(); // Already loaded!
}

// Good: Count without loading (2 queries, less data transfer)
$users = User::withCount('posts')->get();
foreach ($users as $user) {
    echo $user->posts_count; // Computed in database
}
```

**✅ Same as Eloquent**: Use the same eager loading methods (`with`, `load`, `withCount`) you already know.

### Batch Operations Over Loops

Use batch operations instead of loops:

```php
// Bad: 100 queries
foreach ($records as $record) {
    User::create($record);
}

// Good: 1 batch request
User::insert($records);

// Bad: 50 queries
foreach ($userIds as $userId) {
    User::where('id', $userId)->update(['status' => 'active']);
}

// Good: 1 query
User::whereIn('id', $userIds)->update(['status' => 'active']);
```

### Select Only Needed Columns

Reduce data transfer by selecting specific columns:

```php
// Bad: Fetches all columns including large text fields
$users = User::all();

// Good: Only needed columns
$users = User::select(['id', 'name', 'email'])->get();

// Good: With relationships
$users = User::select(['id', 'name'])
    ->with(['posts' => function ($query) {
        $query->select(['id', 'user_id', 'title']);
    }])
    ->get();
```

### Use Indexes Effectively

Ensure your queries use indexes:

```php
// Good: Uses index on email
$user = User::where('email', 'alice@example.com')->first();

// Good: Uses composite index on (user_id, created_at)
$posts = Post::where('user_id', 123)
    ->where('created_at', '>', now()->subDays(7))
    ->get();

// Bad: Function on indexed column prevents index usage
$users = User::whereRaw('LOWER(email) = ?', ['alice@example.com'])->get();

// Good: Use case-insensitive index or constraint instead
$users = User::where('email', 'alice@example.com')->get();
```

### Query Logging for Debugging

Enable query logging to identify slow queries:

```php
// In AppServiceProvider or controller
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

DB::listen(function ($query) {
    if ($query->connectionName === 'graph') {
        Log::debug('Neo4j Query', [
            'cypher' => $query->sql,
            'bindings' => $query->bindings,
            'time' => $query->time . 'ms',
        ]);
    }
});
```

Configure slow query logging in `config/database.php`:

```php
'neo4j' => [
    'log_slow_queries' => true,
    'slow_query_threshold' => 1000, // Log queries slower than 1000ms
],
```

---

## Next Steps

**Continue Learning**:
- [Troubleshooting Guide](troubleshooting.md) - Debug performance issues and errors

**Related Topics**:
- [Models and CRUD](models-and-crud.md) - Basic model operations
- [Querying](querying.md) - Advanced query techniques
- [Neo4j Aggregates](neo4j-aggregates.md) - Optimize analytics with Neo4j aggregate functions
- [Schema Introspection](schema-introspection.md) - Build indexes based on schema analysis
