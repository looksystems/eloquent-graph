# Querying Data

This guide covers everything you need to query Neo4j data using Eloquent Cypher's query builder. If you're familiar with Laravel's query builder, you'll feel right at home—the API is identical.

## Basic Queries

**✅ Same as Eloquent**: All standard query methods work identically.

### Simple WHERE Clauses

```php
use App\Models\User;

// Basic where
$users = User::where('name', 'John')->get();
// Cypher: MATCH (n:users) WHERE n.name = 'John' RETURN n

// Multiple conditions
$users = User::where('name', 'John')
    ->where('age', '>', 25)
    ->get();
// Cypher: MATCH (n:users) WHERE n.name = 'John' AND n.age > 25 RETURN n

// Or conditions
$users = User::where('name', 'John')
    ->orWhere('name', 'Jane')
    ->get();
// Cypher: MATCH (n:users) WHERE n.name = 'John' OR n.name = 'Jane' RETURN n
```

### All Operators Work

```php
// Comparison operators
User::where('age', '=', 25)->get();
User::where('age', '!=', 25)->get();
User::where('age', '>', 25)->get();
User::where('age', '<', 25)->get();
User::where('age', '>=', 25)->get();
User::where('age', '<=', 25)->get();

// LIKE operator
User::where('name', 'LIKE', 'John%')->get();
User::where('email', 'LIKE', '%@example.com')->get();
```

### IN and NOT IN

```php
// whereIn
$users = User::whereIn('status', ['active', 'pending'])->get();
// Cypher: MATCH (n:users) WHERE n.status IN ['active', 'pending'] RETURN n

// whereNotIn
$users = User::whereNotIn('role', ['guest', 'banned'])->get();
// Cypher: MATCH (n:users) WHERE NOT n.role IN ['guest', 'banned'] RETURN n
```

### NULL Checks

```php
// whereNull
$users = User::whereNull('deleted_at')->get();

// whereNotNull
$users = User::whereNotNull('email_verified_at')->get();
```

### BETWEEN

```php
// whereBetween
$users = User::whereBetween('age', [25, 35])->get();

// whereNotBetween
$users = User::whereNotBetween('age', [25, 35])->get();
```

---

## Advanced Where Clauses

### Date/Time Queries

**✅ Same as Eloquent**: All date query methods work identically.

```php
// whereDate - match specific date
$posts = Post::whereDate('created_at', '2024-01-15')->get();

// whereYear
$posts = Post::whereYear('created_at', 2024)->get();

// whereMonth
$posts = Post::whereMonth('created_at', 1)->get();

// whereTime
$logs = Log::whereTime('created_at', '>', '12:00:00')->get();

// Combine date filters
$posts = Post::whereYear('created_at', 2024)
    ->whereMonth('created_at', 10)
    ->get();
```

### Column Comparisons

Compare two columns from the same node:

```php
// Basic column comparison
$users = User::whereColumn('updated_at', '>', 'created_at')->get();

// With different operators
$users = User::whereColumn('first_name', '=', 'last_name')->get();

// Multiple column comparisons
$users = User::whereColumn('first_name', '!=', 'last_name')
    ->whereColumn('updated_at', '>', 'created_at')
    ->get();
```

### Nested Where Groups

```php
// Complex boolean logic
$users = User::where('status', 'active')
    ->where(function ($query) {
        $query->where('role', 'admin')
              ->orWhere('role', 'moderator');
    })
    ->get();
// Cypher: MATCH (n:users) WHERE n.status = 'active' AND (n.role = 'admin' OR n.role = 'moderator') RETURN n

// Multiple nested groups
$posts = Post::where('published', true)
    ->where(function ($query) {
        $query->where('category', 'tech')
              ->orWhere('category', 'science');
    })
    ->where(function ($query) {
        $query->where('views', '>', 1000)
              ->orWhere('featured', true);
    })
    ->get();
```

### Raw WHERE Clauses

**⚠️ Different from Eloquent**: Use Neo4j Cypher syntax, not SQL.

```php
// Simple raw where
$users = User::whereRaw('n.age > 25')->get();

// With bindings
$users = User::whereRaw('n.age > $minAge', ['minAge' => 25])->get();

// Complex Cypher expressions
$users = User::whereRaw('n.score * n.multiplier > 100')->get();
```

**Note**: In `whereRaw()`, you must use `n.` prefix for node properties (where `n` is the default node alias in Cypher queries).

### JSON Queries

Neo4j stores nested arrays/objects as JSON strings. Query them like standard Eloquent:

```php
// whereJsonContains - check if JSON contains value
$users = User::whereJsonContains('settings->notifications', true)->get();

// Nested paths
$users = User::whereJsonContains('metadata->preferences->theme', 'dark')->get();

// whereJsonLength - check array/object length
$users = User::whereJsonLength('tags', '>', 3)->get();
$users = User::whereJsonLength('preferences', 5)->get();
```

---

## Ordering & Limiting

**✅ Same as Eloquent**: All ordering and limiting methods work identically.

### Sorting Results

```php
// Basic ordering
$users = User::orderBy('name')->get();
$users = User::orderBy('age', 'desc')->get();
$users = User::orderByDesc('created_at')->get();

// Multiple order clauses
$users = User::orderBy('status')
    ->orderByDesc('created_at')
    ->get();

// Timestamp shortcuts
$posts = Post::latest()->get(); // Orders by created_at DESC
$posts = Post::oldest()->get(); // Orders by created_at ASC
$posts = Post::latest('updated_at')->get(); // Custom column
```

### Random Order

```php
// Get random results
$users = User::inRandomOrder()->get();
```

### Limiting Results

```php
// limit / take
$users = User::limit(10)->get();
$users = User::take(10)->get(); // Alias for limit

// offset / skip
$users = User::offset(20)->limit(10)->get();
$users = User::skip(20)->take(10)->get(); // Aliases

// Get first result
$user = User::where('email', 'john@example.com')->first();

// First or fail (throws exception if not found)
$user = User::where('id', 123)->firstOrFail();
```

---

## Aggregations

### Standard Aggregates

**✅ Same as Eloquent**: All standard aggregates work identically.

```php
// Count
$total = User::count();
$active = User::where('status', 'active')->count();

// Sum
$totalSales = Order::sum('amount');
$userSales = Order::where('user_id', 1)->sum('amount');

// Average
$avgAge = User::avg('age');
$avgPrice = Product::avg('price');

// Min / Max
$youngest = User::min('age');
$oldest = User::max('age');
$cheapest = Product::min('price');
$mostExpensive = Product::max('price');
```

### Neo4j-Specific Aggregates

**⚠️ Different from Eloquent**: Additional aggregates unique to Neo4j.

#### Percentiles

Calculate percentile values for statistical analysis:

```php
// percentileDisc - Discrete percentile (returns actual value from dataset)
$p95ResponseTime = Metric::percentileDisc('response_time', 0.95);
$median = Metric::percentileDisc('score', 0.5); // 50th percentile

// percentileCont - Continuous percentile (interpolated value)
$p95Interpolated = Metric::percentileCont('response_time', 0.95);
$medianInterp = Metric::percentileCont('score', 0.5);
```

**When to use**:
- `percentileDisc`: When you need an actual value from your dataset (e.g., "95% of users scored below 87")
- `percentileCont`: When interpolation makes sense (e.g., "95th percentile response time is 245.3ms")

#### Standard Deviation

Calculate variability in your data:

```php
// stdev - Sample standard deviation
$priceVariability = Product::stdev('price');
$scoreSpread = Test::stdev('score');

// stdevp - Population standard deviation
$priceVariabilityPop = Product::stdevp('price');
```

**When to use**:
- `stdev`: When working with a sample of a larger population (most common)
- `stdevp`: When you have the entire population

#### Collect

Aggregate values into an array:

```php
// collect - Collect all values into array
$allTags = Post::where('status', 'published')->collect('tags');
// Returns: ['php', 'laravel', 'neo4j', ...]

$usernames = User::where('role', 'admin')->collect('username');
// Returns: ['alice', 'bob', 'charlie']
```

**Use cases**:
- Gathering all distinct values
- Building tag clouds
- Creating dropdown options

### Aggregates with Relationships

```php
// Count related models
$user = User::withCount('posts')->first();
echo $user->posts_count; // 42

// Multiple relationships
$user = User::withCount(['posts', 'comments'])->first();
echo $user->posts_count;    // 42
echo $user->comments_count; // 156

// With constraints
$user = User::withCount(['posts as published_posts' => function ($query) {
    $query->where('status', 'published');
}])->first();
echo $user->published_posts; // 38
```

---

## Selecting Columns

**⚠️ Different from Eloquent**: Minor differences with raw selects.

### Basic Column Selection

```php
// Select specific columns
$users = User::select('name', 'email')->get();
$users = User::select(['name', 'email'])->get();

// Add more columns
$users = User::select('name')
    ->addSelect('email')
    ->get();

// Select all (default)
$users = User::get(); // Returns all properties
$users = User::select('*')->get(); // Explicit all
```

### Raw Selects

**⚠️ Important**: Raw selects require the `n.` prefix for node properties.

```php
// selectRaw requires n. prefix
$users = User::selectRaw('n.name, n.age * 2 as double_age')->get();

// Complex expressions
$users = User::selectRaw('n.first_name + " " + n.last_name as full_name')->get();

// With aggregates
$stats = User::selectRaw('n.department, count(n) as total')
    ->groupBy('department')
    ->get();
```

**Why the `n.` prefix?** In Cypher (Neo4j's query language), `n` is the node alias. This is automatically handled for standard `select()`, but raw expressions need explicit prefixes.

### Distinct Results

```php
// Distinct values
$departments = User::select('department')->distinct()->get();

// With multiple columns
$combos = User::select('city', 'state')->distinct()->get();
```

---

## Eager Loading

**✅ Same as Eloquent**: All eager loading methods work identically.

### Basic Eager Loading

Prevent N+1 query problems by loading relationships upfront:

```php
// Load one relationship
$users = User::with('posts')->get();

// Load multiple relationships
$users = User::with(['posts', 'roles', 'profile'])->get();

// Nested relationships
$users = User::with('posts.comments')->get();

// Multiple nested
$users = User::with([
    'posts.comments.author',
    'roles.permissions'
])->get();
```

### Constrained Eager Loading

Filter related records as they're loaded:

```php
// Load only published posts
$users = User::with(['posts' => function ($query) {
    $query->where('status', 'published');
}])->get();

// Load only recent comments
$posts = Post::with(['comments' => function ($query) {
    $query->where('created_at', '>', now()->subDays(7))
          ->orderBy('created_at', 'desc');
}])->get();

// Multiple constraints
$users = User::with(['posts' => function ($query) {
    $query->where('status', 'published')
          ->orderBy('created_at', 'desc')
          ->limit(5);
}])->get();
```

### Lazy Eager Loading

Load relationships after retrieving the parent model:

```php
$users = User::all();

// Load relationship later
$users->load('posts');

// With constraints
$users->load(['posts' => function ($query) {
    $query->where('status', 'published');
}]);

// Load multiple
$users->load(['posts', 'roles']);
```

### Counting Relationships

```php
// Add relationship counts
$users = User::withCount('posts')->get();
foreach ($users as $user) {
    echo "{$user->name} has {$user->posts_count} posts";
}

// Multiple counts
$users = User::withCount(['posts', 'comments', 'likes'])->get();

// With constraints
$users = User::withCount([
    'posts',
    'posts as published_posts_count' => function ($query) {
        $query->where('status', 'published');
    }
])->get();
```

---

## Query Scopes

**✅ Same as Eloquent**: Scopes work identically.

### Local Scopes

Define reusable query constraints in your model:

```php
// In your model
class User extends GraphModel
{
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeAdmins($query)
    {
        return $query->where('role', 'admin');
    }

    public function scopeCreatedAfter($query, $date)
    {
        return $query->where('created_at', '>', $date);
    }
}

// Use in queries
$users = User::active()->get();
$admins = User::active()->admins()->get();
$recent = User::createdAfter('2024-01-01')->get();

// Chain with other methods
$posts = Post::published()->orderBy('created_at', 'desc')->limit(10)->get();
```

### Global Scopes

Apply constraints to all queries automatically:

```php
// Define a global scope
class ActiveScope implements \Illuminate\Database\Eloquent\Scope
{
    public function apply(\Illuminate\Database\Eloquent\Builder $builder, \Illuminate\Database\Eloquent\Model $model)
    {
        $builder->where('status', 'active');
    }
}

// Apply to model
class User extends GraphModel
{
    protected static function booted()
    {
        static::addGlobalScope(new ActiveScope);
    }
}

// Now all queries automatically filter active users
$users = User::all(); // Only active users

// Remove global scope when needed
$allUsers = User::withoutGlobalScope(ActiveScope::class)->get();
```

---

## Relationship Queries

**✅ Same as Eloquent**: All relationship query methods work identically.

### whereHas - Filter by Relationship Existence

Query models based on the existence of related records:

```php
// Find users who have posts
$authors = User::whereHas('posts')->get();

// With constraints
$activeAuthors = User::whereHas('posts', function ($query) {
    $query->where('status', 'published');
})->get();

// Multiple relationship constraints
$users = User::whereHas('posts', function ($query) {
    $query->where('status', 'published')
          ->where('views', '>', 1000);
})->get();
```

### whereHas with Count

```php
// Users with at least 5 posts
$prolificAuthors = User::whereHas('posts', null, '>=', 5)->get();

// Users with exactly 1 post
$oneHitWonders = User::whereHas('posts', null, '=', 1)->get();

// Users with more than 10 posts
$superAuthors = User::whereHas('posts', null, '>', 10)->get();

// Combine with constraints
$users = User::whereHas('posts', function ($query) {
    $query->where('status', 'published');
}, '>=', 3)->get();
```

### whereDoesntHave - Filter by Absence

Find models that don't have related records:

```php
// Users without any posts
$readers = User::whereDoesntHave('posts')->get();

// Users without published posts
$nonPublishers = User::whereDoesntHave('posts', function ($query) {
    $query->where('status', 'published');
})->get();

// Multiple relationships
$inactive = User::whereDoesntHave('posts')
    ->whereDoesntHave('comments')
    ->get();
```

### orWhereHas - OR Conditions

```php
// Users who have posts OR comments
$activeUsers = User::whereHas('posts')
    ->orWhereHas('comments')
    ->get();

// With constraints
$users = User::whereHas('posts', function ($query) {
    $query->where('status', 'published');
})->orWhereHas('comments', function ($query) {
    $query->where('approved', true);
})->get();
```

### has - Simple Existence Check

Shorthand when you don't need constraints:

```php
// Same as whereHas but shorter
$authors = User::has('posts')->get();

// With count
$prolific = User::has('posts', '>=', 5)->get();

// Multiple relationships (AND)
$active = User::has('posts')->has('comments')->get();
```

### Nested Relationship Queries

Query through multiple levels of relationships:

```php
// Find countries that have users with posts
$countries = Country::whereHas('users.posts')->get();

// With constraints at any level
$countries = Country::whereHas('users.posts', function ($query) {
    $query->where('status', 'published')
          ->where('created_at', '>', now()->subMonth());
})->get();

// Deep nesting
$orgs = Organization::whereHas('teams.members.posts', function ($query) {
    $query->where('views', '>', 10000);
})->get();
```

### Counting Related Records

```php
// Add relationship count
$users = User::withCount('posts')->get();
foreach ($users as $user) {
    echo $user->posts_count;
}

// Multiple counts
$users = User::withCount(['posts', 'comments'])->get();

// With constraints and custom names
$users = User::withCount([
    'posts as total_posts',
    'posts as published_posts' => function ($query) {
        $query->where('status', 'published');
    },
    'posts as draft_posts' => function ($query) {
        $query->where('status', 'draft');
    }
])->get();

// Use counts in queries
$popular = User::withCount('posts')
    ->having('posts_count', '>', 10)
    ->get();
```

### Relationship Query Examples

```php
// Complex multi-relationship query
$results = User::with(['posts' => function ($query) {
        $query->where('status', 'published')
              ->orderBy('created_at', 'desc')
              ->limit(5);
    }])
    ->withCount([
        'posts as total_posts',
        'comments as total_comments'
    ])
    ->whereHas('roles', function ($query) {
        $query->whereIn('name', ['author', 'editor']);
    })
    ->where('status', 'active')
    ->get();

// Posts with active authors and recent comments
$posts = Post::with(['author', 'comments' => function ($query) {
        $query->where('created_at', '>', now()->subDays(7))
              ->with('author');
    }])
    ->whereHas('author', function ($query) {
        $query->where('status', 'active');
    })
    ->orderBy('created_at', 'desc')
    ->paginate(20);
```

---

## Next Steps

- **[Neo4j Features Overview](neo4j-overview.md)** - Explore multi-label nodes, Cypher DSL, aggregates, and schema introspection
- **[Cypher DSL](cypher-dsl.md)** - Master graph traversal, pattern matching, and path finding
- **[Neo4j Aggregates](neo4j-aggregates.md)** - Learn percentile, standard deviation, and collect functions
- **[Performance Guide](performance.md)** - Optimize queries with indexes, batch operations, and caching

Ready to leverage Neo4j's unique graph capabilities? Check out the Cypher DSL guide for advanced querying patterns like graph traversal and shortest path algorithms.
