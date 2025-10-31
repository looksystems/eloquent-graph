# Quick Start Guide

**Version 1.2.0** - Now 50-70% faster with automatic batch execution and transaction retry!

## Prerequisites

- PHP 8.0+
- Composer
- Docker and Docker Compose (for Neo4j)
- Laravel 10.x, 11.x, or 12.x application

## 1. Installation

```bash
composer require looksystems/eloquent-cypher
```

## 2. Start Neo4j

### Option A: Using Docker Compose (Recommended)
```bash
# Copy the docker-compose.yml from the package if needed
docker-compose up -d

# Neo4j will be available on:
# - Bolt: localhost:7688
# - Browser: http://localhost:7475
```

### Option B: Using Docker Run
```bash
docker run -d \
  --name neo4j-test \
  -p 7688:7687 \
  -p 7475:7474 \
  -e NEO4J_AUTH=neo4j/password \
  neo4j:5-community
```

Note: You can customize the ports if 7688/7475 are already in use.

## 3. Configure Neo4j Connection

Add to your `.env` file:
```env
NEO4J_HOST=localhost
NEO4J_PORT=7688
NEO4J_USERNAME=neo4j
NEO4J_PASSWORD=password
NEO4J_DATABASE=neo4j
```

Add to `config/database.php`:
```php
'connections' => [
    'neo4j' => [
        'driver' => 'neo4j',
        'host' => env('NEO4J_HOST', 'localhost'),
        'port' => env('NEO4J_PORT', 7688),
        'database' => env('NEO4J_DATABASE', 'neo4j'),
        'username' => env('NEO4J_USERNAME', 'neo4j'),
        'password' => env('NEO4J_PASSWORD', 'password'),

        // Performance Features (v1.2.0) - Optional but recommended
        'batch_size' => 100,                  // Batch size for bulk operations
        'enable_batch_execution' => true,     // 50-70% faster bulk operations
        'retry' => [
            'max_attempts' => 3,              // Automatic retry on transient errors
            'initial_delay_ms' => 100,
            'max_delay_ms' => 5000,
            'multiplier' => 2.0,
            'jitter' => true,
        ],
    ],
],
```

## 4. Create Your First Model

```php
<?php

namespace App\Models;

use Look\EloquentCypher\Neo4JModel;

class User extends Neo4JModel
{
    protected $connection = 'neo4j';
    protected $fillable = ['name', 'email', 'age'];

    public function posts()
    {
        return $this->hasMany(Post::class);
    }
}
```

```php
<?php

namespace App\Models;

use Look\EloquentCypher\Neo4JModel;

class Post extends Neo4JModel
{
    protected $connection = 'neo4j';
    protected $fillable = ['title', 'content', 'user_id'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
```

## 5. Start Using Eloquent

Everything works exactly like standard Eloquent:

```php
// Create
$user = User::create([
    'name' => 'John Doe',
    'email' => 'john@example.com',
    'age' => 30
]);

// Read
$user = User::find($id);
$users = User::where('age', '>', 25)->get();
$young = User::where('age', '<', 30)->orderBy('name')->get();

// Update
$user->update(['name' => 'Jane Doe']);
User::where('age', '<', 18)->update(['status' => 'minor']);

// Delete
$user->delete();
User::where('inactive', true)->delete();

// Relationships
$post = $user->posts()->create([
    'title' => 'My First Post',
    'content' => 'Hello Neo4j!'
]);

$author = $post->user;
$posts = User::with('posts')->find($id);
```

## 6. Performance Features (v1.2.0 - Automatic!)

The following performance enhancements work automatically with **zero code changes**:

```php
// Batch operations are 50-70% faster!
// Before: 100 separate queries
// After: 1 batch request (automatic!)
User::insert([
    ['name' => 'User 1', 'email' => 'user1@example.com'],
    ['name' => 'User 2', 'email' => 'user2@example.com'],
    // ... 98 more records
]); // ✅ Automatically batched - 70% faster!

// Managed transactions with automatic retry (optional upgrade)
use Illuminate\Support\Facades\DB;

DB::connection('neo4j')->write(function ($connection) {
    $user = User::create(['name' => 'John']);
    $user->posts()->create(['title' => 'Post']);
    return $user;
}); // ✅ Auto-retry on deadlocks/timeouts
```

**Performance Improvements**:
- Insert 100 records: **3s → 0.9s** (70% faster)
- Insert 1,000 records: **10s → 4s** (60% faster)
- Upsert operations: **48% faster**
- Schema migrations: **40% faster**
- Transaction success rate: **95% → 99.9%+**

See [docs/MANAGED_TRANSACTIONS.md](docs/MANAGED_TRANSACTIONS.md) for complete guide.

## 7. Neo4j-Specific Features (Optional)

### Schema Introspection

Explore your database structure with artisan commands:

```bash
# Display complete schema overview
php artisan neo4j:schema

# List all node labels
php artisan neo4j:schema:labels --count

# List all relationship types
php artisan neo4j:schema:relationships --count

# List all constraints and indexes
php artisan neo4j:schema:constraints
php artisan neo4j:schema:indexes

# Export schema to file
php artisan neo4j:schema:export schema.json
```

Or programmatically:

```php
use Look\EloquentCypher\Facades\Neo4jSchema;

$labels = Neo4jSchema::getAllLabels();
$relationships = Neo4jSchema::getAllRelationshipTypes();
$schema = Neo4jSchema::introspect();
```

### Hybrid Array Storage

Eloquent Cypher intelligently stores arrays for optimal performance:

```php
// Flat arrays → Native Neo4j LISTs (no APOC needed!)
$user = User::create([
    'skills' => ['PHP', 'JavaScript', 'Go']
]);

// Query works seamlessly
User::whereJsonContains('skills', 'PHP')->get();
User::whereJsonLength('skills', '>', 2)->get();

// Nested structures → JSON strings (APOC enhances when available)
$user = User::create([
    'preferences' => ['theme' => 'dark', 'notifications' => true]
]);

User::whereJsonContains('preferences->theme', 'dark')->get();
```

### Graph Queries

When you need graph database power:

```php
// Raw Cypher queries
$results = DB::connection('neo4j')->cypher(
    'MATCH (u:users {status: $status})-[:POSTED]->(p:posts)
     RETURN u, count(p) as post_count',
    ['status' => 'active']
);

// Graph patterns
$results = User::joinPattern('(u:users), (p:posts)')
    ->where('p.user_id = u.id')
    ->where('p.published', true)
    ->select(['u.name', 'count(p) as total'])
    ->get();
```

## Next Steps

- **Performance Guide**: Read [docs/MANAGED_TRANSACTIONS.md](docs/MANAGED_TRANSACTIONS.md) for optimizations
- **Full Documentation**: Check [DOCUMENTATION.md](DOCUMENTATION.md) for complete feature list
- **Compatibility Matrix**: See [COMPATIBILITY_MATRIX.md](COMPATIBILITY_MATRIX.md) for Laravel API coverage
- **Read the Docs**: Check [README.md](README.md) for overview
- **Contributing**: Read [CONTRIBUTING.md](CONTRIBUTING.md) for development guidelines

## Troubleshooting

### Connection Refused
Make sure Neo4j is running:
```bash
# Using docker-compose
docker-compose up -d

# Or check if container is running
docker ps | grep neo4j

# Check Neo4j logs
docker logs neo4j-test
```

### Class Not Found
Run composer autoload:
```bash
composer dump-autoload
```

### Authentication Failed
Check your Neo4j credentials in `.env` match your Neo4j setup.

## Testing

To run the package test suite:
```bash
# First, copy the PHPUnit configuration
cp phpunit.xml phpunit.xml.dist

# Run all tests (sequentially)
./vendor/bin/pest

# Run with coverage report
./vendor/bin/pest --coverage-text

# Run specific test
./vendor/bin/pest tests/Feature/BasicCrudTest.php
```

**Note:** Tests use port 7688 by default. Ensure your Neo4j instance is running on this port or update the test configuration.

## Need Help?

1. Check the test suite for usage examples
2. Read the comprehensive documentation
3. Open an issue on GitHub