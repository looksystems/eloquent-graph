# Configuration Guide - Eloquent Cypher v2.0

This guide covers all configuration options for Eloquent Cypher v2.0+ with the driver abstraction layer.

## Table of Contents

- [Quick Start](#quick-start)
- [Database Connection](#database-connection)
- [Driver Selection](#driver-selection)
- [Performance & Optimization](#performance--optimization)
- [Relationship Storage](#relationship-storage)
- [Retry & Error Handling](#retry--error-handling)
- [Connection Pooling](#connection-pooling)
- [Read/Write Splitting](#readwrite-splitting)
- [Migration from v1.x](#migration-from-v1x)

## Quick Start

### 1. Install Package

```bash
composer require looksystems/eloquent-cypher
```

### 2. Register Service Provider

Add to `config/app.php`:

```php
'providers' => [
    Look\EloquentCypher\GraphServiceProvider::class,
],
```

### 3. Add Connection Configuration

Add to `config/database.php`:

```php
'connections' => [
    'graph' => [
        'driver' => 'graph',
        'database_type' => 'neo4j',  // Required: neo4j, future: memgraph, apache-age

        // Connection details
        'host' => env('GRAPH_HOST', 'localhost'),
        'port' => env('GRAPH_PORT', 7687),
        'database' => env('GRAPH_DATABASE', 'neo4j'),
        'username' => env('GRAPH_USERNAME', 'neo4j'),
        'password' => env('GRAPH_PASSWORD', 'password'),
        'protocol' => env('GRAPH_PROTOCOL', 'bolt'),

        // Performance options
        'batch_size' => env('GRAPH_BATCH_SIZE', 100),
        'enable_batch_execution' => env('GRAPH_ENABLE_BATCH_EXECUTION', true),

        // Relationship storage strategy
        'default_relationship_storage' => env('GRAPH_RELATIONSHIP_STORAGE', 'hybrid'),
        'auto_create_edges' => env('GRAPH_AUTO_CREATE_EDGES', true),
        'edge_naming_convention' => env('GRAPH_EDGE_NAMING_CONVENTION', 'snake_case_upper'),

        // Optional features
        'use_apoc_for_json' => env('GRAPH_USE_APOC_FOR_JSON', true),
        'lazy' => env('GRAPH_LAZY_CONNECTION', false),

        // Connection pooling
        'pool' => [
            'enabled' => env('GRAPH_POOL_ENABLED', true),
            'max_connections' => env('GRAPH_POOL_MAX_CONNECTIONS', 10),
            'min_connections' => env('GRAPH_POOL_MIN_CONNECTIONS', 1),
            'acquire_timeout' => env('GRAPH_POOL_ACQUIRE_TIMEOUT', 60000),
        ],

        // Retry configuration
        'retry' => [
            'max_attempts' => env('GRAPH_RETRY_MAX_ATTEMPTS', 3),
            'initial_delay_ms' => env('GRAPH_RETRY_INITIAL_DELAY_MS', 100),
            'max_delay_ms' => env('GRAPH_RETRY_MAX_DELAY_MS', 5000),
            'multiplier' => env('GRAPH_RETRY_MULTIPLIER', 2.0),
            'jitter' => env('GRAPH_RETRY_JITTER', true),
        ],
    ],
],
```

### 4. Configure Environment

Copy `.env.example` or add to your `.env`:

```env
GRAPH_DATABASE_TYPE=neo4j
GRAPH_HOST=localhost
GRAPH_PORT=7687
GRAPH_USERNAME=neo4j
GRAPH_PASSWORD=password
```

## Database Connection

### Connection Parameters

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `driver` | string | **required** | Must be `'graph'` |
| `database_type` | string | **required** | Driver type: `'neo4j'` (future: `'memgraph'`, `'apache-age'`) |
| `host` | string | `'localhost'` | Database host |
| `port` | int | `7687` | Database port (7687 for bolt, 7474 for HTTP) |
| `database` | string | `'neo4j'` | Database name |
| `username` | string | `'neo4j'` | Auth username |
| `password` | string | **required** | Auth password |
| `protocol` | string | `'bolt'` | Protocol: `bolt`, `neo4j`, `bolt+s`, `neo4j+s`, etc. |

### Protocol Options

- **`bolt`**: Default unencrypted protocol
- **`neo4j`**: Routing protocol (Enterprise Edition clusters)
- **`bolt+s`**: Encrypted bolt with full certificate validation
- **`bolt+ssc`**: Encrypted bolt with self-signed certificates
- **`neo4j+s`**: Encrypted routing with full certificate validation
- **`neo4j+ssc`**: Encrypted routing with self-signed certificates

Example encrypted connection:

```php
'graph' => [
    'driver' => 'graph',
    'database_type' => 'neo4j',
    'host' => 'production.neo4j.io',
    'port' => 7687,
    'protocol' => 'bolt+s',
    'username' => env('GRAPH_USERNAME'),
    'password' => env('GRAPH_PASSWORD'),
],
```

## Driver Selection

### Currently Supported Drivers

#### Neo4j Driver (`'neo4j'`)

Full-featured driver for Neo4j 4.x and 5.x (Community and Enterprise editions).

```php
'database_type' => 'neo4j',
```

**Features:**
- ✅ Full Eloquent API compatibility
- ✅ Batch operations
- ✅ Managed transactions
- ✅ Schema introspection
- ✅ Native graph relationships
- ✅ APOC integration (optional)
- ✅ Connection pooling
- ✅ Read/write splitting (Enterprise)

### Future Drivers (Roadmap)

#### Memgraph Driver (Planned)

```php
'database_type' => 'memgraph',  // Coming in v2.1
```

#### Apache AGE Driver (Planned)

```php
'database_type' => 'apache-age',  // Coming in v2.2
```

### Driver Registration

To implement a custom driver:

```php
use Look\EloquentCypher\Drivers\DriverManager;

// Register custom driver
DriverManager::register('custom-graph', CustomGraphDriver::class);

// Use in configuration
'database_type' => 'custom-graph',
```

See driver implementation guide in `docs/DRIVER_IMPLEMENTATION.md` (coming soon).

## Performance & Optimization

### Batch Execution

Execute multiple queries in a single request for 50-70% performance improvement.

```php
'batch_size' => 100,  // Queries per batch
'enable_batch_execution' => true,
```

**Performance Impact:**
- Insert 100 records: 3s → 0.9s (70% faster)
- Upsert 1000 records: 15s → 7.8s (48% faster)
- Schema migrations: 40% faster

**Use Cases:**
- `Model::insert([...])` with multiple records
- `Model::upsert([...])` for bulk upserts
- Mass updates and deletes

**Not Used For:**
- Schema DDL operations (CREATE/DROP INDEX/CONSTRAINT)
- Single record operations
- Complex transactions

### Connection Pooling

Manage database connections efficiently:

```php
'pool' => [
    'enabled' => true,
    'max_connections' => 10,      // Maximum concurrent connections
    'min_connections' => 1,       // Minimum idle connections
    'acquire_timeout' => 60000,   // Timeout in milliseconds
],
```

**Recommendations:**
- **Development**: `max_connections: 5`
- **Production (Single Server)**: `max_connections: 10-20`
- **Production (Load Balanced)**: `max_connections: 5-10` per server

### Lazy Connection

Defer database connection until first query:

```php
'lazy' => true,
```

**Benefits:**
- Faster application boot time
- Reduced connection overhead
- Better for applications with conditional database usage

**When to Enable:**
- Jobs/queues that don't always need database
- API routes with conditional database access
- Testing environments

## Relationship Storage

Configure how Laravel relationships are stored in the graph database.

### Storage Strategies

#### Foreign Key Mode (Legacy)

```php
'default_relationship_storage' => 'foreign_key',
```

**How it works:**
```cypher
// hasMany: User → Post
CREATE (u:users {id: 1})
CREATE (p:posts {id: 1, user_id: 1})  // Foreign key property
```

**Pros:**
- 100% Eloquent compatible
- Fastest for polymorphic relations
- Easy migration from SQL databases

**Cons:**
- Not using native graph capabilities
- No relationship metadata
- Visualization tools see no edges

#### Edge Mode (Native Graph)

```php
'default_relationship_storage' => 'edge',
```

**How it works:**
```cypher
// hasMany: User → Post
CREATE (u:users {id: 1})
CREATE (p:posts {id: 1})
CREATE (u)-[:HAS_POSTS]->(p)  // Native graph edge
```

**Pros:**
- True graph database usage
- Relationship metadata support
- Better for graph visualization
- Enables graph algorithms

**Cons:**
- Polymorphic relations not supported
- Requires data migration from v1.x
- Some Eloquent features limited

#### Hybrid Mode (Recommended)

```php
'default_relationship_storage' => 'hybrid',  // Default
```

**How it works:**
```cypher
// hasMany: User → Post
CREATE (u:users {id: 1})
CREATE (p:posts {id: 1, user_id: 1})  // Foreign key
CREATE (u)-[:HAS_POSTS]->(p)          // AND native edge
```

**Pros:**
- ✅ Best of both worlds
- ✅ 100% Eloquent compatible
- ✅ Native graph traversal available
- ✅ Easy migration path
- ✅ Visualization tools work
- ✅ Supports all relationship types

**Cons:**
- Slightly more storage (both keys and edges)
- Need to keep them in sync (automatic)

### Per-Relationship Configuration

Override global setting per relationship:

```php
use Look\EloquentCypher\GraphModel;
use Look\EloquentCypher\Traits\SupportsNativeEdges;

class User extends GraphModel
{
    use SupportsNativeEdges;

    // Use edges for posts relationship
    public function posts()
    {
        return $this->hasMany(Post::class)
            ->useNativeEdges()
            ->withEdgeType('AUTHORED');
    }

    // Use foreign keys for comments
    public function comments()
    {
        return $this->hasMany(Comment::class)
            ->useForeignKeys();
    }
}
```

### Edge Naming Conventions

```php
'edge_naming_convention' => 'snake_case_upper',  // HAS_POSTS
// 'camel_case',          // HasPosts
// 'pascal_case',         // HasPosts
// 'snake_case',          // has_posts
// 'constant_case',       // HAS_POSTS (same as snake_case_upper)
```

### Auto-Create Edges

Automatically create edges when relationships are established:

```php
'auto_create_edges' => true,
```

When enabled:
```php
$user->posts()->create(['title' => 'Hello']);
// Creates: (user)-[:HAS_POSTS]->(post)
// AND sets: post.user_id = user.id (in hybrid mode)
```

## Retry & Error Handling

Automatic retry for transient errors with exponential backoff.

```php
'retry' => [
    'max_attempts' => 3,           // Maximum retry attempts
    'initial_delay_ms' => 100,     // Initial delay (ms)
    'max_delay_ms' => 5000,        // Maximum delay cap
    'multiplier' => 2.0,           // Exponential multiplier
    'jitter' => true,              // Add randomness to delays
],
```

### Retry Behavior

**Retry Delay Calculation:**
```
delay = min(initial_delay * (multiplier ^ attempt), max_delay)
if (jitter) delay = delay * random(0.8, 1.2)
```

**Example (with jitter disabled):**
- Attempt 1: 100ms
- Attempt 2: 200ms
- Attempt 3: 400ms

### Error Classification

Errors are automatically classified:

1. **Transient** - Automatically retried
   - Connection timeouts
   - Transaction conflicts
   - Temporary network issues

2. **Network** - Triggers reconnection
   - Connection lost
   - Connection reset
   - DNS resolution failure

3. **Authentication** - Not retried
   - Invalid credentials
   - Insufficient permissions

4. **Constraint** - Not retried
   - Unique constraint violations
   - Schema violations

### Usage Example

```php
// Automatic retry on transient errors
DB::connection('graph')->write(function ($connection) {
    User::create(['name' => 'John']);
    Post::create(['title' => 'Hello']);
    return true;
}, $maxRetries = 3);
```

## Connection Pooling

Advanced connection management for production environments.

### Basic Configuration

```php
'pool' => [
    'enabled' => true,
    'max_connections' => 10,
    'min_connections' => 1,
    'acquire_timeout' => 60000,  // 60 seconds
],
```

### Monitoring Pool Status

```php
$connection = DB::connection('graph');
$stats = $connection->getPoolStats();

// Returns:
[
    'total_connections' => 8,
    'active_connections' => 5,
    'peak_connections' => 10,
]
```

### Health Checks

```php
// Check connection health
if (!DB::connection('graph')->ping()) {
    DB::connection('graph')->reconnect();
}
```

## Read/Write Splitting

For Neo4j Enterprise Edition clusters with read replicas.

### Configuration

```php
'graph' => [
    'driver' => 'graph',
    'database_type' => 'neo4j',

    // Primary (write) connection
    'host' => env('GRAPH_WRITE_HOST', 'primary.neo4j.io'),
    'port' => env('GRAPH_WRITE_PORT', 7687),
    'username' => env('GRAPH_WRITE_USERNAME', 'neo4j'),
    'password' => env('GRAPH_WRITE_PASSWORD'),

    // Read replica connections
    'read' => [
        'host' => env('GRAPH_READ_HOST', 'read-replica.neo4j.io'),
        'port' => env('GRAPH_READ_PORT', 7687),
        'username' => env('GRAPH_READ_USERNAME', 'neo4j'),
        'password' => env('GRAPH_READ_PASSWORD'),
    ],

    'read_preference' => env('GRAPH_READ_PREFERENCE', 'nearest'),
    // Options: 'primary', 'secondary', 'nearest'
],
```

### Read Preferences

- **`primary`**: Route all reads to primary
- **`secondary`**: Route reads to replicas only
- **`nearest`**: Route to nearest available node

### Managed Transactions

```php
// Write transaction (routes to primary)
DB::connection('graph')->write(function ($connection) {
    return User::create(['name' => 'John']);
});

// Read transaction (routes to replica)
DB::connection('graph')->read(function ($connection) {
    return User::where('active', true)->get();
});
```

## Migration from v1.x

### Backward Compatibility

v2.0 maintains backward compatibility with v1.x configuration for smooth migration.

### Old Configuration (v1.x)

```php
'neo4j' => [
    'driver' => 'neo4j',
    'host' => env('NEO4J_HOST', 'localhost'),
    'port' => env('NEO4J_PORT', 7687),
    'username' => env('NEO4J_USERNAME', 'neo4j'),
    'password' => env('NEO4J_PASSWORD', 'password'),
],
```

### New Configuration (v2.0+)

```php
'graph' => [
    'driver' => 'graph',
    'database_type' => 'neo4j',  // NEW: specify driver type
    'host' => env('GRAPH_HOST', 'localhost'),
    'port' => env('GRAPH_PORT', 7687),
    'username' => env('GRAPH_USERNAME', 'neo4j'),
    'password' => env('GRAPH_PASSWORD', 'password'),
],
```

### Migration Steps

1. **Update Connection Name** (Recommended):
   ```php
   // Old
   protected $connection = 'neo4j';

   // New
   protected $connection = 'graph';
   ```

2. **Add Database Type**:
   ```php
   'database_type' => 'neo4j',  // Required in v2.0+
   ```

3. **Update Environment Variables**:
   ```env
   # Old
   NEO4J_HOST=localhost
   NEO4J_PORT=7687

   # New (recommended)
   GRAPH_HOST=localhost
   GRAPH_PORT=7687
   GRAPH_DATABASE_TYPE=neo4j
   ```

4. **Update Models**:
   ```php
   // Old
   use Look\EloquentCypher\Neo4JModel;
   class User extends Neo4JModel { }

   // New
   use Look\EloquentCypher\GraphModel;
   class User extends GraphModel { }
   ```

5. **Update Service Provider Registration**:
   ```php
   // Old
   Look\EloquentCypher\Neo4jServiceProvider::class,

   // New
   Look\EloquentCypher\GraphServiceProvider::class,
   ```

### Deprecation Timeline

- **v2.0-v2.9**: Both old and new config supported
- **v3.0**: Old config will be removed

## Advanced Configuration

### Custom Driver Implementation

See `docs/DRIVER_IMPLEMENTATION.md` for creating custom drivers for other graph databases.

### Multiple Connections

```php
'connections' => [
    // Production graph
    'graph' => [
        'driver' => 'graph',
        'database_type' => 'neo4j',
        'host' => env('GRAPH_HOST'),
        // ...
    ],

    // Analytics graph
    'graph_analytics' => [
        'driver' => 'graph',
        'database_type' => 'neo4j',
        'host' => env('GRAPH_ANALYTICS_HOST'),
        // ...
    ],
],
```

Usage:
```php
// Use default graph connection
$users = User::all();

// Use specific connection
$analyticsData = DB::connection('graph_analytics')->select('...');

// Per-model connection
class AnalyticsModel extends GraphModel
{
    protected $connection = 'graph_analytics';
}
```

## Troubleshooting

### Connection Issues

**Problem:** Can't connect to Neo4j

**Solutions:**
1. Verify Neo4j is running: `docker ps` or check logs
2. Check port: Default is 7687, tests use 7688
3. Verify credentials in `.env`
4. Test connection: `DB::connection('graph')->ping()`

### Performance Issues

**Problem:** Slow bulk operations

**Solutions:**
1. Enable batch execution: `'enable_batch_execution' => true`
2. Increase batch size: `'batch_size' => 100`
3. Use managed transactions: `DB::connection('graph')->write()`

### Authentication Errors

**Problem:** Authentication failed

**Solutions:**
1. Reset Neo4j password: See Neo4j documentation
2. Verify environment variables are loaded
3. Check credentials: `config('database.connections.graph')`

## Support

- **Documentation**: [README.md](README.md)
- **Issues**: [GitHub Issues](https://github.com/looksystems/eloquent-cypher/issues)
- **Discussions**: [GitHub Discussions](https://github.com/looksystems/eloquent-cypher/discussions)
