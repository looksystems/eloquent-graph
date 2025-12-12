# Troubleshooting Guide

Common issues and solutions when working with Eloquent Cypher.

## Quick Diagnosis

```php
// Check connection health
DB::connection('graph')->ping(); // Returns true if healthy

// Debug generated queries
$query = User::where('email', 'test@example.com');
dd($query->toCypher()); // See raw Cypher query

// Enable query logging
DB::connection('graph')->enableQueryLog();
User::all();
dd(DB::connection('graph')->getQueryLog());
```

---

## 1. Connection Issues

### "Connection Refused"

**Problem**: Cannot connect to Neo4j database.

```
Neo4jConnectionException: Connection refused
```

**Solutions**:

✅ **Verify Neo4j is running**:

```bash
# Docker
docker ps | grep neo4j

# If not running, start it
docker run -d \
  --name neo4j \
  -p 7474:7474 -p 7687:7687 \
  -e NEO4J_AUTH=neo4j/password \
  neo4j:latest
```

✅ **Check connection settings** in `.env`:

```env
NEO4J_HOST=127.0.0.1
NEO4J_PORT=7687
NEO4J_USERNAME=neo4j
NEO4J_PASSWORD=password
```

✅ **Test connection**:

```php
use Illuminate\Support\Facades\DB;

// Health check
if (DB::connection('graph')->ping()) {
    echo "Connection successful!";
} else {
    echo "Connection failed!";
}
```

⚠️ **Port conflicts**: Neo4j uses ports 7474 (HTTP) and 7687 (Bolt). Ensure they're not in use.

---

### "Authentication Failed"

**Problem**: Wrong credentials or user permissions.

```
Neo4jAuthenticationException: The client is unauthorized due to authentication failure
```

**Solutions**:

✅ **Verify credentials**:

```bash
# Neo4j Browser: http://localhost:7474
# Default: neo4j/neo4j (must change on first login)
```

✅ **Update `.env`**:

```env
NEO4J_USERNAME=neo4j
NEO4J_PASSWORD=your_actual_password
```

✅ **Clear config cache**:

```bash
php artisan config:clear
php artisan cache:clear
```

⚠️ **First-time setup**: Neo4j requires password change on first login. Access Neo4j Browser and change it.

---

### "Connection Timeout"

**Problem**: Network issues or connection pool exhaustion.

```
Neo4jNetworkException: Connection timeout after 30 seconds
```

**Solutions**:

✅ **Adjust timeout settings** in `config/database.php`:

```php
'neo4j' => [
    'driver' => 'neo4j',
    'host' => env('NEO4J_HOST', '127.0.0.1'),
    'port' => env('NEO4J_PORT', 7687),

    // Connection pool settings
    'connection_timeout' => 30,
    'max_connection_lifetime' => 3600,
    'max_connection_pool_size' => 50,
    'connection_acquisition_timeout' => 60,
],
```

✅ **Check network connectivity**:

```bash
# Test Neo4j port
telnet localhost 7687

# Or use nc
nc -zv localhost 7687
```

⚠️ **Docker networking**: If using Docker, ensure containers are on the same network.

---

## 2. Query Issues

### "Operator Not Supported"

**Problem**: Using SQL operators that don't exist in Cypher.

```php
// ❌ WRONG: != doesn't exist in Cypher
User::where('status', '!=', 'active')->get();
```

**Solutions**:

✅ **Use Cypher equivalents** (automatic conversion):

```php
// ✅ CORRECT: Automatically converted to <>
User::where('status', '!=', 'active')->get();
// Generated Cypher: WHERE n.status <> 'active'

// ✅ Manual Cypher operator
User::where('status', '<>', 'active')->get();
```

**Operator mapping**:

| SQL Operator | Cypher Operator | Auto-Converted |
|--------------|----------------|----------------|
| `!=`         | `<>`           | ✅ Yes         |
| `LIKE`       | `CONTAINS`     | ✅ Yes         |
| `=`          | `=`            | Same           |
| `>`          | `>`            | Same           |
| `<`          | `<`            | Same           |
| `>=`         | `>=`           | Same           |
| `<=`         | `<=`           | Same           |

---

### "Property Not Found" with selectRaw

**Problem**: Missing node prefix in raw selects.

```php
// ❌ WRONG: No 'n.' prefix
User::selectRaw('email, name')->get();
// Error: Variable `email` not defined
```

**Solutions**:

✅ **Always use `n.` prefix** for node properties:

```php
// ✅ CORRECT: Prefix with 'n.'
User::selectRaw('n.email, n.name')->get();

// ✅ CORRECT: With functions
User::selectRaw('n.email, UPPER(n.name) as name')->get();

// ✅ CORRECT: With aliases
User::selectRaw('n.email as user_email, n.name')->get();
```

⚠️ **Why**: In Cypher, `n` is the node variable. You must explicitly reference properties on the node.

---

### "Invalid Parameter Type"

**Problem**: Passing unsupported data types to Cypher.

```php
// ❌ WRONG: Object not serialized
User::where('settings', new Settings())->get();
```

**Solutions**:

✅ **Use supported types**:

```php
// ✅ CORRECT: JSON for complex data
User::where('settings', json_encode(['theme' => 'dark']))->get();

// ✅ CORRECT: Arrays for lists
User::whereIn('role', ['admin', 'editor'])->get();

// ✅ CORRECT: Use model casts
class User extends GraphModel
{
    protected $casts = [
        'settings' => 'array',  // Auto JSON encode/decode
        'metadata' => 'json',
        'is_active' => 'boolean',
    ];
}
```

**Supported types**:
- String, Integer, Float, Boolean
- Arrays (flat or nested)
- JSON objects
- Date/DateTime
- Null

---

### "Query Syntax Error"

**Problem**: Invalid Cypher syntax in raw queries.

```php
// ❌ WRONG: SQL syntax
DB::connection('graph')->select('SELECT * FROM users');
```

**Solutions**:

✅ **Use Eloquent methods** (preferred):

```php
// ✅ CORRECT: Eloquent query builder
User::where('active', true)->get();
```

✅ **Use proper Cypher syntax** for raw queries:

```php
// ✅ CORRECT: Valid Cypher
DB::connection('graph')->select('MATCH (n:users) RETURN n');

// ✅ CORRECT: Parameterized queries
DB::connection('graph')->select(
    'MATCH (n:users) WHERE n.email = $email RETURN n',
    ['email' => 'test@example.com']
);
```

⚠️ **Common SQL→Cypher mistakes**:

| SQL Syntax | Cypher Syntax |
|------------|---------------|
| `SELECT *` | `RETURN n`    |
| `FROM users` | `MATCH (n:users)` |
| `JOIN` | `MATCH (a)-[r]->(b)` |
| `INSERT INTO` | `CREATE` |
| `UPDATE SET` | `SET` |
| `DELETE` | `DELETE` or `DETACH DELETE` |

---

## 3. Relationship Issues

### "Foreign Key Not Found"

**Problem**: Missing index on foreign key property.

```php
class User extends GraphModel
{
    public function posts()
    {
        return $this->hasMany(Post::class);
    }
}

// Slow query or timeout
$user->posts; // WHERE n.user_id = 1
```

**Solutions**:

✅ **Create index on foreign key**:

```php
// In migration or Schema builder
Schema::connection('graph')->table('posts', function ($table) {
    $table->index('user_id'); // Critical for performance!
});

// Or via raw Cypher
DB::connection('graph')->statement(
    'CREATE INDEX post_user_id IF NOT EXISTS FOR (n:posts) ON (n.user_id)'
);
```

✅ **Use native edges** (alternative):

```php
class User extends GraphModel
{
    public function posts()
    {
        return $this->hasMany(Post::class)
            ->useNativeEdges(); // Uses graph relationships
    }
}
```

⚠️ **Always index foreign keys** for acceptable performance, or use native edges for graph traversal benefits.

---

### "Pivot Table Not Working"

**Problem**: BelongsToMany pivot operations failing.

```php
$user->roles()->attach($role->id);
// Error: No relationship created
```

**Solutions**:

✅ **Ensure pivot table exists** (foreign key mode):

```php
class User extends GraphModel
{
    public function roles()
    {
        return $this->belongsToMany(Role::class, 'role_user')
            ->withTimestamps();
    }
}

// Create pivot records
Schema::connection('graph')->create('role_user', function ($table) {
    $table->id();
    $table->unsignedBigInteger('user_id')->index();
    $table->unsignedBigInteger('role_id')->index();
    $table->timestamps();
});
```

✅ **Or use native edges**:

```php
class User extends GraphModel
{
    public function roles()
    {
        return $this->belongsToMany(Role::class)
            ->useNativeEdges('HAS_ROLE') // Creates graph relationship
            ->withPivot('assigned_at'); // Edge properties
    }
}

// Attach with pivot data
$user->roles()->attach($role->id, ['assigned_at' => now()]);
```

⚠️ **Choose storage mode** early: Foreign keys (familiar, indexed) vs Native edges (graph traversal).

---

### "Eager Loading Not Working"

**Problem**: N+1 queries or relationship not loaded.

```php
// ❌ WRONG: N+1 problem
$users = User::all(); // 1 query
foreach ($users as $user) {
    echo $user->posts->count(); // N queries
}
```

**Solutions**:

✅ **Use eager loading**:

```php
// ✅ CORRECT: 2 queries total
$users = User::with('posts')->get();
foreach ($users as $user) {
    echo $user->posts->count(); // Already loaded
}

// ✅ Nested eager loading
$users = User::with('posts.comments')->get();

// ✅ Constrained eager loading
$users = User::with(['posts' => function ($query) {
    $query->where('published', true);
}])->get();
```

✅ **Check relationship definition**:

```php
class User extends GraphModel
{
    public function posts()
    {
        // Ensure foreign key and owner key are correct
        return $this->hasMany(Post::class, 'user_id', 'id');
    }
}
```

---

## 4. Performance Issues

### "Queries Running Slow"

**Problem**: Unindexed queries or missing optimizations.

**Solutions**:

✅ **Add indexes** to frequently queried properties:

```php
// In migration
Schema::connection('graph')->table('users', function ($table) {
    $table->index('email');
    $table->index('status');
    $table->unique('username');
});

// Check existing indexes
php artisan neo4j:schema:indexes
```

✅ **Use batch operations**:

```php
// ❌ SLOW: Individual inserts
foreach ($data as $item) {
    User::create($item); // N queries
}

// ✅ FAST: Batch insert (50-70% faster)
User::insert($data); // 1 query with batching
```

✅ **Optimize eager loading**:

```php
// ❌ SLOW: N+1 problem
User::all()->load('posts');

// ✅ FAST: Eager load upfront
User::with('posts')->get();
```

✅ **Limit result sets**:

```php
// ✅ Use pagination
User::paginate(50);

// ✅ Or explicit limits
User::limit(100)->get();
```

---

### "Transaction Timeouts"

**Problem**: Long-running transactions timing out.

```php
// ❌ WRONG: Large operation in single transaction
DB::transaction(function () {
    foreach ($items as $item) {
        // Process 10,000 items...
    }
});
```

**Solutions**:

✅ **Use managed transactions** with automatic retry:

```php
// ✅ CORRECT: Automatic retry on transient errors
DB::connection('graph')->write(function ($tx) {
    User::create(['name' => 'John']);
}, [
    'max_retry_time' => 30,     // 30 seconds max retry
    'initial_delay' => 1000,    // 1 second initial delay
    'multiplier' => 2.0,        // Exponential backoff
    'jitter_factor' => 0.2,     // Random jitter
]);
```

✅ **Break into smaller transactions**:

```php
// ✅ Process in chunks
$items->chunk(100, function ($chunk) {
    DB::connection('graph')->write(function () use ($chunk) {
        User::insert($chunk->toArray());
    });
});
```

✅ **Adjust timeout settings**:

```php
// config/database.php
'neo4j' => [
    'transaction_timeout' => 300, // 5 minutes
    'connection_timeout' => 60,
],
```

---

### "Memory Exhaustion"

**Problem**: Loading too much data into memory.

```php
// ❌ WRONG: Load all users
$users = User::all(); // Loads everything
```

**Solutions**:

✅ **Use chunking**:

```php
// ✅ CORRECT: Process in batches
User::orderBy('id')->chunk(100, function ($users) {
    foreach ($users as $user) {
        // Process user
    }
});
```

✅ **Use cursor** for large datasets:

```php
// ✅ Memory-efficient iteration
foreach (User::cursor() as $user) {
    // Process one at a time
}
```

✅ **Select only needed columns**:

```php
// ❌ WRONG: Load all properties
User::all();

// ✅ CORRECT: Select specific columns
User::select(['id', 'email', 'name'])->get();
```

---

## 5. Debugging Techniques

### Enable Query Logging

```php
// Enable logging
DB::connection('graph')->enableQueryLog();

// Run queries
User::where('email', 'test@example.com')->first();

// View logged queries
$queries = DB::connection('graph')->getQueryLog();
dd($queries);

/* Output:
[
    [
        'query' => 'MATCH (n:users) WHERE n.email = $p0 RETURN n LIMIT 1',
        'bindings' => ['test@example.com'],
        'time' => 2.45
    ]
]
*/
```

---

### Inspect Raw Cypher

```php
// Get Cypher without executing
$query = User::where('status', 'active')
    ->where('age', '>', 18)
    ->orderBy('created_at', 'desc');

// See the generated Cypher
dd($query->toCypher());

/* Output:
MATCH (n:users)
WHERE n.status = $p0 AND n.age > $p1
RETURN n
ORDER BY n.created_at DESC
*/
```

---

### Use dd() and dump()

```php
// Dump and die
User::where('email', 'test@example.com')->dd();

// Just dump (continue execution)
User::all()->dump();

// Dump with Cypher query
$query = User::where('active', true);
dump($query->toCypher());
$results = $query->get();
```

---

### Neo4j Browser Inspection

Access Neo4j Browser at `http://localhost:7474`:

```cypher
// View all labels
CALL db.labels()

// View all relationship types
CALL db.relationshipTypes()

// Count nodes by label
MATCH (n:users) RETURN count(n)

// Inspect node properties
MATCH (n:users) RETURN n LIMIT 10

// View indexes
SHOW INDEXES

// View constraints
SHOW CONSTRAINTS
```

---

### Exception Details

All exceptions include helpful context:

```php
try {
    User::create(['email' => 'existing@example.com']);
} catch (\Look\EloquentCypher\Exceptions\Neo4jConstraintException $e) {
    echo $e->getMessage();          // Error message
    echo $e->getDetailedMessage();  // Full details
    echo $e->getCypher();           // Query that failed
    echo $e->getMigrationHint();    // SQL→Cypher hint

    // Check exception type
    if ($e instanceof Neo4jTransientException) {
        // Retry logic
    }
}
```

**Exception hierarchy**:

- `Neo4jException` (base)
  - `Neo4jConnectionException` - Connection issues
    - `Neo4jAuthenticationException` - Auth failures
    - `Neo4jNetworkException` - Network errors
  - `Neo4jTransactionException` - Transaction failures
    - `Neo4jTransientException` - Retryable errors
  - `Neo4jQueryException` - Query syntax/execution
  - `Neo4jConstraintException` - Constraint violations

---

## 6. Getting Help

### Check Test Suite

The package includes comprehensive tests showing correct usage:

```bash
# View relationship examples
tests/Feature/RelationshipTest.php

# View query examples
tests/Feature/QueryBuilderMethodsTest.php

# View Cypher DSL examples
tests/Feature/CypherDslIntegrationTest.php

# View error handling
tests/Feature/ErrorRecoveryTest.php
```

---

### Enable Debug Mode

```env
# .env
APP_DEBUG=true
LOG_LEVEL=debug
```

View logs in `storage/logs/laravel.log`.

---

### Common Checks Checklist

✅ Neo4j is running (`docker ps`)
✅ Correct credentials in `.env`
✅ Connection successful (`DB::connection('graph')->ping()`)
✅ Indexes on foreign keys
✅ Proper node prefix (`n.`) in `selectRaw()`
✅ Using Cypher operators (`<>` not `!=`)
✅ Relationship definitions correct (foreign key/owner key)
✅ Eager loading for N+1 prevention
✅ Batch operations for bulk inserts
✅ Config cache cleared (`php artisan config:clear`)

---

### GitHub Issues

If stuck, search or create an issue:

**Repository**: https://github.com/looksystems/eloquent-cypher

**Include**:
- Laravel version
- PHP version
- Neo4j version
- Steps to reproduce
- Generated Cypher (`toCypher()`)
- Full error message

---

### Documentation References

For deeper understanding:

- **Connection setup**: `docs/getting-started.md`
- **Model usage**: `docs/models-and-crud.md`
- **Relationships**: `docs/relationships.md`
- **Query building**: `docs/querying.md`
- **Performance tips**: `docs/performance.md`
- **Quick reference**: `docs/quick-reference.md`

---

## Next Steps

**Still having issues?**
- Review `docs/getting-started.md` for setup verification
- Check `docs/performance.md` for optimization tips
- See `docs/quick-reference.md` for common patterns

**Ready to optimize?**
- Explore `docs/neo4j-overview.md` for Neo4j-specific features overview
- Master graph traversal with `docs/cypher-dsl.md`
- Learn about batch operations in `docs/performance.md`
- Understand graph patterns in `docs/relationships.md`
