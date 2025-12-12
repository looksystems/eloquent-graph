# Getting Started with Eloquent Cypher

Welcome to Eloquent Cypher! This guide will get you up and running with Neo4j in your Laravel application.

---

## Quick Start (5 minutes)

Already familiar with Neo4j and Laravel? Here's the condensed version:

```bash
# 1. Install
composer require looksystems/eloquent-cypher

# 2. Start Neo4j
docker run -d --name neo4j-test -p 7688:7687 -p 7475:7474 \
  -e NEO4J_AUTH=neo4j/password neo4j:5-community
```

```env
# 3. Add to .env
GRAPH_DATABASE_TYPE=neo4j
GRAPH_HOST=localhost
GRAPH_PORT=7688
GRAPH_USERNAME=neo4j
GRAPH_PASSWORD=password
GRAPH_DATABASE=neo4j
```

```php
// 4. Add to config/database.php connections array
'graph' => [
    'driver' => 'graph',
    'database_type' => env('GRAPH_DATABASE_TYPE', 'neo4j'),
    'host' => env('GRAPH_HOST', 'localhost'),
    'port' => env('GRAPH_PORT', 7688),
    'database' => env('GRAPH_DATABASE', 'neo4j'),
    'username' => env('GRAPH_USERNAME', 'neo4j'),
    'password' => env('GRAPH_PASSWORD', 'password'),
    'batch_size' => 100,
    'enable_batch_execution' => true,
],

// 5. Create a model
class User extends \Look\EloquentCypher\GraphModel
{
    protected $connection = 'graph';
    protected $fillable = ['name', 'email'];
}

// 6. Use it like Eloquent
User::create(['name' => 'John', 'email' => 'john@example.com']);
User::where('name', 'John')->first();
```

Need more details? Continue reading below.

---

## Prerequisites

Before you begin, make sure you have:

**Required:**
- PHP 8.0 or higher
- Composer installed
- Laravel 10.x, 11.x, or 12.x application

**Recommended:**
- Docker and Docker Compose (for Neo4j)
- Basic familiarity with Laravel Eloquent

**Time Required:** 10 minutes

---

## Installation

Install the package via Composer:

```bash
composer require looksystems/eloquent-cypher
```

Register the service provider in `config/app.php`:

```php
'providers' => [
    // Other Service Providers
    Look\EloquentCypher\GraphServiceProvider::class,
],
```

That's it! The package is installed.

---

## Neo4j Setup

You need a running Neo4j instance. We recommend Docker for quick setup.

### Option A: Docker Compose (Recommended)

Create a `docker-compose.yml` file in your project root (or use the one included with the package):

```yaml
services:
  neo4j:
    image: neo4j:5-community
    container_name: neo4j-test
    ports:
      - "7688:7687"  # Bolt protocol
      - "7475:7474"  # Browser UI
    environment:
      - NEO4J_AUTH=neo4j/password
      - NEO4J_dbms_default__database=neo4j
      - NEO4J_dbms_memory_pagecache_size=512M
      - NEO4J_dbms_memory_heap_initial__size=512M
      - NEO4J_dbms_memory_heap_max__size=1G
      - NEO4JLABS_PLUGINS=["apoc"]
      - NEO4J_dbms_security_procedures_unrestricted=apoc.*
      - NEO4J_dbms_security_procedures_allowlist=apoc.*
    volumes:
      - neo4j-data:/data
      - neo4j-logs:/logs

volumes:
  neo4j-data:
  neo4j-logs:
```

Start Neo4j:

```bash
docker-compose up -d

# Or use the composer script
composer neo4j:up
```

**Port Notes:**
- Bolt: `7688` (custom port to avoid conflicts with default 7687)
- Browser: `7475` (custom port to avoid conflicts with default 7474)
- Customize ports if these are already in use

### Option B: Docker Run Command

Quick one-liner to start Neo4j:

```bash
docker run -d \
  --name neo4j-test \
  -p 7688:7687 \
  -p 7475:7474 \
  -e NEO4J_AUTH=neo4j/password \
  -e NEO4JLABS_PLUGINS='["apoc"]' \
  neo4j:5-community
```

### Option C: Local Installation

Download from [neo4j.com/download](https://neo4j.com/download) and follow platform-specific instructions.

**Change default ports to 7688/7475** to match this guide.

### Verify Neo4j is Running

**Check Docker container:**
```bash
docker ps | grep neo4j
```

**Access Neo4j Browser:**

Open [http://localhost:7475](http://localhost:7475)

- Username: `neo4j`
- Password: `password`

**Run a test query:**
```cypher
RETURN 'Neo4j is running!' as message
```

You should see the message returned.

---

## Configuration

### Step 1: Update .env

Add your graph database connection details to `.env`:

```env
GRAPH_DATABASE_TYPE=neo4j
GRAPH_HOST=localhost
GRAPH_PORT=7688
GRAPH_USERNAME=neo4j
GRAPH_PASSWORD=password
GRAPH_DATABASE=neo4j
```

### Step 2: Configure Database Connection

Add the `graph` connection to `config/database.php` in the `connections` array:

```php
'connections' => [

    // Your existing connections (mysql, pgsql, etc.)

    'graph' => [
        'driver' => 'graph',
        'database_type' => env('GRAPH_DATABASE_TYPE', 'neo4j'),
        'host' => env('GRAPH_HOST', 'localhost'),
        'port' => env('GRAPH_PORT', 7688),
        'database' => env('GRAPH_DATABASE', 'neo4j'),
        'username' => env('GRAPH_USERNAME', 'neo4j'),
        'password' => env('GRAPH_PASSWORD', 'password'),

        // Performance Settings (Optional - Recommended)
        'batch_size' => 100,
        'enable_batch_execution' => true,  // 50-70% faster bulk operations!

        // Transaction Retry (Optional - Recommended)
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

**Configuration Options Explained:**

- `driver`: Must be `'graph'`
- `database_type`: The graph database driver (e.g., `'neo4j'`)
- `host`: Neo4j server address
- `port`: Bolt protocol port (default: 7688 in this guide)
- `database`: Database name (usually `'neo4j'`)
- `username`: Neo4j username
- `password`: Neo4j password
- `batch_size`: Records per batch for bulk operations (default: 100)
- `enable_batch_execution`: Auto-batch insert/upsert operations (recommended: `true`)
- `retry`: Automatic retry config for transient errors

**Performance Note:**
Enabling batch execution makes bulk operations **50-70% faster**:
- Insert 100 records: **3s → 0.9s** (70% faster)
- Insert 1,000 records: **10s → 4s** (60% faster)

---

## Your First Model

Time to create your first Neo4j model! The only change from standard Eloquent is the base class.

### Standard Eloquent Model (MySQL/PostgreSQL)

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    protected $fillable = ['name', 'email', 'age'];

    public function posts()
    {
        return $this->hasMany(Post::class);
    }
}
```

### Eloquent Cypher Model (Neo4j)

```php
<?php

namespace App\Models;

use Look\EloquentCypher\GraphModel;

class User extends GraphModel
{
    protected $connection = 'graph';
    protected $fillable = ['name', 'email', 'age'];

    public function posts()
    {
        return $this->hasMany(Post::class);
    }
}
```

**What Changed:**
1. Base class: `Model` → `GraphModel`
2. Added: `protected $connection = 'graph';`

**What Stayed the Same:**
Everything else! Properties, relationships, methods all work identically.

### Create a Related Model

```php
<?php

namespace App\Models;

use Look\EloquentCypher\GraphModel;

class Post extends GraphModel
{
    protected $connection = 'graph';
    protected $fillable = ['title', 'content', 'user_id'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
```

**Same pattern:** Just change the base class and set the connection.

---

## First Queries

Let's test your setup with some basic operations.

### Create

```php
use App\Models\User;

// Create a single user
$user = User::create([
    'name' => 'John Doe',
    'email' => 'john@example.com',
    'age' => 30
]);

// Create with save()
$user = new User();
$user->name = 'Jane Smith';
$user->email = 'jane@example.com';
$user->age = 28;
$user->save();
```

### Read

```php
// Find by ID
$user = User::find(1);

// Find or fail
$user = User::findOrFail(1);

// Get all users
$users = User::all();

// Where queries
$adults = User::where('age', '>=', 18)->get();
$young = User::where('age', '<', 30)->orderBy('name')->get();

// Multiple conditions
$users = User::where('age', '>', 25)
    ->where('email', 'like', '%@example.com')
    ->get();
```

### Update

```php
// Update single record
$user = User::find(1);
$user->update(['name' => 'Jane Doe']);

// Update multiple records
User::where('age', '<', 18)->update(['status' => 'minor']);

// Increment/Decrement
$user->increment('age');
$user->decrement('credits', 5);
```

### Delete

```php
// Delete single record
$user = User::find(1);
$user->delete();

// Delete multiple records
User::where('inactive', true)->delete();

// Delete by ID
User::destroy(1);
User::destroy([1, 2, 3]);
```

### Relationships

```php
// Create related record
$user = User::find(1);
$post = $user->posts()->create([
    'title' => 'My First Post',
    'content' => 'Hello Neo4j!'
]);

// Get related records
$posts = $user->posts;
$author = $post->user;

// Eager loading
$users = User::with('posts')->get();
$user = User::with('posts')->find(1);

// Constrained eager loading
$users = User::with(['posts' => function ($query) {
    $query->where('published', true)->orderBy('created_at', 'desc');
}])->get();
```

---

## Verifying Your Setup

Run this test to confirm everything works:

```php
use App\Models\User;
use App\Models\Post;

// Create a user
$user = User::create([
    'name' => 'Test User',
    'email' => 'test@example.com',
    'age' => 25
]);

// Create a post for the user
$post = $user->posts()->create([
    'title' => 'Hello World',
    'content' => 'This is my first Neo4j post!'
]);

// Retrieve with relationship
$user = User::with('posts')->find($user->id);

echo "User: {$user->name}\n";
echo "Posts: {$user->posts->count()}\n";
echo "First post: {$user->posts->first()->title}\n";

// Clean up
$user->delete();
```

**Expected Output:**
```
User: Test User
Posts: 1
First post: Hello World
```

If you see this, congratulations! You're up and running.

---

## Important Notes

### Same as Eloquent

These work identically to standard Eloquent:

- All CRUD operations
- All relationship types (hasMany, belongsTo, belongsToMany, etc.)
- Timestamps (`created_at`, `updated_at`)
- Mass assignment (`$fillable`, `$guarded`)
- Attribute casting (`$casts`)
- Query builder methods
- Eager loading
- Scopes
- Events and observers

### Different from Eloquent

Minor differences to be aware of:

**Primary Keys:**
```php
// Neo4j uses integer IDs by default, but auto-increment is handled differently
// You typically don't need to change this, but if you have issues:
protected $incrementing = false;
protected $keyType = 'int';
```

**Soft Deletes:**
```php
// Use the graph-specific trait
use Look\EloquentCypher\Concerns\GraphSoftDeletes;

class User extends GraphModel
{
    use GraphSoftDeletes;

    protected $connection = 'graph';
}

// Usage is identical to Laravel's SoftDeletes
$user->delete();        // Soft delete
$user->forceDelete();   // Permanent delete
$user->restore();       // Restore soft deleted
User::withTrashed()->get();  // Include soft deleted
```

**Raw Queries:**
```php
// Use selectRaw with n. prefix for Neo4j node reference
$users = User::selectRaw('n.age * 2 as double_age')->get();

// For complex Cypher queries
use Illuminate\Support\Facades\DB;

$results = DB::connection('graph')->cypher(
    'MATCH (u:users)-[:POSTED]->(p:posts) WHERE u.age > $age RETURN u, p LIMIT 10',
    ['age' => 25]
);
```

---

## Troubleshooting

### Connection Refused

**Problem:** Can't connect to Neo4j

**Solutions:**
```bash
# Check if Neo4j is running
docker ps | grep neo4j

# Start Neo4j if it's not running
docker-compose up -d

# Check Neo4j logs
docker logs neo4j-test

# Verify port in .env matches docker-compose.yml (7688)
```

### Authentication Failed

**Problem:** Wrong credentials

**Solutions:**
- Check `.env` credentials match Docker config
- Default: username `neo4j`, password `password`
- Reset Neo4j password if needed via Browser UI

### Class Not Found

**Problem:** `GraphModel` class not found

**Solutions:**
```bash
# Regenerate autoload files
composer dump-autoload

# Verify package is installed
composer show looksystems/eloquent-cypher

# Check service provider is registered in config/app.php
```

### Queries Not Working

**Problem:** Eloquent queries fail

**Solutions:**
- Verify Neo4j is running (see "Connection Refused" above)
- Check connection name matches in model: `protected $connection = 'graph';`
- Test connection in tinker:
```php
php artisan tinker
DB::connection('graph')->getPdo();  // Should not throw error
```

---

## Next Steps

You're now ready to build with Eloquent Cypher! Here's what to explore next:

**Learn More:**
- [Models and CRUD](models-and-crud.md) - Deep dive into model features
- [Relationships](relationships.md) - Master all relationship types
- [Querying](querying.md) - Advanced query builder techniques
- [Neo4j Features Overview](neo4j-overview.md) - Graph-specific capabilities overview
- [Cypher DSL](cypher-dsl.md) - Graph traversal and pattern matching

**Performance:**
- [Performance Guide](performance.md) - Optimize your queries and operations

**Reference:**
- [Quick Reference](quick-reference.md) - Cheat sheets and tables
- [Troubleshooting](troubleshooting.md) - Common issues and solutions

---

## Testing

To run the package test suite:

```bash
# Run all tests (sequentially)
./vendor/bin/pest

# Run with coverage report
./vendor/bin/pest --coverage-text

# Run specific test
./vendor/bin/pest tests/Feature/BasicCrudTest.php
```

**Note:** Tests use port 7688 by default.

---

## Need Help?

1. Check the test suite for usage examples
2. Read the comprehensive documentation
3. Open an issue on GitHub

---

**Ready to explore?** Head to [Models and CRUD](models-and-crud.md) to learn about all the model features!
