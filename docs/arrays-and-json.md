# Arrays and JSON Storage

Neo4j has unique property storage constraints compared to SQL databases. This guide explains how Eloquent Cypher handles arrays and JSON data, providing Laravel-familiar patterns while leveraging Neo4j's native types for optimal performance.

---

## Understanding Neo4j Property Limitations

**⚠️ Key Constraint**: Neo4j properties can only store:
- Primitives: strings, numbers, booleans, dates
- Lists of primitives: `['php', 'laravel', 'neo4j']`

**❌ Not Supported**:
- Nested arrays: `[['a', 'b'], ['c', 'd']]`
- Associative arrays: `['key' => 'value']`
- Objects within properties

**✅ Solution**: Eloquent Cypher uses a hybrid strategy—flat arrays as native LISTs, complex data as JSON strings.

---

## Hybrid Storage Strategy

Eloquent Cypher automatically detects array structure and chooses the optimal storage format.

### How It Works

**Automatic Detection**:
```php
protected function isFlatArray(array $array): bool
{
    // Empty arrays → flat
    if (empty($array)) {
        return true;
    }

    // Associative arrays → NOT flat (must be JSON)
    if (array_keys($array) !== range(0, count($array) - 1)) {
        return false;
    }

    // Nested structures → NOT flat (must be JSON)
    foreach ($array as $value) {
        if (is_array($value) || is_object($value)) {
            return false;
        }
    }

    return true; // Flat indexed array of primitives
}
```

**Storage Decision Tree**:
```
Array Type                     Storage Format        Query Performance
─────────────────────────────────────────────────────────────────────
['php', 'js']                  Native LIST           Fast (no APOC)
['key' => 'val']               JSON string           Medium (uses APOC)
[['nested'], 'data']           JSON string           Medium (uses APOC)
Laravel Collection             JSON string           Medium (uses APOC)
```

**✅ Why Hybrid?**
- Best performance for simple arrays
- No APOC dependency for common cases
- Full Laravel compatibility for complex data
- Automatic—no configuration needed

---

## Array Casting

Use Laravel's familiar `$casts` property to define array attributes.

### Basic Array Casting

```php
use Look\EloquentCypher\Neo4JModel;

class User extends Neo4JModel
{
    protected $casts = [
        'tags' => 'array',        // For nested/associative arrays
        'skills' => 'array',      // Stored as JSON strings
        'roles' => 'array',
    ];
}

// Usage
$user = User::create([
    'name' => 'John',
    'tags' => ['php', 'laravel'],           // Flat → Native LIST
    'skills' => ['frontend' => 'vue'],      // Associative → JSON
    'roles' => [['admin', 'level' => 2]],   // Nested → JSON
]);

// Retrieval
$tags = $user->tags;        // Returns: ['php', 'laravel']
$skills = $user->skills;    // Returns: ['frontend' => 'vue']
```

### JSON Casting

For complex nested structures, use explicit `json` casting:

```php
class Post extends Neo4JModel
{
    protected $casts = [
        'metadata' => 'json',
        'settings' => 'json',
    ];
}

// Create with nested data
$post = Post::create([
    'title' => 'Introduction',
    'metadata' => [
        'author' => [
            'name' => 'John',
            'social' => ['twitter' => '@john'],
        ],
        'stats' => [
            'views' => 1000,
            'shares' => ['twitter' => 50, 'facebook' => 30],
        ],
    ],
]);

// Access nested properties
$authorName = $post->metadata['author']['name'];  // 'John'
$views = $post->metadata['stats']['views'];        // 1000
```

**✅ Automatic Encoding**: Eloquent Cypher handles JSON encoding transparently.

**⚠️ JSON Format**: Uses clean JSON (`JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`) for APOC compatibility.

---

## Storage Decision Guide

Choose the right storage strategy based on your data structure and query needs.

### When to Use Native Arrays (Flat Lists)

**Best For**:
- Simple tags: `['php', 'javascript', 'python']`
- Categories: `['news', 'tech', 'sports']`
- Simple IDs: `[1, 2, 3, 4]`

**Benefits**:
- No APOC dependency
- Fastest query performance
- Direct Cypher operators work

**Example**:
```php
class Article extends Neo4JModel
{
    // No cast needed - automatically detected as flat
    protected $fillable = ['title', 'tags'];
}

Article::create([
    'title' => 'Laravel Tips',
    'tags' => ['php', 'laravel', 'tips'],  // Stored as native LIST
]);

// Fast queries without APOC
Article::whereJsonContains('tags', 'laravel')->get();
```

### When to Use JSON Casting

**Best For**:
- Settings objects: `['theme' => 'dark', 'notifications' => true]`
- Nested configurations
- Complex metadata with mixed types
- API response data

**Benefits**:
- Full Laravel compatibility
- Complex nesting supported
- Works with existing JSON

**Example**:
```php
class User extends Neo4JModel
{
    protected $casts = [
        'preferences' => 'json',
        'settings' => 'json',
    ];
}

User::create([
    'name' => 'Jane',
    'preferences' => [
        'theme' => 'dark',
        'email' => ['marketing' => false, 'updates' => true],
        'privacy' => ['profile' => 'friends', 'posts' => 'public'],
    ],
]);
```

### When to Use Separate Nodes

**Best For**:
- Relationships between entities
- Data with its own lifecycle
- Frequently queried complex objects
- Graph traversal patterns

**Anti-Pattern** (storing related entities as JSON):
```php
// ❌ Don't do this
$user->update([
    'posts' => [
        ['id' => 1, 'title' => 'Post 1'],
        ['id' => 2, 'title' => 'Post 2'],
    ],
]);
```

**✅ Correct Approach** (use relationships):
```php
class User extends Neo4JModel
{
    public function posts()
    {
        return $this->hasMany(Post::class);
    }
}

// Much better
$user->posts()->create(['title' => 'New Post']);
```

**Rule of Thumb**: If you'd create a separate table in SQL, use a separate node and relationship in Neo4j.

---

## Advanced JSON Queries

Eloquent Cypher supports deep JSON path navigation using Laravel's arrow syntax.

### Nested Path Syntax

```php
// Single level
User::whereJsonContains('preferences->theme', 'dark')->get();

// Multiple levels
User::whereJsonContains('settings->profile->visibility', 'public')->get();

// Deep nesting (3+ levels)
Post::whereJsonContains('metadata->author->social->twitter', '@johndoe')->get();
```

**⚠️ Path Format**: Use `->` to separate path segments (Laravel convention).

### Querying Arrays Within JSON

```php
class User extends Neo4JModel
{
    protected $casts = [
        'settings' => 'json',
    ];
}

// Create user with nested array
$user = User::create([
    'name' => 'John',
    'settings' => [
        'notifications' => [
            'channels' => ['email', 'sms', 'push'],
            'frequency' => 'daily',
        ],
    ],
]);

// Check if array contains value
User::whereJsonContains('settings->notifications->channels', 'email')->get();
User::whereJsonContains('settings->notifications->channels', 'push')->get();
```

### Complex Nested Structures

```php
$product = Product::create([
    'name' => 'Laptop',
    'specs' => [
        'hardware' => [
            'cpu' => ['brand' => 'Intel', 'model' => 'i7'],
            'ram' => ['size' => 16, 'type' => 'DDR4'],
            'storage' => ['type' => 'SSD', 'capacity' => 512],
        ],
        'features' => ['touchscreen', 'backlit-keyboard'],
    ],
]);

// Query nested objects
Product::whereJsonContains('specs->hardware->cpu->brand', 'Intel')->get();

// Query nested arrays
Product::whereJsonContains('specs->features', 'touchscreen')->get();
```

**✅ APOC Detection**: Automatically uses APOC if available, falls back to string matching.

---

## whereJsonContains Deep Dive

Complete guide to all `whereJsonContains` patterns.

### Basic Usage

```php
// Simple equality check
User::whereJsonContains('preferences->theme', 'dark')->get();

// Negation
User::whereJsonDoesntContain('preferences->theme', 'light')->get();
```

### Array Membership

```php
// Check if value exists in array
User::whereJsonContains('tags', 'php')->get();
User::whereJsonContains('settings->roles', 'admin')->get();
```

**How It Works**:
```cypher
-- For native LISTs
WHERE 'php' IN n.tags

-- For JSON arrays (with APOC)
WHERE 'admin' IN apoc.convert.fromJsonMap(n.settings).roles
```

### Combining Conditions

```php
// AND logic
User::whereJsonContains('preferences->theme', 'dark')
    ->whereJsonContains('preferences->language', 'en')
    ->get();

// OR logic
User::whereJsonContains('tags', 'php')
    ->orWhereJsonContains('tags', 'javascript')
    ->get();

// Complex nested logic
User::where(function ($query) {
    $query->whereJsonContains('settings->role', 'admin')
          ->orWhereJsonContains('settings->role', 'moderator');
})->whereJsonContains('preferences->notifications', true)->get();
```

### Edge Cases

```php
// Empty arrays
User::whereJsonContains('tags', 'nonexistent')->get();  // No results

// Null values
User::whereJsonContains('preferences->missing->key', 'value')->get();  // No results

// Boolean values
User::whereJsonContains('settings->enabled', true)->get();

// Numeric values
User::whereJsonContains('config->version', 2)->get();
```

**⚠️ Type Sensitivity**: Values are compared with strict equality.

---

## whereJsonLength Usage

Check the length of JSON arrays with various operators.

### Basic Length Checks

```php
// Exact length
User::whereJsonLength('skills', 3)->get();

// Greater than
User::whereJsonLength('skills', '>', 5)->get();

// Less than
User::whereJsonLength('tags', '<', 10)->get();

// Greater than or equal
User::whereJsonLength('roles', '>=', 2)->get();

// Less than or equal
User::whereJsonLength('permissions', '<=', 5)->get();
```

### With Nested Paths

```php
// Check length of nested array
Post::whereJsonLength('metadata->comments', '>', 10)->get();
Post::whereJsonLength('metadata->likes', 0)->get();  // Posts with no likes

User::whereJsonLength('settings->notifications->channels', '>=', 2)->get();
```

### Practical Examples

```php
// Find power users (many skills)
$experts = User::whereJsonLength('skills', '>', 7)->get();

// Find beginners (few skills)
$beginners = User::whereJsonLength('skills', '<=', 2)->get();

// Find posts needing moderation (many comments)
$busyPosts = Post::whereJsonLength('metadata->comments', '>', 50)->get();

// Find inactive users (empty activity)
$inactive = User::whereJsonLength('recent_activities', 0)->get();
```

### Combining with Other Conditions

```php
// Experts in PHP
User::whereJsonContains('skills', 'php')
    ->whereJsonLength('skills', '>', 5)
    ->get();

// Popular posts with engagement
Post::whereJsonLength('metadata->likes', '>', 100)
    ->whereJsonLength('metadata->comments', '>', 20)
    ->get();
```

**✅ All Operators**: `=`, `!=`, `>`, `<`, `>=`, `<=` all work as expected.

---

## APOC Integration

APOC (Awesome Procedures on Cypher) enhances JSON operations. Eloquent Cypher auto-detects and uses APOC when available.

### What is APOC?

**APOC** is Neo4j's standard library for extended functionality:
- JSON parsing and manipulation
- Data conversion utilities
- Advanced graph algorithms

**✅ Optional Enhancement**: Package works without APOC, but JSON queries are more powerful with it.

### Automatic Detection

```php
// Check if APOC is available
$hasApoc = DB::connection('neo4j')->hasApoc();

if ($hasApoc) {
    // Use advanced JSON queries
    User::whereJsonContains('deep->nested->path', 'value')->get();
}
```

**Configuration**:
```php
// config/database.php
'neo4j' => [
    'use_apoc_for_json' => true,  // Default: true (auto-detect and use)
],
```

### APOC Functions Used

**1. JSON Parsing**:
```cypher
-- Convert JSON string to map
apoc.convert.fromJsonMap(n.settings)

-- Convert JSON string to list
apoc.convert.fromJsonList(n.tags)
```

**2. Path Navigation**:
```cypher
-- Access nested properties
apoc.convert.fromJsonMap(n.preferences).theme.colors
apoc.convert.fromJsonMap(n.settings).profile.visibility
```

**3. Hybrid Type Handling**:
```cypher
-- Eloquent Cypher generates type-safe queries
CASE
  WHEN valueType(n.column) STARTS WITH 'STRING'
    THEN apoc.convert.fromJsonMap(n.column).path
  WHEN valueType(n.column) STARTS WITH 'LIST'
    THEN n.column
  ELSE null
END
```

### Fallback Behavior

Without APOC, Eloquent Cypher falls back to string-based matching:

```cypher
-- Without APOC (limited nested support)
WHERE n.preferences CONTAINS '"theme":"dark"'

-- With APOC (full path support)
WHERE apoc.convert.fromJsonMap(n.preferences).theme = 'dark'
```

**⚠️ Limitations Without APOC**:
- Nested path queries less accurate
- String pattern matching only
- No type-aware comparisons

**✅ Recommendation**: Install APOC for production use.

### Installing APOC

**Docker Compose** (recommended):
```yaml
services:
  neo4j:
    image: neo4j:5-community
    environment:
      NEO4JLABS_PLUGINS: '["apoc"]'
```

**Manual Installation**:
1. Download APOC JAR from https://github.com/neo4j/apoc/releases
2. Place in `$NEO4J_HOME/plugins/`
3. Add to `neo4j.conf`: `dbms.security.procedures.unrestricted=apoc.*`
4. Restart Neo4j

**Verify Installation**:
```cypher
CALL apoc.help("convert")
```

---

## Performance Comparison

Understand the performance implications of different storage strategies.

### Benchmark Results

**Query Type**: Finding users with specific tag (1000 records)

| Storage Strategy    | Query Time | APOC Required | Use Case                    |
|---------------------|------------|---------------|-----------------------------|
| Native LIST         | 5ms        | No            | Simple flat arrays          |
| JSON with APOC      | 12ms       | Yes           | Nested structures           |
| JSON without APOC   | 45ms       | No            | Fallback (string matching)  |
| Separate Nodes      | 3ms        | No            | Frequently queried entities |

**Takeaways**:
- Native LISTs are fastest for simple arrays
- APOC significantly improves JSON query performance
- Separate nodes are best for complex, frequently queried data

### Storage Size Comparison

**Example**: User with 10 tags

```php
// Native LIST: ~120 bytes
['php', 'laravel', 'vue', 'mysql', 'redis', ...]

// JSON string: ~180 bytes (includes encoding overhead)
'["php","laravel","vue","mysql","redis",...]'

// Separate nodes: ~1.2KB (includes relationship overhead)
(:User)-[:HAS_TAG]->(:Tag {name: "php"})
```

**Rule**: Use native LISTs for read-heavy simple arrays, JSON for complex objects.

### Query Optimization Tips

**1. Use Native LISTs for Hot Paths**:
```php
// Fast queries
class Article extends Neo4JModel
{
    // tags stored as native LIST
    protected $fillable = ['title', 'tags'];
}

// No JSON parsing overhead
Article::whereJsonContains('tags', 'php')->get();
```

**2. Index JSON Properties** (APOC required):
```cypher
-- Create index on nested property
CALL apoc.schema.assert(null, {
  User: [['preferences.theme']]
})
```

**3. Denormalize for Performance**:
```php
// Instead of deep nesting
$user->settings = [
    'profile' => [
        'visibility' => 'public',
        'theme' => 'dark',
    ],
];

// Store frequently accessed values at top level
$user->theme = 'dark';  // Direct property access (fastest)
$user->settings = ['profile' => ['visibility' => 'public']];
```

---

## Indexing JSON Data

While Neo4j doesn't natively index JSON properties, strategies exist to improve query performance.

### Property Extraction Pattern

**Extract to Properties**:
```php
class User extends Neo4JModel
{
    protected $casts = [
        'settings' => 'json',
    ];

    // Automatically sync JSON fields to properties
    protected static function booted()
    {
        static::saving(function ($user) {
            // Extract frequently queried JSON fields
            if (isset($user->settings['theme'])) {
                $user->theme = $user->settings['theme'];
            }
        });
    }
}
```

**Create Index**:
```cypher
CREATE INDEX user_theme FOR (u:users) ON (u.theme)
```

**Query**:
```php
// Fast indexed query
User::where('theme', 'dark')->get();

// Instead of slower JSON query
User::whereJsonContains('settings->theme', 'dark')->get();
```

### Composite Strategy

**Hybrid Approach**:
```php
class Post extends Neo4JModel
{
    protected $fillable = [
        'title',
        'view_count',    // Extracted for indexing
        'metadata',      // Full JSON for flexibility
    ];

    protected $casts = [
        'metadata' => 'json',
    ];

    protected static function booted()
    {
        static::saving(function ($post) {
            // Sync frequently queried fields
            $post->view_count = $post->metadata['stats']['views'] ?? 0;
        });
    }
}
```

**Indexes**:
```cypher
-- Index extracted property
CREATE INDEX post_views FOR (p:posts) ON (p.view_count)

-- Query uses fast index
MATCH (p:posts) WHERE p.view_count > 1000
```

**✅ Benefits**:
- Fast indexed queries on critical fields
- Full JSON flexibility for other data
- Transparent to application code

### APOC Text Index (Alternative)

With APOC, create full-text indexes on JSON content:

```cypher
-- Create full-text index
CALL db.index.fulltext.createNodeIndex(
  "userSettings",
  ["User"],
  ["settings"]
)

-- Query with full-text search
CALL db.index.fulltext.queryNodes("userSettings", "dark theme")
YIELD node, score
```

**⚠️ Limitation**: Full-text search doesn't support structured path queries.

---

## Best Practices

Patterns for effective array and JSON usage in Neo4j.

### 1. Choose the Right Storage Format

```php
// ✅ Good: Simple arrays as native LISTs
class Article extends Neo4JModel
{
    protected $fillable = ['title', 'tags'];  // Auto-detected as flat
}

// ✅ Good: Complex structures as JSON
class User extends Neo4JModel
{
    protected $casts = [
        'preferences' => 'json',  // Nested associative array
    ];
}

// ❌ Bad: Related entities as JSON
class User extends Neo4JModel
{
    protected $casts = [
        'posts' => 'json',  // Should be relationship!
    ];
}
```

### 2. Denormalize Hot Paths

```php
// Extract frequently queried nested values
class User extends Neo4JModel
{
    protected $casts = ['settings' => 'json'];

    // Denormalize for performance
    protected static function booted()
    {
        static::saving(function ($user) {
            // Top-level indexed property
            $user->theme = $user->settings['theme'] ?? 'light';
            $user->language = $user->settings['language'] ?? 'en';
        });
    }
}

// Fast query on indexed property
User::where('theme', 'dark')->get();

// Slow query on JSON path
User::whereJsonContains('settings->theme', 'dark')->get();
```

### 3. Keep JSON Shallow

```php
// ✅ Good: 2-3 levels max
$user->preferences = [
    'theme' => 'dark',
    'email' => ['marketing' => false, 'updates' => true],
];

// ⚠️ Risky: Deep nesting hurts performance and maintainability
$user->config = [
    'app' => [
        'ui' => [
            'theme' => [
                'mode' => 'dark',
                'colors' => [
                    'primary' => '#000',
                    // ... 5 levels deep
                ],
            ],
        ],
    ],
];
```

### 4. Validate JSON Structure

```php
use Illuminate\Validation\Rule;

class User extends Neo4JModel
{
    protected $casts = ['preferences' => 'json'];

    public static function rules()
    {
        return [
            'preferences' => 'required|array',
            'preferences.theme' => 'required|in:light,dark',
            'preferences.language' => 'required|string|size:2',
            'preferences.email' => 'required|array',
            'preferences.email.marketing' => 'boolean',
        ];
    }
}
```

### 5. Document JSON Schema

```php
/**
 * User preferences schema:
 *
 * preferences: {
 *   theme: "light" | "dark",
 *   language: string (ISO 639-1),
 *   email: {
 *     marketing: boolean,
 *     updates: boolean,
 *     newsletter: boolean
 *   },
 *   privacy: {
 *     profile: "public" | "friends" | "private",
 *     posts: "public" | "friends" | "private"
 *   }
 * }
 */
class User extends Neo4JModel
{
    protected $casts = ['preferences' => 'json'];
}
```

### 6. Use Accessors for Complex Logic

```php
class User extends Neo4JModel
{
    protected $casts = ['preferences' => 'json'];

    // Clean accessor
    public function getThemeAttribute()
    {
        return $this->preferences['theme'] ?? 'light';
    }

    // Setter with validation
    public function setThemeAttribute($value)
    {
        $prefs = $this->preferences ?? [];
        $prefs['theme'] = in_array($value, ['light', 'dark']) ? $value : 'light';
        $this->preferences = $prefs;
    }
}

// Clean usage
$user->theme = 'dark';  // Validates and updates JSON
echo $user->theme;       // Returns 'dark'
```

---

## Migration Guide

Convert between storage strategies as your application evolves.

### Flat Array to JSON

**Scenario**: Adding complex structure to simple tags.

**Before**:
```php
class Article extends Neo4JModel
{
    // tags stored as native LIST: ['php', 'laravel']
}
```

**After**:
```php
class Article extends Neo4JModel
{
    protected $casts = [
        'tags' => 'json',  // Now structured: {'primary': ['php'], 'secondary': ['laravel']}
    ];
}
```

**Migration**:
```php
// Migrate data
Article::chunk(100, function ($articles) {
    foreach ($articles as $article) {
        $article->tags = [
            'primary' => [$article->tags[0] ?? null],
            'secondary' => array_slice($article->tags, 1),
        ];
        $article->save();
    }
});
```

### JSON to Separate Nodes

**Scenario**: JSON array becomes relationship.

**Before**:
```php
$user->settings = [
    'favorite_posts' => [1, 2, 3, 4, 5],  // Post IDs
];
```

**After**:
```php
class User extends Neo4JModel
{
    public function favoritePosts()
    {
        return $this->belongsToMany(Post::class, 'FAVORITED');
    }
}
```

**Migration**:
```cypher
// Create relationships from JSON data
MATCH (u:users)
WHERE u.settings IS NOT NULL
WITH u, apoc.convert.fromJsonMap(u.settings).favorite_posts AS postIds
UNWIND postIds AS postId
MATCH (p:posts {id: postId})
MERGE (u)-[:FAVORITED]->(p)

// Remove JSON property
MATCH (u:users)
REMOVE u.settings
```

### Denormalization Pattern

**Extract hot fields** for performance:

```php
// Artisan command
class DenormalizeUserTheme extends Command
{
    public function handle()
    {
        User::whereNotNull('preferences')
            ->chunk(100, function ($users) {
                foreach ($users as $user) {
                    $user->theme = $user->preferences['theme'] ?? 'light';
                    $user->save();
                }
            });

        $this->info('Denormalized theme property for all users');
    }
}
```

**Create Index**:
```cypher
CREATE INDEX user_theme FOR (u:users) ON (u.theme)
```

---

## Real-World Examples

Complete examples demonstrating best practices.

### Example 1: User Settings

```php
class User extends Neo4JModel
{
    protected $casts = [
        'preferences' => 'json',
    ];

    protected $fillable = [
        'name',
        'email',
        'preferences',
        'theme',  // Denormalized hot field
    ];

    protected static function booted()
    {
        static::saving(function ($user) {
            // Auto-sync theme for fast queries
            if (isset($user->preferences['theme'])) {
                $user->theme = $user->preferences['theme'];
            }
        });
    }

    // Accessor for clean API
    public function getNotificationSettingsAttribute()
    {
        return $this->preferences['notifications'] ?? [
            'email' => true,
            'push' => false,
        ];
    }
}

// Usage
$user = User::create([
    'name' => 'John',
    'email' => 'john@example.com',
    'preferences' => [
        'theme' => 'dark',
        'language' => 'en',
        'notifications' => [
            'email' => true,
            'push' => true,
            'sms' => false,
        ],
    ],
]);

// Fast indexed query
$darkThemeUsers = User::where('theme', 'dark')->get();

// JSON query (slower)
$emailEnabled = User::whereJsonContains('preferences->notifications->email', true)->get();
```

### Example 2: Product Metadata

```php
class Product extends Neo4JModel
{
    protected $casts = [
        'features' => 'json',
        'specifications' => 'json',
    ];

    // Commonly queried features as top-level properties
    protected $fillable = [
        'name',
        'price',
        'in_stock',      // Denormalized
        'rating',        // Denormalized
        'features',
        'specifications',
    ];

    protected static function booted()
    {
        static::saving(function ($product) {
            // Sync for fast filtering
            $product->in_stock = $product->specifications['inventory']['in_stock'] ?? false;
            $product->rating = $product->specifications['reviews']['average'] ?? 0;
        });
    }
}

// Usage
$laptop = Product::create([
    'name' => 'ThinkPad X1',
    'price' => 1299,
    'features' => ['touchscreen', 'backlit-keyboard', 'thunderbolt'],
    'specifications' => [
        'hardware' => [
            'cpu' => 'Intel i7-1185G7',
            'ram' => 16,
            'storage' => ['type' => 'SSD', 'capacity' => 512],
        ],
        'inventory' => [
            'in_stock' => true,
            'warehouse' => 'US-West',
        ],
        'reviews' => [
            'count' => 127,
            'average' => 4.5,
        ],
    ],
]);

// Fast queries on denormalized properties
$available = Product::where('in_stock', true)
    ->where('price', '<', 1500)
    ->get();

// JSON queries for detailed filtering
$touchscreenLaptops = Product::whereJsonContains('features', 'touchscreen')
    ->whereJsonContains('specifications->hardware->cpu', 'Intel')
    ->get();
```

### Example 3: Feature Flags

```php
class Organization extends Neo4JModel
{
    protected $casts = [
        'feature_flags' => 'json',
    ];

    public function hasFeature(string $feature): bool
    {
        $flags = $this->feature_flags ?? [];
        return $flags[$feature] ?? false;
    }

    public function enableFeature(string $feature): void
    {
        $flags = $this->feature_flags ?? [];
        $flags[$feature] = true;
        $this->update(['feature_flags' => $flags]);
    }

    public function scopeWithFeature($query, string $feature)
    {
        return $query->whereJsonContains("feature_flags->{$feature}", true);
    }
}

// Usage
$org = Organization::create([
    'name' => 'Acme Corp',
    'feature_flags' => [
        'advanced_analytics' => true,
        'api_access' => false,
        'custom_branding' => true,
        'sso' => false,
    ],
]);

// Check features
if ($org->hasFeature('advanced_analytics')) {
    // Show analytics dashboard
}

// Query by feature
$premiumOrgs = Organization::withFeature('custom_branding')->get();
```

---

## Troubleshooting

Common issues and solutions.

### Issue: APOC Not Detected

**Symptom**: JSON queries fall back to string matching.

**Solution**:
```bash
# Verify APOC installation
docker exec neo4j-test cypher-shell -u neo4j -p password "CALL apoc.help('convert')"

# Check config
php artisan tinker
>>> DB::connection('neo4j')->hasApoc()
```

**If false**, install APOC (see [APOC Integration](#apoc-integration) section).

### Issue: whereJsonContains Returns No Results

**Symptom**: Query returns empty when data exists.

**Debug**:
```php
// Check actual stored format
$user = User::find(1);
dd($user->preferences);  // Array or string?

// Check raw attribute
dd($user->getAttributes()['preferences']);  // Native type or JSON?
```

**Solutions**:
```php
// Option 1: Explicit cast
protected $casts = ['preferences' => 'json'];

// Option 2: Check APOC availability
if (!DB::connection('neo4j')->hasApoc()) {
    // Use simpler queries
    User::where('preferences', 'LIKE', '%"theme":"dark"%')->get();
}
```

### Issue: Nested Path Not Working

**Symptom**: Deep paths fail: `settings->profile->visibility->level`.

**Cause**: Without APOC, nested paths have limited support.

**Solutions**:
```php
// Option 1: Install APOC (recommended)

// Option 2: Flatten structure
// Instead of: settings->profile->visibility->level
// Use: settings->profile_visibility_level

// Option 3: Denormalize hot paths
protected static function booted()
{
    static::saving(function ($user) {
        $user->visibility_level = $user->settings['profile']['visibility']['level'] ?? 'public';
    });
}
```

### Issue: Type Mismatch Errors

**Symptom**: `Cannot compare STRING to INTEGER`.

**Cause**: JSON encodes all values as strings.

**Solution**:
```php
// Ensure consistent types
User::whereJsonContains('settings->version', '2');  // String

// Not:
User::whereJsonContains('settings->version', 2);   // Integer
```

### Issue: Performance Degradation

**Symptom**: JSON queries are slow.

**Solutions**:
```php
// 1. Install APOC for faster parsing

// 2. Denormalize frequently queried fields
protected static function booted()
{
    static::saving(function ($model) {
        $model->hot_field = $model->json_data['nested']['path'];
    });
}

// 3. Use native LISTs for simple arrays
// Change: protected $casts = ['tags' => 'json'];
// To: Let auto-detection handle it (native LIST if flat)

// 4. Add indexes on denormalized properties
CREATE INDEX model_hot_field FOR (m:model_table) ON (m.hot_field)
```

---

## Next Steps

**Continue Learning**:
- [Models and CRUD](models-and-crud.md) - Model configuration and attribute casting
- [Querying Data](querying.md) - Complete query builder guide
- [Performance Guide](performance.md) - Optimization strategies

**Related Topics**:
- [Multi-Label Nodes](multi-label-nodes.md) - Organizing data with labels
- [Neo4j Aggregates](neo4j-aggregates.md) - Statistical functions and analytics
- [Relationships](relationships.md) - Graph relationship patterns

**Need Help?**
- [Troubleshooting Guide](troubleshooting.md)
- [GitHub Issues](https://github.com/your-repo/eloquent-cypher/issues)
