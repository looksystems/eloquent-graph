# Relationships

**All eight Eloquent relationship types work with Eloquent Cypher.**

This guide covers how to define and use relationships in Neo4j, including the key decision between traditional foreign keys and native graph edges.

## Table of Contents

- [Overview](#overview)
- [The Storage Decision](#the-storage-decision)
- [HasMany](#hasmany)
- [HasOne](#hasone)
- [BelongsTo](#belongsto)
- [BelongsToMany](#belongstomany)
- [HasManyThrough](#hasmanythrough)
- [HasOneThrough](#hasonethrough)
- [Polymorphic Relationships](#polymorphic-relationships)
- [Next Steps](#next-steps)

---

## Overview

Eloquent Cypher supports all standard Eloquent relationship types with the same familiar API:

- `hasMany` - One-to-many
- `hasOne` - One-to-one
- `belongsTo` - Inverse of hasMany/hasOne
- `belongsToMany` - Many-to-many
- `hasManyThrough` - Through intermediate models
- `hasOneThrough` - Single result through intermediate
- `morphMany` / `morphOne` - Polymorphic one-to-many / one-to-one
- `morphTo` - Polymorphic inverse
- `morphToMany` - Polymorphic many-to-many

**✅ Same as Eloquent**:
```php
// All familiar relationship methods work identically:
$user->posts()->create(['title' => 'Hello World']);
$user->posts; // Collection of Post models
$user->posts()->where('published', true)->get();
$post->user; // Related User model
User::with('posts')->get(); // Eager loading
User::has('posts')->get(); // Existence queries
```

**⚠️ Different from Eloquent**:

Neo4j offers a unique choice: store relationships as **foreign key properties** (like SQL) or **native graph edges** (true Neo4j relationships). You control this at three levels:

1. **Global**: `config('database.connections.neo4j.default_relationship_storage')`
2. **Per Model**: `protected $useNativeRelationships = true`
3. **Per Query**: `$user->posts()->useNativeEdges()->get()`

---

## The Storage Decision

Choose how to store relationships based on your needs. All three modes support the full Eloquent API.

### Foreign Key Mode (Default)

**How it works**: Stores relationships as properties on child nodes, exactly like SQL foreign keys.

```php
// Configuration (default)
'default_relationship_storage' => 'foreign_key'

// Standard Eloquent relationship - no changes needed
class User extends Neo4JModel
{
    public function posts()
    {
        return $this->hasMany(Post::class);
    }
}

class Post extends Neo4JModel
{
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

// Usage - identical to Eloquent
$user = User::create(['name' => 'John']);
$post = $user->posts()->create(['title' => 'My Post']);

// Post node has user_id property
// (:Post {id: 123, title: 'My Post', user_id: 456})
```

**✅ Advantages**:
- 100% Eloquent compatible - zero code changes
- Fast lookups with indexes on foreign key properties
- Easy migration from SQL databases
- Familiar mental model for Laravel developers

**When to use**: Default choice for most applications, especially when migrating from SQL or prioritizing Eloquent compatibility.

---

### Native Edge Mode

**How it works**: Uses real Neo4j relationships/edges between nodes. The graph database way.

```php
// Configuration
'default_relationship_storage' => 'edge'

// Or per-model
class User extends Neo4JModel
{
    protected $useNativeRelationships = true;

    public function posts()
    {
        return $this->hasMany(Post::class);
    }
}

// Or per-query
$posts = $user->posts()->useNativeEdges()->get();

// Creates actual Neo4j relationship:
// (:User)-[:HAS_MANY_POSTS]->(:Post)
```

**✅ Advantages**:
- Native graph traversal patterns
- Relationship properties (edge metadata)
- Graph algorithms and path finding
- Better for highly connected data
- Visualize in Neo4j Browser

**Example with edge properties** (BelongsToMany):

```php
class User extends Neo4JModel
{
    protected $useNativeRelationships = true;

    public function roles()
    {
        return $this->belongsToMany(Role::class)
            ->withPivot('assigned_at', 'expires_at');
    }
}

// Attach with edge properties
$user->roles()->attach($role->id, [
    'assigned_at' => now(),
    'expires_at' => now()->addYear()
]);

// Edge created: (:User)-[:USERS_ROLES {assigned_at: ..., expires_at: ...}]->(:Role)

// Access pivot data
foreach ($user->roles as $role) {
    echo $role->pivot->assigned_at;
    echo $role->pivot->expires_at;
}
```

**When to use**: When leveraging graph database features, need relationship metadata, or building highly connected social/network applications.

---

### Hybrid Mode

**How it works**: Creates **both** a native edge AND stores the foreign key property. Best of both worlds.

```php
// Configuration
'default_relationship_storage' => 'hybrid'

// Results in:
// 1. Foreign key property: user_id on Post node
// 2. Native edge: (:User)-[:HAS_MANY_POSTS]->(:Post)
```

**✅ Advantages**:
- Fast foreign key lookups (indexed properties)
- Native graph traversal available when needed
- Relationship metadata via edges
- Flexibility to optimize queries either way

**⚠️ Considerations**:
- Slightly more storage (property + edge)
- Must keep both in sync (automatic)

**When to use**: Production applications that want graph database benefits while maintaining SQL-like performance characteristics.

---

### Quick Comparison

| Feature | Foreign Key | Native Edge | Hybrid |
|---------|-------------|-------------|--------|
| Eloquent API | ✅ Full | ✅ Full | ✅ Full |
| Performance | Fast (indexed) | Fast (traversal) | Fast (both) |
| Graph Features | ❌ No | ✅ Yes | ✅ Yes |
| Edge Metadata | ❌ No | ✅ Yes | ✅ Yes |
| Storage | Minimal | Minimal | Property + Edge |
| Migration from SQL | Easy | Medium | Easy |

---

## HasMany

Define one-to-many relationships exactly like Eloquent.

### Foreign Key Mode (Default)

```php
class User extends Neo4JModel
{
    public function posts()
    {
        return $this->hasMany(Post::class);
    }
}

class Post extends Neo4JModel
{
    protected $fillable = ['title', 'content', 'user_id'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

// Create related models
$user = User::create(['name' => 'John']);
$post = $user->posts()->create(['title' => 'Hello World']);

// Automatically sets user_id on Post node
echo $post->user_id; // John's ID

// Retrieve relationships
$posts = $user->posts; // Collection of Post models
$user = $post->user; // User model
```

### Native Edge Mode

```php
class User extends Neo4JModel
{
    protected $useNativeRelationships = true;

    public function posts()
    {
        return $this->hasMany(Post::class);
    }
}

class Post extends Neo4JModel
{
    protected $useNativeRelationships = true;

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

// Same API - different storage
$user = User::create(['name' => 'Jane']);
$post = $user->posts()->create(['title' => 'Graph Post']);

// Creates: (:User {name: 'Jane'})-[:HAS_MANY_POSTS]->(:Post {title: 'Graph Post'})
// No user_id property stored (unless hybrid mode)
```

### All Eloquent Methods Work

```php
// Query relationships
$publishedPosts = $user->posts()->where('published', true)->get();

// Count
$count = $user->posts()->count();

// Eager loading
$users = User::with('posts')->get();

// Eager loading with constraints
$users = User::with(['posts' => function ($query) {
    $query->where('published', true)->orderBy('created_at', 'desc');
}])->get();

// Existence queries
$usersWithPosts = User::has('posts')->get();
$usersWithManyPosts = User::has('posts', '>=', 5)->get();

// whereHas with conditions
$users = User::whereHas('posts', function ($query) {
    $query->where('published', true);
})->get();

// Load count
$users = User::withCount('posts')->get();
echo $users[0]->posts_count; // Integer
```

**✅ Same as Eloquent**: All query methods, eager loading, and existence checks work identically.

---

## HasOne

One-to-one relationships for single related models.

```php
class User extends Neo4JModel
{
    public function profile()
    {
        return $this->hasOne(Profile::class);
    }
}

class Profile extends Neo4JModel
{
    protected $fillable = ['bio', 'avatar_url', 'user_id'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

// Create relationship
$user = User::create(['name' => 'Alice']);
$profile = $user->profile()->create([
    'bio' => 'Software developer',
    'avatar_url' => 'avatar.jpg'
]);

// Access relationship
$profile = $user->profile; // Single Profile model (or null)
$user = $profile->user; // User model

// Update through relationship
$user->profile()->update(['bio' => 'Updated bio']);

// Delete relationship
$user->profile()->delete();
```

### Native Edge Mode

```php
class User extends Neo4JModel
{
    protected $useNativeRelationships = true;

    public function profile()
    {
        return $this->hasOne(Profile::class);
    }
}

// Creates edge: (:User)-[:HAS_ONE_PROFILE]->(:Profile)
$profile = $user->profile()->create(['bio' => 'Graph profile']);
```

**✅ Same as Eloquent**: Returns single model or null, supports all standard query methods.

---

## BelongsTo

The inverse of hasMany and hasOne relationships.

```php
class Post extends Neo4JModel
{
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

// Access parent
$post = Post::find(1);
$user = $post->user; // User model or null

// Eager loading
$posts = Post::with('user')->get();
foreach ($posts as $post) {
    echo $post->user->name;
}

// Create and associate
$user = User::create(['name' => 'Bob']);
$post = new Post(['title' => 'New Post']);
$post->user()->associate($user);
$post->save();

// Dissociate
$post->user()->dissociate();
$post->save(); // user_id set to null
```

### Custom Keys

```php
class Post extends Neo4JModel
{
    public function author()
    {
        // belongsTo(Model, foreignKey, ownerKey)
        return $this->belongsTo(User::class, 'author_id', 'id');
    }
}
```

**✅ Same as Eloquent**: Associate, dissociate, and all query methods work identically.

---

## BelongsToMany

Many-to-many relationships with optional pivot data.

### Foreign Key Mode (Pivot Nodes)

```php
class User extends Neo4JModel
{
    public function roles()
    {
        return $this->belongsToMany(Role::class)
            ->withTimestamps();
    }
}

class Role extends Neo4JModel
{
    public function users()
    {
        return $this->belongsToMany(User::class);
    }
}

// Attach relationships
$user = User::create(['name' => 'Admin User']);
$role = Role::create(['name' => 'administrator']);

$user->roles()->attach($role->id);
$user->roles()->attach($role->id, ['expires_at' => now()->addYear()]);

// Creates intermediate pivot node:
// (:User)-[:user_id]->(:role_user {user_id: X, role_id: Y, expires_at: ...})
//                                -[:role_id]->(:Role)
```

### Native Edge Mode (Real Edges)

```php
class User extends Neo4JModel
{
    protected $useNativeRelationships = true;

    public function roles()
    {
        return $this->belongsToMany(Role::class)
            ->withPivot('assigned_at', 'assigned_by', 'level')
            ->withTimestamps();
    }
}

class Role extends Neo4JModel
{
    protected $useNativeRelationships = true;
}

// Attach with pivot data
$user->roles()->attach($adminRole->id, [
    'assigned_at' => now(),
    'assigned_by' => $currentUser->id,
    'level' => 10
]);

// Creates edge with properties:
// (:User)-[:USERS_ROLES {assigned_at: ..., assigned_by: ..., level: 10}]->(:Role)

// Access pivot data
foreach ($user->roles as $role) {
    echo $role->pivot->assigned_at;
    echo $role->pivot->level;

    // Pivot is Neo4jEdgePivot instance with edge properties
    if ($role->pivot->level > 5) {
        echo "High level access";
    }
}
```

### Pivot Operations

```php
// Attach (add relationships)
$user->roles()->attach($roleId);
$user->roles()->attach([$role1Id, $role2Id]);
$user->roles()->attach($roleId, ['level' => 5]);
$user->roles()->attach([
    $role1Id => ['level' => 5],
    $role2Id => ['level' => 10]
]);

// Detach (remove relationships)
$user->roles()->detach($roleId);
$user->roles()->detach([$role1Id, $role2Id]);
$user->roles()->detach(); // Remove all

// Sync (replace all relationships)
$user->roles()->sync([$role1Id, $role2Id]);
$user->roles()->sync([
    $role1Id => ['level' => 5],
    $role2Id => ['level' => 10]
]);

// syncWithoutDetaching (add without removing existing)
$user->roles()->syncWithoutDetaching([$newRoleId]);

// Toggle
$user->roles()->toggle([$role1Id, $role2Id]);

// Update pivot data
$user->roles()->updateExistingPivot($roleId, ['level' => 15]);
```

### Querying Pivot Data

```php
// Filter by pivot values
$users = User::whereHas('roles', function ($query) {
    $query->wherePivot('level', '>', 5);
})->get();

// Order by pivot
$roles = $user->roles()->orderByPivot('assigned_at', 'desc')->get();

// Select specific pivot columns
$roles = $user->roles()
    ->withPivot('assigned_at', 'level')
    ->get();
```

### Custom Pivot Table Name

```php
public function roles()
{
    // belongsToMany(Model, table, foreignPivotKey, relatedPivotKey)
    return $this->belongsToMany(Role::class, 'user_role_assignments', 'user_id', 'role_id');
}
```

**✅ Same as Eloquent**: All pivot methods (attach, detach, sync, toggle) work identically. Edge mode stores pivot data as relationship properties.

---

## HasManyThrough

Access distant relationships through intermediate models.

```php
class User extends Neo4JModel
{
    public function posts()
    {
        return $this->hasMany(Post::class);
    }

    public function comments()
    {
        // User -> Post -> Comment
        return $this->hasManyThrough(Comment::class, Post::class);
    }
}

class Post extends Neo4JModel
{
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function comments()
    {
        return $this->hasMany(Comment::class);
    }
}

class Comment extends Neo4JModel
{
    public function post()
    {
        return $this->belongsTo(Post::class);
    }
}

// Get all comments on user's posts
$user = User::find(1);
$comments = $user->comments; // Collection of Comment models

// Query through relationships
$comments = $user->comments()
    ->where('approved', true)
    ->orderBy('created_at', 'desc')
    ->get();

// Eager loading
$users = User::with('comments')->get();
```

### Custom Keys

```php
public function comments()
{
    // hasManyThrough(
    //     Model,
    //     Through,
    //     firstKey (foreign key on through table),
    //     secondKey (foreign key on final table),
    //     localKey (local key on this model),
    //     secondLocalKey (local key on through model)
    // )
    return $this->hasManyThrough(
        Comment::class,
        Post::class,
        'author_id',      // post.author_id
        'post_id',        // comment.post_id
        'id',             // user.id
        'id'              // post.id
    );
}
```

### Native Edge Benefits

With `useNativeRelationships`, through relationships use graph traversal:

```php
class User extends Neo4JModel
{
    protected $useNativeRelationships = true;

    public function comments()
    {
        return $this->hasManyThrough(Comment::class, Post::class);
    }
}

// Traverses: (:User)-[:HAS_MANY_POSTS]->(:Post)-[:HAS_MANY_COMMENTS]->(:Comment)
// Direct graph pattern matching - very efficient for deep traversals
```

**✅ Same as Eloquent**: All query methods work, supports eager loading and existence queries.

---

## HasOneThrough

Single result through intermediate model.

```php
class User extends Neo4JModel
{
    public function posts()
    {
        return $this->hasMany(Post::class);
    }

    public function latestComment()
    {
        // User -> Post -> Comment (single most recent)
        return $this->hasOneThrough(Comment::class, Post::class)
            ->latest('comments.created_at');
    }
}

// Get single comment
$user = User::find(1);
$comment = $user->latestComment; // Single Comment model or null

// With custom keys
public function featuredPostImage()
{
    return $this->hasOneThrough(
        Image::class,
        Post::class,
        'user_id',        // post.user_id
        'post_id',        // image.post_id
        'id',             // user.id
        'id'              // post.id
    )->where('images.featured', true);
}
```

**✅ Same as Eloquent**: Returns single model or null, supports all query constraints.

---

## Polymorphic Relationships

Relationships where a model can belong to multiple model types.

### MorphOne / MorphMany

```php
class User extends Neo4JModel
{
    public function avatar()
    {
        return $this->morphOne(Image::class, 'imageable');
    }

    public function images()
    {
        return $this->morphMany(Image::class, 'imageable');
    }
}

class Post extends Neo4JModel
{
    public function featuredImage()
    {
        return $this->morphOne(Image::class, 'imageable');
    }

    public function images()
    {
        return $this->morphMany(Image::class, 'imageable');
    }
}

class Image extends Neo4JModel
{
    protected $fillable = ['url', 'imageable_id', 'imageable_type'];

    public function imageable()
    {
        return $this->morphTo();
    }
}

// Create morphOne
$user = User::create(['name' => 'Alice']);
$avatar = $user->avatar()->create(['url' => 'avatar.jpg']);

// Image node has:
// {url: 'avatar.jpg', imageable_id: 1, imageable_type: 'Tests\\Models\\User'}

// Access parent
$image = Image::find($avatar->id);
$parent = $image->imageable; // Returns User model

// Create morphMany
$post = Post::create(['title' => 'Photo Gallery']);
$post->images()->create(['url' => 'photo1.jpg']);
$post->images()->create(['url' => 'photo2.jpg']);
$post->images()->create(['url' => 'photo3.jpg']);

$images = $post->images; // Collection of 3 Image models
```

### MorphTo

```php
class Comment extends Neo4JModel
{
    protected $fillable = ['content', 'commentable_id', 'commentable_type'];

    public function commentable()
    {
        return $this->morphTo();
    }
}

class Post extends Neo4JModel
{
    public function comments()
    {
        return $this->morphMany(Comment::class, 'commentable');
    }
}

class Video extends Neo4JModel
{
    public function comments()
    {
        return $this->morphMany(Comment::class, 'commentable');
    }
}

// Create comments on different types
$post = Post::create(['title' => 'Blog Post']);
$video = Video::create(['title' => 'Tutorial Video']);

$postComment = Comment::create([
    'content' => 'Great post!',
    'commentable_id' => $post->id,
    'commentable_type' => Post::class
]);

$videoComment = Comment::create([
    'content' => 'Helpful video!',
    'commentable_id' => $video->id,
    'commentable_type' => Video::class
]);

// Retrieve parent (different types)
$postComment = Comment::find($postComment->id);
$parent = $postComment->commentable; // Returns Post model

$videoComment = Comment::find($videoComment->id);
$parent = $videoComment->commentable; // Returns Video model
```

### Eager Loading Polymorphic Relationships

```php
// Eager load morphTo with multiple types
$comments = Comment::with('commentable')->get();

foreach ($comments as $comment) {
    if ($comment->commentable instanceof Post) {
        echo "Comment on post: " . $comment->commentable->title;
    } elseif ($comment->commentable instanceof Video) {
        echo "Comment on video: " . $comment->commentable->title;
    }
}

// Constrained eager loading
$users = User::with(['images' => function ($query) {
    $query->where('url', 'CONTAINS', 'avatar');
}])->get();
```

### Querying Polymorphic Relationships

```php
// Find all images for users
$userImages = Image::where('imageable_type', User::class)->get();

// whereHas with polymorphic
$usersWithImages = User::has('images')->get();

$usersWithAvatars = User::whereHas('images', function ($query) {
    $query->where('url', 'CONTAINS', 'avatar');
})->get();

// Count polymorphic relationships
$users = User::withCount('images')->get();
echo $users[0]->images_count;
```

### Custom Morph Type Names

```php
use Illuminate\Database\Eloquent\Relations\Relation;

// In service provider
Relation::morphMap([
    'user' => User::class,
    'post' => Post::class,
    'video' => Video::class,
]);

// Now imageable_type stores 'user' instead of 'App\Models\User'
// Useful for refactoring namespaces or cleaner storage
```

### MorphToMany

```php
class Post extends Neo4JModel
{
    public function tags()
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }
}

class Video extends Neo4JModel
{
    public function tags()
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }
}

class Tag extends Neo4JModel
{
    public function posts()
    {
        return $this->morphedByMany(Post::class, 'taggable');
    }

    public function videos()
    {
        return $this->morphedByMany(Video::class, 'taggable');
    }
}

// Attach tags
$post = Post::create(['title' => 'Laravel Tutorial']);
$video = Video::create(['title' => 'Laravel Video']);
$tag = Tag::create(['name' => 'laravel']);

$post->tags()->attach($tag->id);
$video->tags()->attach($tag->id);

// Query
$laravelPosts = Tag::where('name', 'laravel')->first()->posts;
$laravelVideos = Tag::where('name', 'laravel')->first()->videos;
```

**⚠️ Storage Mode**: Polymorphic relationships use **foreign key mode** by default for performance and compatibility. This stores `imageable_id` and `imageable_type` as properties on the child node.

While native edge mode is possible with polymorphic relationships, it requires additional configuration for edge type mapping. For most use cases, foreign key mode provides the best balance of performance and simplicity.

**✅ Same as Eloquent**: All polymorphic relationship types and methods work identically. MorphTo automatically resolves to correct model type.

---

## Next Steps

Now that you understand relationships, explore:

- **[Querying](querying.md)** - Advanced queries with relationships (whereHas, eager loading, etc.)
- **[Cypher DSL](cypher-dsl.md)** - Graph traversal, pattern matching, and path finding for complex relationship patterns
- **[Multi-Label Nodes](multi-label-nodes.md)** - Organize related models with multiple labels
- **[Performance](performance.md)** - Indexing strategies and batch operations for relationship queries

**Quick Tips**:

1. **Start with foreign key mode** - It's the safest migration path and works identically to SQL
2. **Add indexes** - Index foreign key properties for fast lookups: `$table->index('user_id')`
3. **Use eager loading** - Avoid N+1 queries with `User::with('posts')->get()`
4. **Consider hybrid mode** - Get both foreign key performance and graph traversal benefits
5. **Native edges for graphs** - Switch to native edges when building social networks or highly connected data
