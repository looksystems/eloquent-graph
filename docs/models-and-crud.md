# Models and CRUD Operations

Complete guide to creating models and performing CRUD operations with Eloquent Cypher. If you know Eloquent, you already know 95% of this.

## Table of Contents

1. [Creating Models](#creating-models)
2. [CRUD Operations](#crud-operations)
3. [Timestamps](#timestamps)
4. [Attribute Casting](#attribute-casting)
5. [Mutators & Accessors](#mutators--accessors)
6. [Mass Assignment](#mass-assignment)
7. [Soft Deletes](#soft-deletes)
8. [Next Steps](#next-steps)

---

## Creating Models

### Basic Model Setup

**✅ Same as Eloquent**: All model properties work identically - `$fillable`, `$guarded`, `$hidden`, `$casts`, `$appends`, `$with`, etc.

**⚠️ Different from Eloquent**: Three small changes required:

```php
// STANDARD ELOQUENT
use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    protected $fillable = ['name', 'email', 'age'];
    protected $hidden = ['password'];
    protected $casts = ['age' => 'integer'];
}

// ELOQUENT CYPHER
use Look\EloquentCypher\GraphModel;

class User extends GraphModel
{
    // 1. Set connection to 'graph'
    protected $connection = 'graph';

    // 2. Disable auto-incrementing (Neo4j uses unique IDs)
    public $incrementing = false;

    // 3. Set key type (defaults to 'int', can be 'string')
    protected $keyType = 'int';

    // Everything else is IDENTICAL to Eloquent
    protected $fillable = ['name', 'email', 'age'];
    protected $hidden = ['password'];
    protected $casts = ['age' => 'integer'];
}
```

### Table Names (Labels)

**✅ Same as Eloquent**: Table name conventions work identically.

```php
// Default: pluralized lowercase class name
class User extends GraphModel {}
// Table/Label: "users"

class BlogPost extends GraphModel {}
// Table/Label: "blog_posts"

// Custom table name
class User extends GraphModel
{
    protected $table = 'app_users';
}
```

**💡 Neo4j Context**: Your `$table` property becomes the node **label** in Neo4j. A User model with `$table = 'users'` creates nodes labeled `:users`.

### Primary Keys

**⚠️ Different from Eloquent**: Neo4j doesn't have auto-incrementing IDs.

```php
class User extends GraphModel
{
    // Always set these
    public $incrementing = false;
    protected $keyType = 'int'; // or 'string'

    // Optionally customize key name (defaults to 'id')
    protected $primaryKey = 'uuid';
}
```

**How it works**: Eloquent Cypher automatically generates unique IDs using PHP's `uniqid()` when you create models. You can also set IDs manually:

```php
$user = new User(['name' => 'John']);
$user->id = 'custom-id-123';
$user->save();
```

### Complete Model Example

```php
<?php

namespace App\Models;

use Look\EloquentCypher\GraphModel;

class User extends GraphModel
{
    protected $connection = 'graph';
    public $incrementing = false;
    protected $keyType = 'int';

    protected $fillable = [
        'name',
        'email',
        'age',
        'settings',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'age' => 'integer',
        'email_verified_at' => 'datetime',
        'settings' => 'array',
        'is_active' => 'boolean',
    ];

    protected $appends = ['full_profile'];

    public function getFullProfileAttribute()
    {
        return $this->name . ' (' . $this->email . ')';
    }
}
```

---

## CRUD Operations

**✅ Same as Eloquent**: All CRUD methods work identically. Zero learning curve.

### Create

```php
// Method 1: create() - mass assignment
$user = User::create([
    'name' => 'John Doe',
    'email' => 'john@example.com',
    'age' => 30,
]);

// Method 2: new + save()
$user = new User();
$user->name = 'Jane Smith';
$user->email = 'jane@example.com';
$user->age = 25;
$user->save();

// Method 3: firstOrCreate() - find or create
$user = User::firstOrCreate(
    ['email' => 'john@example.com'], // Search attributes
    ['name' => 'John Doe', 'age' => 30] // Additional attributes if creating
);

// Method 4: updateOrCreate() - update if exists, create if not
$user = User::updateOrCreate(
    ['email' => 'john@example.com'],
    ['name' => 'John Doe', 'age' => 31] // Updates if found, creates if not
);

// Method 5: firstOrNew() - find or instantiate (doesn't save)
$user = User::firstOrNew(
    ['email' => 'john@example.com'],
    ['name' => 'John Doe']
);
// $user is instantiated but not saved to database yet
$user->save(); // Now it's saved
```

### Read

```php
// Find by primary key
$user = User::find(1);
$user = User::find('some-unique-id');

// Find multiple by keys
$users = User::find([1, 2, 3]);

// Find or fail (throws ModelNotFoundException)
$user = User::findOrFail(1);

// Find or return new instance
$user = User::findOrNew(1);

// Get all records
$users = User::all();

// Get first record
$user = User::first();

// Get first or fail
$user = User::firstOrFail();

// Where queries (covered in depth in querying.md)
$users = User::where('age', '>', 25)->get();
$user = User::where('email', 'john@example.com')->first();

// Pluck specific column
$names = User::pluck('name');
$emailsByName = User::pluck('email', 'name');

// Count records
$count = User::count();
$activeCount = User::where('is_active', true)->count();
```

### Update

```php
// Method 1: Find and update properties
$user = User::find(1);
$user->name = 'Jane Doe';
$user->email = 'jane@example.com';
$user->save();

// Method 2: update() method
$user = User::find(1);
$user->update([
    'name' => 'Jane Doe',
    'email' => 'jane@example.com',
]);

// Method 3: fill() then save()
$user = User::find(1);
$user->fill([
    'name' => 'Jane Doe',
    'email' => 'jane@example.com',
]);
$user->save();

// Method 4: Mass update with query
User::where('age', '<', 18)->update(['is_minor' => true]);

// Method 5: Increment/Decrement
$user->increment('login_count');
$user->increment('credits', 10);
$user->decrement('credits', 5);

// Increment with additional updates
$user->increment('login_count', 1, ['last_login' => now()]);

// Touch timestamps
$user->touch(); // Updates updated_at
```

### Delete

```php
// Method 1: Delete existing model
$user = User::find(1);
$user->delete();

// Method 2: Delete by query
User::where('is_active', false)->delete();

// Method 3: Destroy by ID(s)
User::destroy(1);
User::destroy([1, 2, 3]);
User::destroy(1, 2, 3);

// Check if model exists after delete
$user = User::find(1);
$user->delete();
expect($user->exists)->toBeFalse();
```

**⚠️ Important**: Delete uses `DETACH DELETE` in Neo4j, which removes the node and all its relationships. This prevents orphaned relationships.

---

## Timestamps

**✅ Same as Eloquent**: Timestamps work automatically and identically.

```php
class User extends GraphModel
{
    // Timestamps enabled by default
    public $timestamps = true;

    // Automatically managed:
    // - created_at: Set when model is created
    // - updated_at: Set when model is created or updated
}

// Create a user
$user = User::create(['name' => 'John']);
echo $user->created_at; // Carbon instance: "2025-10-26 10:30:00"
echo $user->updated_at; // Carbon instance: "2025-10-26 10:30:00"

// Update the user
$user->update(['name' => 'Jane']);
echo $user->updated_at; // Carbon instance: "2025-10-26 11:45:00" (updated!)

// Touch timestamps manually
$user->touch(); // Updates updated_at to now
```

### Disable Timestamps

```php
class User extends GraphModel
{
    public $timestamps = false; // No automatic timestamps
}
```

### Custom Timestamp Columns

```php
class User extends GraphModel
{
    const CREATED_AT = 'creation_date';
    const UPDATED_AT = 'last_modified';
}
```

### Timestamp Format

**✅ Same as Eloquent**: Stored as `Y-m-d H:i:s` strings by default, retrieved as Carbon instances.

```php
// Access as Carbon instance
$user->created_at->format('M d, Y'); // "Oct 26, 2025"
$user->created_at->diffForHumans(); // "2 hours ago"
$user->created_at->addDays(7); // Add 7 days

// Timestamps work in queries
$recentUsers = User::where('created_at', '>', now()->subDays(7))->get();
```

---

## Attribute Casting

**✅ Same as Eloquent**: All cast types work identically. Every single one.

### Supported Cast Types

```php
class User extends GraphModel
{
    protected $casts = [
        // Primitives
        'age' => 'integer',        // or 'int'
        'salary' => 'float',       // or 'double', 'real'
        'bio' => 'string',
        'is_active' => 'boolean',  // or 'bool'

        // Decimals (with precision)
        'price' => 'decimal:2',    // "99.99" string

        // Arrays and Objects
        'options' => 'array',      // PHP array
        'metadata' => 'json',      // JSON string
        'config' => 'object',      // stdClass object
        'tags' => 'collection',    // Illuminate\Support\Collection

        // Dates and Times
        'birthday' => 'date',              // Carbon, Y-m-d
        'hired_at' => 'datetime',          // Carbon, Y-m-d H:i:s
        'last_login' => 'timestamp',       // Carbon from Unix timestamp
        'custom_date' => 'datetime:Y-m-d', // Custom format

        // Immutable dates (CarbonImmutable)
        'immutable_date' => 'immutable_date',
        'immutable_datetime' => 'immutable_datetime',

        // Encrypted
        'secret' => 'encrypted',               // Encrypted string
        'secret_array' => 'encrypted:array',   // Encrypted array
        'secret_json' => 'encrypted:json',     // Encrypted JSON

        // Hashed (one-way, for passwords)
        'password' => 'hashed',

        // Enums (PHP 8.1+)
        'status' => UserStatus::class,
    ];
}
```

### Casting Examples

```php
// Integer casting
$user = User::create(['age' => '25']); // String
echo $user->age; // 25 (integer)
echo gettype($user->age); // "integer"

// Boolean casting
$user = User::create(['is_active' => 1]);
echo $user->is_active; // true (boolean)

// Array casting
$user = User::create(['tags' => ['php', 'laravel', 'neo4j']]);
echo $user->tags; // ['php', 'laravel', 'neo4j'] (array)
$user->tags[] = 'eloquent';
$user->save();

// JSON casting
$user = User::create(['settings' => ['theme' => 'dark', 'lang' => 'en']]);
echo $user->settings['theme']; // 'dark'
$user->settings['notifications'] = true;
$user->save();

// Collection casting
$user = User::create(['roles' => ['admin', 'editor']]);
$user->roles->map(fn($role) => strtoupper($role)); // Collection methods!
$user->roles->contains('admin'); // true

// Date casting
$user = User::create(['birthday' => '1990-05-15']);
echo $user->birthday->format('M d, Y'); // "May 15, 1990"
echo $user->birthday->age; // 35

// Decimal casting
$product = Product::create(['price' => 99.999]);
echo $product->price; // "100.00" (string with 2 decimals)

// Encrypted casting
$user = User::create(['secret' => 'my-secret-value']);
// Stored encrypted in database, auto-decrypted on retrieval
echo $user->secret; // "my-secret-value"

// Hashed casting (for passwords)
$user = User::create(['password' => 'plain-text-password']);
// Stored as bcrypt hash, cannot be decrypted
// Use Hash::check() to verify
```

### Neo4j Storage Details

**Array/JSON Casting**: Eloquent Cypher uses a hybrid storage strategy:

- **Flat arrays** (primitives only): Stored as native Neo4j lists `['a', 'b', 'c']`
- **Nested arrays/objects**: Stored as JSON strings

```php
// Flat array - stored as Neo4j list
'tags' => ['php', 'laravel']  // Native: ['php', 'laravel']

// Nested array - stored as JSON string
'settings' => [
    'theme' => 'dark',
    'notifications' => ['email' => true, 'sms' => false]
]
// Stored as JSON: '{"theme":"dark","notifications":{"email":true,"sms":false}}'
```

This is automatic and transparent - you don't need to think about it.

---

## Mutators & Accessors

**✅ Same as Eloquent**: Mutators and accessors work identically. Both old and new syntax supported.

### Set Mutators (Transform on Write)

```php
class User extends GraphModel
{
    protected $fillable = ['name', 'email'];

    // Old syntax (still works)
    public function setNameAttribute($value)
    {
        $this->attributes['name'] = strtoupper($value);
    }

    public function setEmailAttribute($value)
    {
        $this->attributes['email'] = strtolower($value);
    }
}

$user = User::create(['name' => 'john doe', 'email' => 'JOHN@EXAMPLE.COM']);
echo $user->name;  // "JOHN DOE"
echo $user->email; // "john@example.com"
```

### Get Mutators (Transform on Read)

```php
class User extends GraphModel
{
    protected $fillable = ['price', 'first_name', 'last_name'];

    // Old syntax (still works)
    public function getPriceAttribute($value)
    {
        return $value ? '$' . number_format($value, 2) : null;
    }

    // Virtual attribute (computed, not stored)
    public function getFullNameAttribute()
    {
        return $this->first_name . ' ' . $this->last_name;
    }
}

$user = User::create(['price' => 99.5, 'first_name' => 'John', 'last_name' => 'Doe']);
echo $user->price;      // "$99.50" (formatted)
echo $user->full_name;  // "John Doe" (computed)
```

### Modern Attribute Classes (Laravel 9+)

**✅ Same as Eloquent**: New attribute syntax works perfectly.

```php
use Illuminate\Database\Eloquent\Casts\Attribute;

class User extends GraphModel
{
    protected $fillable = ['name', 'email'];

    // Modern syntax
    protected function name(): Attribute
    {
        return Attribute::make(
            get: fn($value) => strtoupper($value),
            set: fn($value) => strtolower($value),
        );
    }

    protected function email(): Attribute
    {
        return Attribute::make(
            get: fn($value) => strtolower($value),
            set: fn($value) => strtolower($value),
        );
    }

    // Virtual attribute
    protected function fullProfile(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->name . ' <' . $this->email . '>',
        );
    }
}
```

### Combining Casts and Mutators

Casts run before mutators, so you can combine them:

```php
class User extends GraphModel
{
    protected $casts = [
        'settings' => 'array',
    ];

    public function setSettingsAttribute($value)
    {
        // Ensure default values exist
        $defaults = ['theme' => 'light', 'lang' => 'en'];
        $this->attributes['settings'] = array_merge($defaults, $value);
    }

    public function getSettingsAttribute($value)
    {
        // Cast already converted to array, now add runtime defaults
        return $value ?? ['theme' => 'light', 'lang' => 'en'];
    }
}
```

---

## Mass Assignment

**✅ Same as Eloquent**: Mass assignment protection works identically.

### Using $fillable (Whitelist)

```php
class User extends GraphModel
{
    // Only these attributes can be mass-assigned
    protected $fillable = ['name', 'email', 'age'];
}

// This works
$user = User::create(['name' => 'John', 'email' => 'john@example.com', 'age' => 30]);

// This is ignored (role not in $fillable)
$user = User::create(['name' => 'John', 'role' => 'admin']);
echo $user->role; // null

// But you can set it directly
$user->role = 'admin';
$user->save();
```

### Using $guarded (Blacklist)

```php
class User extends GraphModel
{
    // All attributes fillable except these
    protected $guarded = ['id', 'role'];
}

// This works
$user = User::create(['name' => 'John', 'email' => 'john@example.com', 'age' => 30]);

// role is ignored (in $guarded)
$user = User::create(['name' => 'John', 'role' => 'admin']);
echo $user->role; // null
```

### Allow All (⚠️ Use with Caution)

```php
class User extends GraphModel
{
    protected $guarded = []; // Allow all attributes
}
```

### Force Fill (Bypass Protection)

```php
$user = new User();
$user->forceFill([
    'name' => 'John',
    'role' => 'admin', // Even if not fillable
])->save();
```

---

## Soft Deletes

**✅ Same as Eloquent**: Soft deletes work almost identically.

**⚠️ Different from Eloquent**: Use `GraphSoftDeletes` trait alongside Laravel's `SoftDeletes` trait.

### Setup

```php
use Illuminate\Database\Eloquent\SoftDeletes;
use Look\EloquentCypher\Concerns\GraphSoftDeletes;
use Look\EloquentCypher\GraphModel;

class User extends GraphModel
{
    use SoftDeletes, GraphSoftDeletes;

    protected $connection = 'graph';
    public $incrementing = false;
    protected $keyType = 'int';

    protected $fillable = ['name', 'email'];

    // Optional: Customize soft delete column
    // const DELETED_AT = 'removed_at';
}
```

**Why two traits?** Laravel's `SoftDeletes` provides scopes and helper methods. `GraphSoftDeletes` handles Neo4j-specific deletion logic. They work together seamlessly.

### Soft Delete Operations

```php
// Soft delete (sets deleted_at timestamp)
$user = User::find(1);
$user->delete();

echo $user->trashed(); // true
echo $user->deleted_at; // Carbon instance

// Soft deleted models are excluded from queries
$users = User::all(); // Doesn't include soft-deleted
$count = User::count(); // Doesn't count soft-deleted
```

### Query Scopes

```php
// Include soft deleted models
$allUsers = User::withTrashed()->get();

// Only soft deleted models
$trashedUsers = User::onlyTrashed()->get();

// Check if model is trashed
if ($user->trashed()) {
    echo "User is soft deleted";
}
```

### Restore Soft Deleted Models

```php
// Restore a single model
$user = User::onlyTrashed()->find(1);
$user->restore();

echo $user->trashed(); // false
echo $user->deleted_at; // null

// Restore via query
User::onlyTrashed()->where('email', 'like', '%@example.com')->restore();
```

### Force Delete (Permanent)

```php
// Force delete (permanently remove from database)
$user = User::find(1);
$user->forceDelete();

// Model is gone forever
expect(User::withTrashed()->find(1))->toBeNull();

// Force delete a soft-deleted model
$user = User::onlyTrashed()->find(1);
$user->forceDelete();
```

### Soft Delete Events

```php
// Soft delete events fire like regular delete events
User::deleting(function ($user) {
    Log::info("User {$user->id} is being soft deleted");
});

User::deleted(function ($user) {
    Log::info("User {$user->id} was soft deleted");
});

// Restoring events
User::restoring(function ($user) {
    Log::info("User {$user->id} is being restored");
});

User::restored(function ($user) {
    Log::info("User {$user->id} was restored");
});

// Force delete events
User::forceDeleting(function ($user) {
    Log::info("User {$user->id} is being force deleted");
});

User::forceDeleted(function ($user) {
    Log::info("User {$user->id} was permanently deleted");
});
```

### Soft Deletes with Relationships

```php
// Relationships respect soft deletes
$user = User::find(1);
$posts = $user->posts; // Excludes soft-deleted posts if Post model uses SoftDeletes

// Include trashed related models
$posts = $user->posts()->withTrashed()->get();

// Cascade soft delete (manual)
User::deleting(function ($user) {
    $user->posts()->delete(); // Soft delete all posts
});
```

### Complete Soft Delete Example

```php
// Create user
$user = User::create(['name' => 'John', 'email' => 'john@example.com']);
$userId = $user->id;

// Soft delete
$user->delete();
expect($user->trashed())->toBeTrue();
expect(User::find($userId))->toBeNull(); // Not found in normal queries
expect(User::withTrashed()->find($userId))->not->toBeNull(); // Found with withTrashed

// Restore
$user->restore();
expect($user->trashed())->toBeFalse();
expect(User::find($userId))->not->toBeNull(); // Found again

// Force delete
$user->forceDelete();
expect(User::withTrashed()->find($userId))->toBeNull(); // Gone forever
```

---

## Next Steps

Now that you understand models and CRUD operations, explore these related topics:

- **[Relationships](relationships.md)**: Connect models with relationships (hasMany, belongsTo, belongsToMany, etc.)
- **[Querying](querying.md)**: Advanced query builder features (where, joins, aggregates, scopes, etc.)
- **[Neo4j Features Overview](neo4j-overview.md)**: Overview of Neo4j-specific features
- **[Multi-Label Nodes](multi-label-nodes.md)**: Organize models with multiple labels
- **[Cypher DSL](cypher-dsl.md)**: Graph traversal and pattern matching
- **[Performance](performance.md)**: Optimization tips (batch operations, managed transactions, indexes)

---

## Quick Reference

### Essential Model Properties

```php
class User extends GraphModel
{
    // Required
    protected $connection = 'graph';
    public $incrementing = false;
    protected $keyType = 'int';

    // Optional (common)
    protected $table = 'users';              // Default: pluralized class name
    protected $primaryKey = 'id';            // Default: 'id'
    public $timestamps = true;               // Default: true
    protected $fillable = [];                // Whitelist for mass assignment
    protected $guarded = [];                 // Blacklist for mass assignment
    protected $hidden = [];                  // Hide from JSON
    protected $visible = [];                 // Show only these in JSON
    protected $casts = [];                   // Type casting
    protected $appends = [];                 // Virtual attributes in JSON
    protected $with = [];                    // Always eager load these
    const CREATED_AT = 'created_at';         // Default: 'created_at'
    const UPDATED_AT = 'updated_at';         // Default: 'updated_at'
    const DELETED_AT = 'deleted_at';         // Default: 'deleted_at' (soft deletes)
}
```

### CRUD Cheat Sheet

```php
// Create
User::create(['name' => 'John']);
$user = new User(['name' => 'John']); $user->save();
User::firstOrCreate(['email' => 'john@example.com'], ['name' => 'John']);
User::updateOrCreate(['email' => 'john@example.com'], ['name' => 'John']);

// Read
User::find(1);
User::find([1, 2, 3]);
User::findOrFail(1);
User::all();
User::first();
User::where('age', '>', 25)->get();

// Update
$user->update(['name' => 'Jane']);
$user->name = 'Jane'; $user->save();
User::where('is_active', false)->update(['status' => 'inactive']);
$user->increment('login_count');

// Delete
$user->delete();
User::destroy(1);
User::destroy([1, 2, 3]);
User::where('is_active', false)->delete();

// Soft Deletes
$user->delete();            // Soft delete
$user->restore();           // Restore
$user->forceDelete();       // Permanent delete
User::withTrashed()->get(); // Include soft deleted
User::onlyTrashed()->get(); // Only soft deleted
```

