# Managed Transactions Guide - Eloquent Cypher v1.2.0

## Table of Contents
- [Overview](#overview)
- [Quick Start](#quick-start)
- [Transaction Methods](#transaction-methods)
- [Configuration](#configuration)
- [Best Practices](#best-practices)
- [Migration Guide](#migration-guide)
- [Advanced Topics](#advanced-topics)
- [Troubleshooting](#troubleshooting)

## Overview

Eloquent Cypher v1.2.0 introduces **managed transactions** - Neo4j-optimized transaction methods that provide automatic retry logic with exponential backoff. These are **optional enhancements** that complement Laravel's existing transaction support.

### Key Benefits

✅ **Automatic retry** on transient errors (deadlocks, network issues)
✅ **Exponential backoff** with configurable jitter
✅ **Connection health checks** and automatic reconnection
✅ **Better error context** with query and parameter information
✅ **Cluster-aware** routing (read vs write nodes)
✅ **100% backward compatible** - existing code continues to work

## Quick Start

### Basic Usage

```php
use Illuminate\Support\Facades\DB;

// Write transaction with automatic retry
$result = DB::connection('neo4j')->write(function ($connection) {
    $user = User::create(['name' => 'John Doe']);
    $post = $user->posts()->create(['title' => 'My First Post']);
    return $post->id;
});

// Read-only transaction (routes to read replicas in cluster)
$users = DB::connection('neo4j')->read(function ($connection) {
    return User::where('active', true)->with('posts')->get();
});
```

### Comparison with Standard Laravel Transactions

```php
// Standard Laravel transaction (still works!)
DB::transaction(function () {
    User::create(['name' => 'Jane']);
}, $attempts = 5);

// NEW: Neo4j-optimized managed transaction
DB::connection('neo4j')->write(function ($connection) {
    User::create(['name' => 'Jane']);
}, $maxRetries = 3);
```

## Transaction Methods

### write() Method

For operations that modify data (CREATE, UPDATE, DELETE, MERGE):

```php
/**
 * Execute a write transaction with automatic retry
 *
 * @param callable $callback The transaction logic
 * @param int|null $maxRetries Override default retry count (optional)
 * @return mixed The callback's return value
 */
$result = DB::connection('neo4j')->write(function ($connection) {
    // Your write operations here
    $user = User::create(['name' => 'Alice']);
    $user->posts()->create(['title' => 'Hello World']);

    // Return value is passed through
    return $user->id;
}, $maxRetries = 3);
```

#### Features:
- Automatic retry on transient errors
- Routes to write nodes in cluster setup
- Enforces idempotency best practices
- Returns callback result on success

### read() Method

For read-only operations (MATCH, WHERE, WITH):

```php
/**
 * Execute a read-only transaction with automatic retry
 *
 * @param callable $callback The transaction logic
 * @param int|null $maxRetries Override default retry count (optional)
 * @return mixed The callback's return value
 */
$data = DB::connection('neo4j')->read(function ($connection) {
    // Read-only operations
    $users = User::where('status', 'active')->get();
    $stats = User::count();

    return compact('users', 'stats');
}, $maxRetries = 2);
```

#### Features:
- Routes to read replicas in cluster
- Lower default retry count (reads are safer)
- Prevents accidental writes in read context
- Better performance for read-heavy workloads

## Configuration

### Database Configuration

Add retry settings to your `config/database.php`:

```php
'connections' => [
    'neo4j' => [
        'driver' => 'neo4j',
        'host' => env('NEO4J_HOST', 'localhost'),
        'port' => env('NEO4J_PORT', 7687),
        // ... other settings ...

        // Batch execution (NEW in v1.2.0)
        'batch_size' => 100,
        'enable_batch_execution' => true,

        // Retry configuration (NEW in v1.2.0)
        'retry' => [
            'max_attempts' => 3,          // Default retry attempts
            'initial_delay_ms' => 100,    // First retry delay
            'max_delay_ms' => 5000,       // Maximum delay between retries
            'multiplier' => 2.0,          // Exponential backoff multiplier
            'jitter' => true,             // Add random jitter to prevent thundering herd
        ],
    ],
],
```

### Configuration Options Explained

| Option | Default | Description |
|--------|---------|-------------|
| `max_attempts` | 3 | Maximum number of retry attempts for transient errors |
| `initial_delay_ms` | 100 | Delay before first retry (milliseconds) |
| `max_delay_ms` | 5000 | Maximum delay between retries (milliseconds) |
| `multiplier` | 2.0 | Multiplier for exponential backoff (2.0 = double each time) |
| `jitter` | true | Add random variation to retry delays |

### Retry Delay Calculation

```
delay = min(initial_delay * (multiplier ^ attempt), max_delay)
if jitter enabled:
    delay = delay * random(0.5, 1.5)
```

Example with defaults:
- Attempt 1: 100ms (± jitter)
- Attempt 2: 200ms (± jitter)
- Attempt 3: 400ms (± jitter)

## Best Practices

### 1. Idempotency Requirements

Managed transactions may retry your code multiple times. Ensure your operations are **idempotent**:

```php
// ❌ BAD: Not idempotent (counter increments multiple times on retry)
DB::connection('neo4j')->write(function () {
    $user = User::find(1);
    $user->login_count++;  // Will increment multiple times!
    $user->save();
});

// ✅ GOOD: Idempotent (same result regardless of retries)
DB::connection('neo4j')->write(function () {
    User::where('id', 1)->update([
        'last_login' => now(),
        'login_count' => DB::raw('n.login_count + 1')
    ]);
});

// ✅ GOOD: Use unique constraints for idempotency
DB::connection('neo4j')->write(function () {
    return User::firstOrCreate(
        ['email' => 'user@example.com'],
        ['name' => 'John Doe']
    );
});
```

### 2. Choosing Between write() and read()

```php
// Use write() for any data modification
DB::connection('neo4j')->write(function () {
    $post = Post::create(['title' => 'New Post']);
    $post->tags()->attach([1, 2, 3]);
});

// Use read() for queries only
$analytics = DB::connection('neo4j')->read(function () {
    return [
        'total_users' => User::count(),
        'active_users' => User::where('active', true)->count(),
        'recent_posts' => Post::where('created_at', '>', now()->subDays(7))->count(),
    ];
});

// Mixing reads and writes? Use write()
DB::connection('neo4j')->write(function () {
    $user = User::find(1);  // Read
    if ($user->shouldUpgrade()) {
        $user->upgrade();    // Write
    }
    return $user;
});
```

### 3. Return Values and Error Handling

```php
try {
    $postId = DB::connection('neo4j')->write(function () {
        $post = Post::create(['title' => 'My Post']);

        // Return values are passed through
        return $post->id;
    });

    echo "Created post: $postId";

} catch (Neo4jTransientException $e) {
    // Failed after all retries
    Log::error('Transaction failed after retries', [
        'query' => $e->getQuery(),
        'parameters' => $e->getParameters(),
        'attempts' => $e->getAttempts(),
    ]);

} catch (Neo4jException $e) {
    // Non-retryable error (syntax, constraints, etc.)
    Log::error('Permanent transaction error', [
        'message' => $e->getMessage(),
        'hint' => $e->getHint(),
    ]);
}
```

### 4. Long-Running Transactions

For operations that take significant time:

```php
// Increase retry attempts for long operations
DB::connection('neo4j')->write(function () {
    // Import large dataset
    foreach ($chunks as $chunk) {
        User::insert($chunk);
    }
}, $maxRetries = 5);

// Or disable retry for non-idempotent bulk operations
DB::connection('neo4j')->write(function () {
    // Complex migration that shouldn't retry
    $this->complexDataMigration();
}, $maxRetries = 1);
```

## Migration Guide

### From Standard Laravel Transactions

If you're currently using Laravel's standard transaction method:

```php
// Before (Laravel standard - still works!)
DB::transaction(function () {
    $user = User::create(['name' => 'John']);
    $user->posts()->create(['title' => 'Post']);
}, 5); // 5 attempts

// After (Neo4j-optimized - optional upgrade)
DB::connection('neo4j')->write(function ($connection) {
    $user = User::create(['name' => 'John']);
    $user->posts()->create(['title' => 'Post']);
}, 3); // 3 attempts with exponential backoff
```

**Key Differences:**
- `write()` has exponential backoff (standard has linear retry)
- `write()` better handles Neo4j-specific errors
- `write()` includes connection health checks
- `write()` provides better error context

### From Manual Retry Logic

If you have custom retry logic:

```php
// Before (manual retry)
$attempts = 0;
$maxAttempts = 3;
$result = null;

while ($attempts < $maxAttempts) {
    try {
        DB::beginTransaction();
        $result = $this->performOperation();
        DB::commit();
        break;
    } catch (\Exception $e) {
        DB::rollBack();
        $attempts++;

        if ($attempts >= $maxAttempts) {
            throw $e;
        }

        sleep(pow(2, $attempts)); // Exponential backoff
    }
}

// After (managed transaction)
$result = DB::connection('neo4j')->write(function () {
    return $this->performOperation();
}, 3);
```

### Gradual Migration Strategy

1. **Phase 1**: Identify critical paths
   ```php
   // Start with high-value transactions
   // - Payment processing
   // - User registration
   // - Order placement
   ```

2. **Phase 2**: Add managed transactions alongside existing code
   ```php
   // Feature flag approach
   if (config('features.use_managed_transactions')) {
       return DB::connection('neo4j')->write(function () {
           return $this->createOrder();
       });
   } else {
       return DB::transaction(function () {
           return $this->createOrder();
       });
   }
   ```

3. **Phase 3**: Monitor and adjust
   ```php
   // Log retry metrics
   DB::connection('neo4j')->write(function () {
       $start = microtime(true);
       $result = $this->performOperation();
       $duration = microtime(true) - $start;

       Log::info('Transaction completed', [
           'duration' => $duration,
           'operation' => 'performOperation'
       ]);

       return $result;
   });
   ```

4. **Phase 4**: Full migration
   ```php
   // Replace all critical transactions
   // Keep standard transactions for simple operations
   ```

## Advanced Topics

### Custom Retry Logic

Override retry behavior for specific operations:

```php
class TransactionManager
{
    public function executeWithCustomRetry(callable $callback, array $options = [])
    {
        $maxRetries = $options['max_retries'] ?? 3;
        $retryOn = $options['retry_on'] ?? [Neo4jTransientException::class];
        $onRetry = $options['on_retry'] ?? null;

        $attempts = 0;

        while ($attempts < $maxRetries) {
            try {
                return DB::connection('neo4j')->write($callback);
            } catch (\Exception $e) {
                $attempts++;

                $shouldRetry = false;
                foreach ($retryOn as $retryableClass) {
                    if ($e instanceof $retryableClass) {
                        $shouldRetry = true;
                        break;
                    }
                }

                if (!$shouldRetry || $attempts >= $maxRetries) {
                    throw $e;
                }

                if ($onRetry) {
                    $onRetry($e, $attempts);
                }

                $delay = $this->calculateBackoff($attempts, $options);
                usleep($delay * 1000);
            }
        }
    }

    private function calculateBackoff(int $attempt, array $options): int
    {
        $initial = $options['initial_delay'] ?? 100;
        $multiplier = $options['multiplier'] ?? 2.0;
        $maxDelay = $options['max_delay'] ?? 5000;

        $delay = min($initial * pow($multiplier, $attempt - 1), $maxDelay);

        if ($options['jitter'] ?? true) {
            $delay = $delay * (0.5 + (mt_rand() / mt_getrandmax()));
        }

        return (int) $delay;
    }
}
```

### Connection Health Monitoring

```php
// Check connection health
if (!DB::connection('neo4j')->ping()) {
    Log::warning('Neo4j connection unhealthy, reconnecting...');
    DB::connection('neo4j')->reconnect();
}

// Automatic health check in transactions
DB::connection('neo4j')->write(function () {
    // Connection automatically checked and reconnected if needed
    return User::create(['name' => 'John']);
});
```

### Cluster Deployments

For Neo4j cluster deployments:

```php
// Write operations route to leader
DB::connection('neo4j')->write(function () {
    // Automatically routed to write leader
    User::create(['name' => 'Alice']);
});

// Read operations can use followers
DB::connection('neo4j')->read(function () {
    // Automatically routed to read replicas
    return User::where('active', true)->get();
});

// Ensure read-after-write consistency
$userId = DB::connection('neo4j')->write(function () {
    $user = User::create(['name' => 'Bob']);
    return $user->id;
});

// Force read from leader for consistency
$user = DB::connection('neo4j')->write(function () use ($userId) {
    return User::find($userId); // Guaranteed to see the write
});
```

## Troubleshooting

### Common Issues

#### 1. "Transaction failed after retries"

**Cause**: Persistent transient errors (deadlocks, high contention)

**Solution**:
```php
// Increase retry attempts
DB::connection('neo4j')->write($callback, $maxRetries = 5);

// Or reduce contention
User::whereIn('id', $userIds)
    ->orderBy('id') // Consistent ordering reduces deadlocks
    ->lockForUpdate()
    ->get();
```

#### 2. "Operation is not idempotent"

**Cause**: Operation produces different results on retry

**Solution**:
```php
// Use upsert patterns
User::updateOrCreate(
    ['email' => $email],
    ['name' => $name, 'updated_at' => now()]
);

// Or use unique transaction IDs
$txId = Str::uuid();
if (!TransactionLog::where('tx_id', $txId)->exists()) {
    // Perform operation
    TransactionLog::create(['tx_id' => $txId]);
}
```

#### 3. "Connection lost during transaction"

**Cause**: Network issues or Neo4j restart

**Solution**:
```php
// Automatic reconnection handles this
DB::connection('neo4j')->write(function () {
    // Connection checked and re-established if needed
    return User::all();
});

// For critical operations, add logging
DB::connection('neo4j')->write(function () {
    Log::info('Starting critical operation');
    $result = $this->criticalOperation();
    Log::info('Critical operation completed');
    return $result;
}, $maxRetries = 5);
```

### Debug Mode

Enable debug logging for transactions:

```php
// In AppServiceProvider
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

public function boot()
{
    if (config('app.debug')) {
        DB::listen(function ($query) {
            if ($query->connectionName === 'neo4j') {
                Log::debug('Neo4j Query', [
                    'cypher' => $query->sql,
                    'bindings' => $query->bindings,
                    'time' => $query->time,
                ]);
            }
        });
    }
}
```

### Performance Monitoring

Track transaction performance:

```php
class TransactionMetrics
{
    public static function measure(string $name, callable $transaction)
    {
        $start = microtime(true);
        $attempts = 0;

        try {
            $result = DB::connection('neo4j')->write(function () use ($transaction, &$attempts) {
                $attempts++;
                return $transaction();
            });

            $duration = microtime(true) - $start;

            Log::info("Transaction completed: $name", [
                'duration_ms' => $duration * 1000,
                'attempts' => $attempts,
                'success' => true,
            ]);

            return $result;

        } catch (\Exception $e) {
            $duration = microtime(true) - $start;

            Log::error("Transaction failed: $name", [
                'duration_ms' => $duration * 1000,
                'attempts' => $attempts,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}

// Usage
$user = TransactionMetrics::measure('create_user', function () {
    return User::create(['name' => 'Alice']);
});
```

## Summary

Managed transactions in Eloquent Cypher v1.2.0 provide:

1. **Automatic retry** with exponential backoff
2. **Better error handling** with context and hints
3. **Connection health management**
4. **Cluster-aware routing**
5. **100% backward compatibility**

Start with critical paths, ensure idempotency, and gradually migrate for improved reliability and performance. The standard Laravel transaction methods continue to work, so adoption can be incremental based on your needs.

For more information, see:
- [README.md](../README.md) - General usage and examples
- [LIMITATIONS.md](../LIMITATIONS.md) - Known limitations and workarounds
- [QUICK_WINS_PLAN.md](../QUICK_WINS_PLAN.md) - Implementation details