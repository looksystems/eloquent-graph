# Migration Guide: From SQL to Neo4j

Practical, step-by-step guide to migrating Laravel applications from traditional SQL databases (MySQL, PostgreSQL) to Neo4j using Eloquent Cypher.

---

## Migration Strategy

### Overview

Migrating from SQL to Neo4j requires careful planning, but the good news: **Eloquent Cypher maintains 95%+ API compatibility with standard Laravel Eloquent**. Your existing knowledge transfers directly.

**Recommended Approach: Incremental Migration**

Don't migrate everything at once. Instead:

1. **Run both databases in parallel** during transition
2. **Migrate one model at a time**, starting with simple ones
3. **Test thoroughly** after each model conversion
4. **Keep a rollback plan** ready

### Timeline Estimate

For a typical Laravel application:
- **Small app** (5-10 models): 1-2 days
- **Medium app** (20-50 models): 1 week
- **Large app** (100+ models): 2-3 weeks

Most time is spent on testing, not code changes.

### Risk Assessment

**Low Risk (Easy to migrate)**:
- Models with basic CRUD operations
- HasMany/BelongsTo relationships
- Standard Eloquent queries

**Medium Risk (Some adaptation needed)**:
- Complex queries with multiple joins
- BelongsToMany relationships
- Custom database-specific code

**High Risk (Requires redesign)**:
- Heavy use of database triggers
- Complex transactions spanning many tables
- Database-specific functions (stored procedures)

### Before You Start

**Prerequisites**:
- Laravel 10-12 application
- PHP 8.0+
- Docker for Neo4j
- Basic understanding of Eloquent

**Backup Everything**:
```bash
# Backup your database
mysqldump -u root -p your_database > backup.sql

# Commit all changes
git add -A
git commit -m "Pre-migration snapshot"
git tag pre-neo4j-migration
```

---

## Step 1: Setup (Dual Database Mode)

### Install Eloquent Cypher

```bash
composer require looksystems/eloquent-cypher
```

### Start Neo4j with Docker

Create `docker-compose.yml` in your project root:

```yaml
version: '3.8'
services:
  neo4j:
    image: neo4j:5-community
    ports:
      - "7687:7687"
      - "7474:7474"
    environment:
      NEO4J_AUTH: neo4j/password
      NEO4JLABS_PLUGINS: '["apoc"]'
    volumes:
      - neo4j_data:/data

volumes:
  neo4j_data:
```

Start Neo4j:
```bash
docker-compose up -d
```

Verify Neo4j is running:
- Open http://localhost:7474
- Login with neo4j/password
- You should see the Neo4j Browser

### Configure Both Connections

Update `.env`:
```env
# Existing SQL connection (keep as-is)
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=your_database
DB_USERNAME=root
DB_PASSWORD=secret

# New Neo4j connection
NEO4J_HOST=localhost
NEO4J_PORT=7687
NEO4J_USERNAME=neo4j
NEO4J_PASSWORD=password
NEO4J_DATABASE=neo4j
```

Update `config/database.php`:
```php
'connections' => [
    // Keep your existing MySQL/PostgreSQL connection
    'mysql' => [
        'driver' => 'mysql',
        // ... existing config
    ],

    // Add Neo4j connection
    'neo4j' => [
        'driver' => 'neo4j',
        'host' => env('NEO4J_HOST', 'localhost'),
        'port' => env('NEO4J_PORT', 7687),
        'database' => env('NEO4J_DATABASE', 'neo4j'),
        'username' => env('NEO4J_USERNAME', 'neo4j'),
        'password' => env('NEO4J_PASSWORD', 'password'),

        // Performance optimizations (recommended)
        'batch_size' => 100,
        'enable_batch_execution' => true,
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

### Test Both Connections

Create a test command:
```bash
php artisan make:command TestConnections
```

```php
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class TestConnections extends Command
{
    protected $signature = 'test:connections';
    protected $description = 'Test both database connections';

    public function handle()
    {
        // Test MySQL
        try {
            DB::connection('mysql')->getPdo();
            $this->info('✓ MySQL connection works');
        } catch (\Exception $e) {
            $this->error('✗ MySQL connection failed: ' . $e->getMessage());
        }

        // Test Neo4j
        try {
            DB::connection('neo4j')->statement('RETURN 1');
            $this->info('✓ Neo4j connection works');
        } catch (\Exception $e) {
            $this->error('✗ Neo4j connection failed: ' . $e->getMessage());
        }
    }
}
```

Run the test:
```bash
php artisan test:connections
```

You should see:
```
✓ MySQL connection works
✓ Neo4j connection works
```

---

## Step 2: Convert Your First Model

Start with a simple model with no relationships. This builds confidence and establishes your migration pattern.

### Example: Converting the User Model

**Before (SQL/Eloquent):**
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class User extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'email', 'age'];
    protected $hidden = ['password'];
    protected $casts = [
        'email_verified_at' => 'datetime',
        'age' => 'integer',
    ];
}
```

**After (Neo4j/Eloquent Cypher):**
```php
<?php

namespace App\Models;

use Look\EloquentCypher\Neo4JModel;
use Look\EloquentCypher\Concerns\Neo4jSoftDeletes;

class User extends Neo4JModel
{
    use Neo4jSoftDeletes;  // Changed from SoftDeletes

    protected $connection = 'neo4j';  // New
    public $incrementing = false;     // New

    protected $fillable = ['name', 'email', 'age'];
    protected $hidden = ['password'];
    protected $casts = [
        'email_verified_at' => 'datetime',
        'age' => 'integer',
    ];
}
```

### Key Changes

**✅ Same as Eloquent:**
- `$fillable`, `$guarded`, `$hidden` - Work identically
- `$casts` - All cast types supported (string, int, bool, array, json, datetime, etc.)
- `$dates` - Timestamps work the same way
- `$appends`, `$with` - No changes needed

**⚠️ Different from Eloquent:**

| Change | Why | What to Do |
|--------|-----|-----------|
| Base class: `Neo4JModel` instead of `Model` | Provides Neo4j-specific functionality | Change `extends Model` to `extends Neo4JModel` |
| `protected $connection = 'neo4j'` | Routes queries to Neo4j | Add this property |
| `public $incrementing = false` | Neo4j doesn't auto-increment IDs | Add this property (uses uniqid() instead) |
| `Neo4jSoftDeletes` instead of `SoftDeletes` | Neo4j-specific soft delete implementation | Change trait import |

### Testing Your Converted Model

Create a test to verify the model works:

```php
// In tinker or a test
php artisan tinker

// Create a user
$user = User::create([
    'name' => 'John Doe',
    'email' => 'john@example.com',
    'age' => 30
]);

// Find the user
$found = User::find($user->id);
echo $found->name; // "John Doe"

// Update the user
$user->update(['age' => 31]);

// Delete the user (soft delete)
$user->delete();

// Verify soft delete
$trashed = User::withTrashed()->find($user->id);
echo $trashed->deleted_at; // Shows timestamp

// Restore the user
$trashed->restore();

// Force delete
$user->forceDelete();
```

All these operations work **identically** to standard Eloquent.

---

## Step 3: Migrate Relationships

Relationships are where Neo4j really shines, but they require the most attention during migration.

### Strategy: Start with Foreign Key Mode

**Foreign key mode** stores relationships as properties (like SQL), ensuring 100% compatibility:

```cypher
// Foreign key mode (default)
(user {id: 1}), (post {user_id: 1})
```

Later, you can optionally migrate to **native edges** for graph benefits:

```cypher
// Native edge mode (optional, later)
(user {id: 1})-[:CREATED]->(post)
```

### HasMany & BelongsTo (Most Common)

**Before (SQL):**
```php
class User extends Model
{
    public function posts()
    {
        return $this->hasMany(Post::class);
    }
}

class Post extends Model
{
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
```

**After (Neo4j):**
```php
use Look\EloquentCypher\Neo4JModel;

class User extends Neo4JModel
{
    protected $connection = 'neo4j';
    public $incrementing = false;

    public function posts()
    {
        return $this->hasMany(Post::class);  // Identical!
    }
}

class Post extends Neo4JModel
{
    protected $connection = 'neo4j';
    public $incrementing = false;

    protected $fillable = ['user_id', 'title', 'body'];  // Keep user_id

    public function user()
    {
        return $this->belongsTo(User::class);  // Identical!
    }
}
```

**Testing:**
```php
// Create user and posts
$user = User::create(['name' => 'Jane']);
$post = $user->posts()->create(['title' => 'Hello', 'body' => 'World']);

// Query relationships
$user->posts; // Collection of posts
$post->user; // The user

// Eager loading (prevents N+1)
$users = User::with('posts')->get();

// Relationship queries
$users = User::has('posts')->get();
$users = User::whereHas('posts', function($q) {
    $q->where('title', 'like', '%Laravel%');
})->get();
```

All work **identically** to standard Eloquent.

### HasOne (One-to-One)

**Before (SQL):**
```php
class User extends Model
{
    public function profile()
    {
        return $this->hasOne(Profile::class);
    }
}

class Profile extends Model
{
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
```

**After (Neo4j):**
```php
use Look\EloquentCypher\Neo4JModel;

class User extends Neo4JModel
{
    protected $connection = 'neo4j';
    public $incrementing = false;

    public function profile()
    {
        return $this->hasOne(Profile::class);  // Identical!
    }
}

class Profile extends Neo4JModel
{
    protected $connection = 'neo4j';
    public $incrementing = false;

    protected $fillable = ['user_id', 'bio', 'avatar'];

    public function user()
    {
        return $this->belongsTo(User::class);  // Identical!
    }
}
```

**Testing:**
```php
$user = User::create(['name' => 'Jane']);
$profile = $user->profile()->create(['bio' => 'Software developer']);

$user->profile; // The profile
$profile->user; // The user

// Eager loading
$users = User::with('profile')->get();
```

### BelongsToMany (Many-to-Many)

**Before (SQL):**
```php
class User extends Model
{
    public function roles()
    {
        return $this->belongsToMany(Role::class)
            ->withPivot('expires_at')
            ->withTimestamps();
    }
}

class Role extends Model
{
    public function users()
    {
        return $this->belongsToMany(User::class);
    }
}
```

**After (Neo4j):**
```php
use Look\EloquentCypher\Neo4JModel;

class User extends Neo4JModel
{
    protected $connection = 'neo4j';
    public $incrementing = false;

    public function roles()
    {
        return $this->belongsToMany(Role::class)
            ->withPivot('expires_at')  // Identical!
            ->withTimestamps();        // Identical!
    }
}

class Role extends Neo4JModel
{
    protected $connection = 'neo4j';
    public $incrementing = false;

    public function users()
    {
        return $this->belongsToMany(User::class);  // Identical!
    }
}
```

**Testing:**
```php
$user = User::create(['name' => 'Jane']);
$role = Role::create(['name' => 'Admin']);

// Attach (add relationship)
$user->roles()->attach($role->id, ['expires_at' => now()->addYear()]);

// Detach (remove relationship)
$user->roles()->detach($role->id);

// Sync (replace all relationships)
$user->roles()->sync([1, 2, 3]);

// Access pivot data
$user->roles->each(function($role) {
    echo $role->pivot->expires_at;
});

// Eager loading with pivot
$users = User::with('roles')->get();
```

### HasManyThrough (Nested Relationships)

**Before (SQL):**
```php
class Country extends Model
{
    public function posts()
    {
        return $this->hasManyThrough(
            Post::class,
            User::class
        );
    }
}
```

**After (Neo4j):**
```php
use Look\EloquentCypher\Neo4JModel;

class Country extends Neo4JModel
{
    protected $connection = 'neo4j';
    public $incrementing = false;

    public function posts()
    {
        return $this->hasManyThrough(
            Post::class,
            User::class  // Identical!
        );
    }
}
```

**Testing:**
```php
$country = Country::create(['name' => 'USA']);
$user = $country->users()->create(['name' => 'Jane']);
$post = $user->posts()->create(['title' => 'Hello']);

// Get all posts in a country (through users)
$posts = $country->posts; // Collection of posts

// Eager loading
$countries = Country::with('posts')->get();
```

### Migration Testing Checklist

For each relationship, verify:

- [ ] Basic relationship access (`$user->posts`)
- [ ] Inverse relationship (`$post->user`)
- [ ] Eager loading (`User::with('posts')->get()`)
- [ ] Lazy eager loading (`$user->load('posts')`)
- [ ] Relationship queries (`User::has('posts')->get()`)
- [ ] Relationship constraints (`whereHas('posts', fn($q) => ...)`)
- [ ] Count queries (`User::withCount('posts')->get()`)
- [ ] Pivot operations (for BelongsToMany: attach, detach, sync)

---

## Step 4: Migrate Data

Once your models are converted, migrate the actual data from SQL to Neo4j.

### Small Dataset (< 10,000 records)

Use a simple Laravel command:

```php
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User; // Neo4j model
use DB;

class MigrateUsers extends Command
{
    protected $signature = 'migrate:users';

    public function handle()
    {
        $this->info('Migrating users from MySQL to Neo4j...');

        // Fetch from MySQL
        $sqlUsers = DB::connection('mysql')
            ->table('users')
            ->get();

        $bar = $this->output->createProgressBar(count($sqlUsers));

        foreach ($sqlUsers as $sqlUser) {
            // Create in Neo4j
            User::create([
                'id' => $sqlUser->id,  // Preserve IDs
                'name' => $sqlUser->name,
                'email' => $sqlUser->email,
                'age' => $sqlUser->age,
                'created_at' => $sqlUser->created_at,
                'updated_at' => $sqlUser->updated_at,
            ]);

            $bar->advance();
        }

        $bar->finish();
        $this->info("\n✓ Migrated {$sqlUsers->count()} users");
    }
}
```

Run:
```bash
php artisan migrate:users
```

### Large Dataset (> 10,000 records)

Use batch insertion for better performance:

```php
use Illuminate\Support\Facades\DB;

public function handle()
{
    $this->info('Migrating users (batch mode)...');

    DB::connection('mysql')
        ->table('users')
        ->orderBy('id')
        ->chunk(1000, function($users) {
            $batch = $users->map(fn($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'age' => $u->age,
                'created_at' => $u->created_at,
                'updated_at' => $u->updated_at,
            ])->toArray();

            // Batch insert (50-70% faster with batch execution enabled)
            User::insert($batch);

            $this->info("Migrated batch of {$users->count()} users");
        });

    $this->info('✓ Migration complete');
}
```

### Migrate Relationships

After migrating nodes, migrate relationships:

```php
public function handle()
{
    $this->info('Migrating user-post relationships...');

    // Fetch SQL relationships
    $sqlPosts = DB::connection('mysql')
        ->table('posts')
        ->select('id', 'user_id')
        ->get();

    foreach ($sqlPosts as $sqlPost) {
        // Update Neo4j post with user_id (foreign key mode)
        DB::connection('neo4j')->statement(
            'MATCH (p:posts {id: $post_id}) SET p.user_id = $user_id',
            ['post_id' => $sqlPost->id, 'user_id' => $sqlPost->user_id]
        );
    }

    $this->info('✓ Relationships migrated');
}
```

### Verification

After migration, verify data integrity:

```php
php artisan tinker

// Count records
$sqlCount = DB::connection('mysql')->table('users')->count();
$neo4jCount = User::count();
echo "SQL: $sqlCount, Neo4j: $neo4jCount";

// Sample verification
$sqlUser = DB::connection('mysql')->table('users')->first();
$neo4jUser = User::find($sqlUser->id);
var_dump($sqlUser, $neo4jUser->toArray());

// Relationship verification
$sqlPostCount = DB::connection('mysql')
    ->table('posts')
    ->where('user_id', $sqlUser->id)
    ->count();
$neo4jPostCount = $neo4jUser->posts()->count();
echo "Posts - SQL: $sqlPostCount, Neo4j: $neo4jPostCount";
```

---

## Step 5: Common Gotchas & Solutions

### 1. Primary Keys & Auto-Increment

**Problem:** Neo4j doesn't auto-increment IDs like SQL.

**Solution:**
```php
// Always set in your Neo4j models
public $incrementing = false;
protected $keyType = 'int'; // or 'string'
```

The package automatically generates unique IDs using `uniqid()`. If you need to preserve SQL IDs during migration, explicitly set them:

```php
User::create(['id' => 123, 'name' => 'Jane']);
```

### 2. Operator Differences

**Problem:** Some SQL operators don't work in Neo4j.

**Solution:** The package automatically maps operators:

| SQL Operator | Neo4j Equivalent | Auto-Converted? |
|-------------|------------------|-----------------|
| `!=` | `<>` | ✅ Yes |
| `IS NULL` | `IS NULL` | ✅ Yes |
| `IS NOT NULL` | `IS NOT NULL` | ✅ Yes |
| `LIKE` | `CONTAINS` / `=~` | ✅ Yes |

Your code stays the same:
```php
// This works identically
User::where('age', '!=', 30)->get();
User::whereNull('email')->get();
User::where('name', 'like', '%John%')->get();
```

### 3. SelectRaw Requires Prefix

**Problem:** Raw selects need the `n.` prefix for Neo4j.

**SQL (Before):**
```php
User::selectRaw('COUNT(*) as total')->first();
```

**Neo4j (After):**
```php
User::selectRaw('COUNT(n) as total')->first();  // Use 'n' alias
```

**Why?** Neo4j uses node aliases in Cypher queries. The default alias is `n`.

### 4. JSON Path Updates

**Problem:** Nested JSON path updates have limitations.

**Workaround:** Update the entire parent property:

```php
// ❌ Doesn't work reliably
$user->update(['settings->theme' => 'dark']);

// ✅ Works perfectly
$settings = $user->settings;
$settings['theme'] = 'dark';
$user->update(['settings' => $settings]);
```

### 5. Transaction Differences

**Problem:** Neo4j transactions work differently than SQL.

**Solution:** Use managed transactions for reliability:

```php
use Illuminate\Support\Facades\DB;

// Standard transactions (work but no auto-retry)
DB::connection('neo4j')->transaction(function() {
    User::create(['name' => 'Jane']);
    Post::create(['title' => 'Hello']);
});

// Managed transactions (recommended - auto-retry on transient errors)
DB::connection('neo4j')->write(function($connection) {
    User::create(['name' => 'Jane']);
    Post::create(['title' => 'Hello']);
}, $maxRetries = 3);
```

### 6. Soft Deletes Trait

**Problem:** Standard `SoftDeletes` trait doesn't work.

**Solution:** Use `Neo4jSoftDeletes` instead:

```php
// ❌ Don't use
use Illuminate\Database\Eloquent\SoftDeletes;

// ✅ Use this
use Look\EloquentCypher\Concerns\Neo4jSoftDeletes;

class User extends Neo4JModel
{
    use Neo4jSoftDeletes;  // Neo4j-specific
}
```

Everything else works identically:
```php
$user->delete();              // Soft delete
$user->restore();             // Restore
$user->forceDelete();         // Permanent delete
User::withTrashed()->get();   // Include soft-deleted
User::onlyTrashed()->get();   // Only soft-deleted
```

---

## Migration Checklist

Use this checklist to track your migration progress:

### Pre-Migration
- [ ] Backup SQL database
- [ ] Commit all code changes
- [ ] Create git tag for rollback point
- [ ] Install Eloquent Cypher package
- [ ] Start Neo4j with Docker
- [ ] Configure both database connections
- [ ] Test both connections work

### Per-Model Migration
- [ ] Change base class to `Neo4JModel`
- [ ] Add `protected $connection = 'neo4j'`
- [ ] Add `public $incrementing = false`
- [ ] Change `SoftDeletes` to `Neo4jSoftDeletes` (if used)
- [ ] Test basic CRUD (create, read, update, delete)
- [ ] Test soft deletes (if applicable)
- [ ] Migrate all relationships
- [ ] Test each relationship type
- [ ] Test eager loading
- [ ] Test relationship queries
- [ ] Migrate data from SQL to Neo4j
- [ ] Verify data integrity
- [ ] Run full application test suite

### Post-Migration
- [ ] Performance testing
- [ ] Load testing
- [ ] Monitor Neo4j memory usage
- [ ] Document any Neo4j-specific patterns used
- [ ] Update team documentation
- [ ] Plan rollback procedure
- [ ] Schedule monitoring checkpoints

---

## Next Steps

### Optimize Performance
See [performance.md](performance.md) for:
- Batch operations (50-70% faster bulk inserts)
- Managed transactions with auto-retry
- Index creation strategies
- Connection pooling configuration
- Query optimization techniques

### Troubleshoot Issues
See [troubleshooting.md](troubleshooting.md) for:
- Common error messages and solutions
- Connection issues
- Query debugging techniques
- Performance bottlenecks
- Getting help

### Advanced Features
Once your basic migration is complete, explore:
- **Native graph edges** for relationship performance
- **Multi-label nodes** for flexible categorization
- **Cypher DSL** for complex graph queries
- **Neo4j aggregates** for advanced analytics

---

## Migration Support

**Need Help?**
- Review [getting-started.md](getting-started.md) for setup details
- Check [models-and-crud.md](models-and-crud.md) for model patterns
- See [relationships.md](relationships.md) for relationship examples
- Browse [troubleshooting.md](troubleshooting.md) for common issues

**Found a Bug?**
- Check existing GitHub issues
- Open a new issue with reproduction steps
- Include Laravel version, Neo4j version, and error messages

**Migration went smoothly?**
Consider contributing back:
- Share your migration story
- Document any gotchas you discovered
- Help others in the community

Happy migrating!
