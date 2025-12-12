# Cypher DSL Guide

**The complete reference for type-safe Neo4j queries in Laravel**

Version: 1.3.0 | Status: Production Ready | Coverage: 87 tests (100% passing)

## Quick Navigation

**New to DSL?** Start with [Getting Started](#getting-started) and [Basic Query Building](#basic-query-building).

**Building graph queries?** See [Graph Traversal Patterns](#graph-traversal-patterns) and [Path Finding](#path-finding).

**Need examples?** Jump to [Complete Real-World Examples](#complete-real-world-examples).

## Table of Contents

| Section | Difficulty | Use For |
|---------|------------|---------|
| [Getting Started](#getting-started) | 🟢 Beginner | First DSL query, setup |
| [Basic Query Building](#basic-query-building) | 🟢 Beginner | WHERE, ORDER, LIMIT |
| [Graph Traversal Patterns](#graph-traversal-patterns) | 🟡 Intermediate | Relationships, paths |
| [Path Finding](#path-finding) | 🟡 Intermediate | Shortest path, all paths |
| [Model Integration & Hydration](#model-integration--hydration) | 🟡 Intermediate | Type casting, collections |
| [Macros & Extensibility](#macros--extensibility) | 🟡 Intermediate | Reusable patterns |
| [Advanced DSL Features](#advanced-dsl-features) | 🔴 Advanced | Aggregations, subqueries |
| [Performance & Best Practices](#performance--best-practices) | 🔴 Advanced | Optimization tips |
| [Complete Real-World Examples](#complete-real-world-examples) | All | Social network, recommendations |
| [API Reference](#api-reference) | All | Method lookup |

---

## Introduction & Architecture

### What is the Cypher DSL?

The Cypher DSL is a **type-safe, fluent query builder** for Neo4j that brings Laravel-like elegance to graph database queries. Instead of string concatenation, you build queries with a chainable API that provides IDE autocomplete, type checking, and protection against injection attacks.

**Traditional raw Cypher:**
```php
// String manipulation, no type safety, injection risks
$results = DB::connection('graph')->cypher(
    'MATCH (n:users) WHERE n.age > ' . $age . ' RETURN n'
);
```

**With Cypher DSL:**
```php
// Type-safe, composable, IDE-friendly
$users = User::match()
    ->where(Query::variable('n')->property('age')->gt(Query::literal(25)))
    ->get(); // Collection<User> with casts applied
```

### Why Use the DSL?

✅ **Type Safety** - Catch errors at development time, not runtime
✅ **IDE Support** - Full autocomplete for all methods and parameters
✅ **No String Concatenation** - Build queries programmatically
✅ **Injection Protection** - Automatic parameterization prevents attacks
✅ **Model Hydration** - Returns typed model collections, not arrays
✅ **Composable** - Chain methods, reuse patterns via macros
✅ **Laravel Conventions** - Familiar `get()`, `first()`, `count()` methods
✅ **100% Backward Compatible** - Existing raw Cypher still works

### Architecture Overview

The DSL integration consists of three layers:

```
┌─────────────────────────────────────────────────────────────┐
│ Your Application                                            │
│  User::match()->where(...)->get()                          │
└─────────────────────────────────────────────────────────────┘
                         ↓
┌─────────────────────────────────────────────────────────────┐
│ Laravel Integration Layer (Eloquent Cypher)                │
│  - Neo4jCypherDslBuilder (wraps DSL with Laravel API)      │
│  - HasCypherDsl trait (adds match/matchFrom to models)     │
│  - GraphPatternHelpers (convenience traversal methods)     │
│  - Model hydration with casts                              │
└─────────────────────────────────────────────────────────────┘
                         ↓
┌─────────────────────────────────────────────────────────────┐
│ PHP Cypher DSL (wikibase-solutions/php-cypher-dsl)        │
│  - Query builder (match, where, returning, etc.)           │
│  - Type system (Node, Variable, Parameter, Literal)        │
│  - Cypher generation                                        │
└─────────────────────────────────────────────────────────────┘
                         ↓
┌─────────────────────────────────────────────────────────────┐
│ Neo4j Database                                              │
│  MATCH (n:users) WHERE n.age > 25 RETURN n                 │
└─────────────────────────────────────────────────────────────┘
```

**Key Classes:**
- `Neo4jCypherDslBuilder` - Main builder with Laravel conventions
- `HasCypherDsl` - Trait adding `match()` and `matchFrom()` to models
- `GraphPatternHelpers` - Convenience methods (outgoing, incoming, etc.)
- `CypherDslFactory` - Creates builders and DSL objects
- `Cypher` Facade - Static access for convenience

---

## Getting Started

**Difficulty:** 🟢 Beginner

### Prerequisites

The DSL is automatically available - no additional setup required!

```json
{
    "require": {
        "wikibase-solutions/php-cypher-dsl": "^3.0"
    }
}
```

### The HasCypherDsl Trait

Every `GraphModel` automatically includes DSL support:

```php
use Look\EloquentCypher\GraphModel;
use Look\EloquentCypher\Traits\HasCypherDsl;

class User extends GraphModel
{
    use HasCypherDsl; // Already included in GraphModel

    protected $table = 'users';
}
```

This trait adds two powerful methods:
- `match()` - Start query for all nodes of this type (static)
- `matchFrom()` - Start query from this specific instance

### Your First DSL Query

Let's compare raw Cypher with the DSL:

```php
use WikibaseSolutions\CypherDSL\Query;

// Raw Cypher (still works!)
$rawResults = DB::connection('graph')->cypher(
    'MATCH (n:users) WHERE n.age > $age RETURN n',
    ['age' => 25]
);

// DSL - Static model query
$users = User::match()
    ->where(Query::variable('n')->property('age')->gt(Query::literal(25)))
    ->returning(Query::variable('n'))
    ->get();
// Returns: Collection<User> with proper casts

// DSL - Instance query
$user = User::find(1);
$following = $user->matchFrom()
    ->outgoing('FOLLOWS', 'users')
    ->returning(Query::variable('target'))
    ->get();
// Returns: Collection<User> - who this user follows
```

### Query Objects Overview

The DSL uses typed objects for every query component:

| Object | Purpose | Example |
|--------|---------|---------|
| `Query::node()` | Create node pattern | `Query::node('users')` |
| `Query::variable()` | Reference node variable | `Query::variable('n')` |
| `Query::parameter()` | Parameterized value | `Query::parameter('age', 25)` |
| `Query::literal()` | Inline literal value | `Query::literal(true)` |
| `Query::rawExpression()` | Raw Cypher snippet | `Query::rawExpression('COUNT(n)')` |

**Basic pattern:**
```php
$builder = User::match(); // Creates: MATCH (n:users)

$builder->where(
    Query::variable('n')          // Reference the 'n' variable
        ->property('age')         // Access 'age' property
        ->gt(Query::literal(25))  // Greater than 25
);
// Adds: WHERE n.age > 25

$users = $builder->returning(Query::variable('n'))->get();
// Adds: RETURN n
// Executes and hydrates models
```

### Execution Methods

Every builder supports Laravel-style execution:

```php
// Get all results as Collection
$users = User::match()->get();

// Get first result or null
$user = User::match()
    ->where(Query::variable('n')->property('email')->equals(Query::literal('john@example.com')))
    ->first();

// Count matching nodes
$count = User::match()
    ->where(Query::variable('n')->property('active')->equals(Query::literal(true)))
    ->count();

// Get raw Cypher (for debugging)
$cypher = User::match()->toCypher();
// "MATCH (n:users) RETURN n"

$sql = User::match()->toSql(); // Alias for toCypher()
```

---

## Basic Query Building

**Difficulty:** 🟢 Beginner → 🟡 Intermediate

### Match Patterns

Start every query with a `MATCH` clause:

```php
use WikibaseSolutions\CypherDSL\Query;

// Match all users
$users = User::match()
    ->returning(Query::variable('n'))
    ->get();
// MATCH (n:users) RETURN n

// Match from connection (no automatic model hydration)
$results = DB::connection('graph')->cypher()
    ->match(Query::node('users')->named('u'))
    ->returning(Query::variable('u'))
    ->get();
// MATCH (u:users) RETURN u
// Returns Collection<stdClass>

// Match multiple node types
$results = DB::connection('graph')->cypher()
    ->match([
        Query::node('users')->named('u'),
        Query::node('posts')->named('p')
    ])
    ->where(Query::variable('p')->property('user_id')
        ->equals(Query::variable('u')->property('id')))
    ->returning(['user' => Query::variable('u'), 'post' => Query::variable('p')])
    ->get();
// MATCH (u:users), (p:posts) WHERE p.user_id = u.id RETURN u AS user, p AS post
```

### WHERE Conditions

Build complex filters with chainable conditions:

```php
// Simple equality
$users = User::match()
    ->where(Query::variable('n')->property('status')->equals(Query::literal('active')))
    ->get();
// WHERE n.status = 'active'

// Comparison operators
$seniors = User::match()
    ->where(Query::variable('n')->property('age')->gte(Query::literal(65)))
    ->get();
// WHERE n.age >= 65

$youngAdults = User::match()
    ->where(Query::variable('n')->property('age')->gt(Query::literal(18)))
    ->where(Query::variable('n')->property('age')->lt(Query::literal(30)))
    ->get();
// WHERE n.age > 18 AND n.age < 30 (multiple where = AND)
```

**Comparison operators:**
- `equals()` - Equality (=)
- `gt()` - Greater than (>)
- `gte()` - Greater than or equal (>=)
- `lt()` - Less than (<)
- `lte()` - Less than or equal (<=)

### AND/OR Logic

Combine conditions with boolean operators:

```php
// AND conditions (method 1: multiple where calls)
$users = User::match()
    ->where(Query::variable('n')->property('age')->gte(Query::literal(25)))
    ->where(Query::variable('n')->property('status')->equals(Query::literal('active')))
    ->get();
// WHERE n.age >= 25 AND n.status = 'active'

// AND conditions (method 2: chained and())
$users = User::match()
    ->where(
        Query::variable('n')->property('age')->gte(Query::literal(25))
            ->and(Query::variable('n')->property('status')->equals(Query::literal('active')))
    )
    ->get();
// WHERE n.age >= 25 AND n.status = 'active'

// OR conditions
$users = User::match()
    ->where(
        Query::variable('n')->property('age')->lt(Query::literal(26))
            ->or(Query::variable('n')->property('age')->gt(Query::literal(64)))
    )
    ->get();
// WHERE n.age < 26 OR n.age > 64
```

### RETURN Clauses

Specify what to return from queries:

```php
// Return entire node
$users = User::match()
    ->returning(Query::variable('n'))
    ->get();
// RETURN n

// Return specific properties
$names = User::match()
    ->returning([
        'name' => Query::variable('n')->property('name'),
        'age' => Query::variable('n')->property('age')
    ])
    ->get();
// RETURN n.name AS name, n.age AS age

// Return with aliasing
$results = User::match()
    ->returning(['user_name' => Query::variable('n')->property('name')])
    ->get();
// RETURN n.name AS user_name
```

### Ordering Results

Sort results with `orderBy`:

```php
// Order ascending (default)
$users = User::match()
    ->returning(Query::variable('n'))
    ->orderBy(Query::variable('n')->property('name'))
    ->get();
// ORDER BY n.name

// Order descending
$users = User::match()
    ->returning(Query::variable('n'))
    ->orderBy(Query::variable('n')->property('age'), 'DESC')
    ->get();
// ORDER BY n.age DESC

// Multiple order columns
$users = User::match()
    ->returning(Query::variable('n'))
    ->orderBy(Query::variable('n')->property('status'))
    ->orderBy(Query::variable('n')->property('name'))
    ->get();
// ORDER BY n.status, n.name
```

### Limiting & Pagination

Control result set size:

```php
// Limit results
$topUsers = User::match()
    ->returning(Query::variable('n'))
    ->orderBy(Query::variable('n')->property('score'), 'DESC')
    ->limit(Query::literal(10))
    ->get();
// ORDER BY n.score DESC LIMIT 10

// Skip and limit (pagination)
$page2 = User::match()
    ->returning(Query::variable('n'))
    ->orderBy(Query::variable('n')->property('name'))
    ->skip(Query::literal(20))
    ->limit(Query::literal(20))
    ->get();
// ORDER BY n.name SKIP 20 LIMIT 20

// Implement pagination helper
function paginateUsers(int $page, int $perPage = 20) {
    return User::match()
        ->returning(Query::variable('n'))
        ->orderBy(Query::variable('n')->property('created_at'), 'DESC')
        ->skip(Query::literal(($page - 1) * $perPage))
        ->limit(Query::literal($perPage))
        ->get();
}
```

### Parameters vs Literals

Use parameters for values from user input, literals for constants:

```php
// Literal - value baked into query
$users = User::match()
    ->where(Query::variable('n')->property('age')->gt(Query::literal(18)))
    ->get();
// WHERE n.age > 18

// Parameter - value passed separately (safer)
$minAge = $_GET['min_age'] ?? 18;
$users = DB::connection('graph')->cypher()
    ->match(Query::node('users')->named('n'))
    ->where(Query::variable('n')->property('age')->gt(Query::parameter('minAge')))
    ->withParameter('minAge', $minAge)
    ->returning(Query::variable('n'))
    ->get();
// WHERE n.age > $minAge
// Bindings: ['minAge' => 18]

// Best practice: Always use parameters for user input!
```

---

## Graph Traversal Patterns

**Difficulty:** 🟡 Intermediate

Graph traversal is where the DSL really shines. These helpers make complex relationship queries simple.

### Outgoing Relationships

Follow relationships from source to target:

```php
// Basic: Find who a user follows
$user = User::find(1);
$following = $user->matchFrom()
    ->outgoing('FOLLOWS', 'users')
    ->returning(Query::variable('target'))
    ->get();
// MATCH (n:users {id: 1})-[:FOLLOWS]->(target:users) RETURN target
// Returns: Collection<User>

// Without label filtering (any target type)
$related = $user->matchFrom()
    ->outgoing('RELATED_TO')
    ->returning(Query::variable('target'))
    ->get();
// MATCH (n:users {id: 1})-[:RELATED_TO]->(target) RETURN target

// With additional WHERE filters
$activeFollowing = $user->matchFrom()
    ->outgoing('FOLLOWS', 'users')
    ->where(Query::variable('target')->property('active')->equals(Query::literal(true)))
    ->returning(Query::variable('target'))
    ->get();
// MATCH (n:users {id: 1})-[:FOLLOWS]->(target:users)
// WHERE target.active = true
// RETURN target
```

**Method signature:**
```php
outgoing(string $type, ?string $targetLabel = null): self
```

⚠️ **Note:** The `outgoing()` helper returns the builder for the **target** nodes, not relationships.

### Incoming Relationships

Follow relationships from target back to source:

```php
// Find a post's author
$post = Post::find(1);
$author = $post->matchFrom()
    ->incoming('HAS_POST', 'users')
    ->returning(Query::variable('source'))
    ->first();
// MATCH (source:users)-[:HAS_POST]->(n:posts {id: 1}) RETURN source
// Returns: User model

// Find all followers
$user = User::find(1);
$followers = $user->matchFrom()
    ->incoming('FOLLOWS', 'users')
    ->returning(Query::variable('source'))
    ->get();
// MATCH (source:users)-[:FOLLOWS]->(n:users {id: 1}) RETURN source

// Filter incoming relationships
$youngFollowers = $user->matchFrom()
    ->incoming('FOLLOWS', 'users')
    ->where(Query::variable('source')->property('age')->lt(Query::literal(30)))
    ->returning(Query::variable('source'))
    ->get();
```

**Method signature:**
```php
incoming(string $type, ?string $sourceLabel = null): self
```

### Bidirectional Relationships

Match relationships regardless of direction:

```php
// Mutual friendships (works both ways)
$user = User::find(1);
$friends = $user->matchFrom()
    ->bidirectional('FRIENDS', 'users')
    ->returning(Query::variable('other'))
    ->get();
// MATCH (n:users {id: 1})-[:FRIENDS]-(other:users) RETURN other
// Returns users connected via FRIENDS in any direction

// Filter bidirectional
$adultFriends = $user->matchFrom()
    ->bidirectional('FRIENDS', 'users')
    ->where(Query::variable('other')->property('age')->gte(Query::literal(21)))
    ->returning(Query::variable('other'))
    ->get();
```

**Method signature:**
```php
bidirectional(string $type, ?string $label = null): self
```

✅ **Use Case:** Perfect for symmetric relationships like FRIENDS, CONNECTED_TO, or RELATED_TO.

### Chaining Traversals

Build multi-hop queries by chaining traversal methods:

```php
// Find friends of friends
$user = User::find(1);

// First hop: user's friends
$friends = $user->matchFrom()
    ->outgoing('FRIENDS', 'users')
    ->returning(Query::variable('target'))
    ->get();

// Two-hop pattern (requires manual DSL)
$fof = DB::connection('graph')->cypher()
    ->match(
        Query::node('users')->named('user')
            ->withProperties(['id' => $user->id])
            ->relationshipTo(Query::node('users')->named('friend'), ['FRIENDS'])
            ->relationshipTo(Query::node('users')->named('fof'), ['FRIENDS'])
    )
    ->returning(Query::variable('fof'))
    ->get();
// MATCH (user:users {id: 1})-[:FRIENDS]->(friend:users)-[:FRIENDS]->(fof:users)
// RETURN fof

// Filter at each hop
$activeFriendsWithPosts = $user->matchFrom()
    ->outgoing('FRIENDS', 'users')
    ->where(Query::variable('target')->property('active')->equals(Query::literal(true)))
    ->match(Query::variable('target')->relationshipTo(Query::node('posts')->named('p'), ['HAS_POST']))
    ->returning(['friend' => Query::variable('target'), 'post_count' => Query::rawExpression('COUNT(p)')])
    ->get();
```

### Static vs Instance Traversal

Two ways to use traversal helpers:

```php
// Instance traversal (matchFrom) - specific node context
$user = User::find(1);
$following = $user->matchFrom()
    ->outgoing('FOLLOWS', 'users')
    ->get();
// MATCH (n:users {id: 1})-[:FOLLOWS]->(target:users) RETURN target
// Result: Only users that THIS user follows

// Static traversal (match) - all nodes of type
$allFollowPatterns = User::match()
    ->outgoing('FOLLOWS', 'users')
    ->returning([Query::variable('n'), Query::variable('target')])
    ->get();
// MATCH (n:users)-[:FOLLOWS]->(target:users) RETURN n, target
// Result: ALL follow relationships in the graph
```

✅ **Use `matchFrom()`** when starting from a specific node
✅ **Use `match()`** when analyzing patterns across all nodes

---

## Path Finding

**Difficulty:** 🟡 Intermediate → 🔴 Advanced

Path finding algorithms discover connections between nodes.

### Shortest Path

Find the shortest path between two nodes:

```php
use WikibaseSolutions\CypherDSL\Query;

$userA = User::find(1);
$userB = User::find(50);

// Basic shortest path (any relationship type)
$path = $userA->matchFrom()
    ->shortestPath($userB)
    ->returning(Query::variable('path'))
    ->first();
// MATCH path = shortestPath((n:users {id: 1})-[*]-(target:users {id: 50}))
// RETURN path

// Specific relationship type
$socialPath = $userA->matchFrom()
    ->shortestPath($userB, 'FOLLOWS')
    ->returning(Query::variable('path'))
    ->first();
// MATCH path = shortestPath((n:users {id: 1})-[:FOLLOWS*]-(target:users {id: 50}))
// RETURN path

// With maximum depth
$path = $userA->matchFrom()
    ->shortestPath($userB, 'KNOWS', 5)
    ->returning(Query::variable('path'))
    ->first();
// MATCH path = shortestPath((n:users {id: 1})-[:KNOWS*..5]-(target:users {id: 50}))
// RETURN path

// Using node ID instead of model
$path = $userA->matchFrom()
    ->shortestPath(50, 'KNOWS')
    ->returning(Query::variable('path'))
    ->first();
```

**Method signature:**
```php
shortestPath(Model|int $target, ?string $relType = null, ?int $maxDepth = null): self
```

**Return value:**
The path object contains:
- `nodes()` - Array of nodes in the path
- `relationships()` - Array of relationships traversed
- `length()` - Number of hops

```php
$path = $userA->matchFrom()
    ->shortestPath($userB, 'KNOWS')
    ->returning(Query::variable('path'))
    ->first();

if ($path) {
    $length = $path->path->length ?? 0;
    echo "Connected via {$length} hops";
}
```

### All Paths

Find all paths between nodes up to a maximum depth:

```php
$userA = User::find(1);
$userB = User::find(10);

// Default max depth (5)
$paths = $userA->matchFrom()
    ->allPaths($userB)
    ->returning(Query::variable('path'))
    ->get();
// MATCH path = (n:users {id: 1})-[*..5]-(target:users {id: 10})
// RETURN path

// Specific relationship type
$socialPaths = $userA->matchFrom()
    ->allPaths($userB, 'FOLLOWS', 3)
    ->returning(Query::variable('path'))
    ->get();
// MATCH path = (n:users {id: 1})-[:FOLLOWS*..3]-(target:users {id: 10})
// RETURN path

// Analyze path diversity
$paths = $userA->matchFrom()
    ->allPaths($userB, 'KNOWS', 4)
    ->returning(Query::variable('path'))
    ->get();

echo "Found {$paths->count()} distinct paths";
```

**Method signature:**
```php
allPaths(Model|int $target, ?string $relType = null, int $maxDepth = 5): self
```

⚠️ **Performance Warning:** `allPaths()` can be expensive! Always set a reasonable `maxDepth` to avoid runaway queries.

### Variable-Length Patterns

For more control, use raw DSL patterns:

```php
use WikibaseSolutions\CypherDSL\Query;

// Friends within 2-3 hops
$connections = DB::connection('graph')->cypher()
    ->raw('MATCH', 'path = (start:users {id: ' . $user->id . '})-[:KNOWS*2..3]-(end:users)')
    ->returning(Query::rawExpression('DISTINCT end'))
    ->get();
// MATCH path = (start:users {id: 1})-[:KNOWS*2..3]-(end:users) RETURN DISTINCT end

// Direction-specific variable-length
$influences = DB::connection('graph')->cypher()
    ->raw('MATCH', '(start:users {id: ' . $user->id . '})-[:INFLUENCES*1..3]->(influenced:users)')
    ->returning(Query::variable('influenced'))
    ->get();
// MATCH (start:users {id: 1})-[:INFLUENCES*1..3]->(influenced:users) RETURN influenced
```

### Path Finding Performance

| Method | Best For | Performance | Max Depth |
|--------|----------|-------------|-----------|
| `shortestPath()` | Finding one connection | Fast | Unbounded |
| `allPaths()` | Network analysis | Slow | Set limit! |
| Variable-length | Specific hop counts | Medium | 2-4 hops |

✅ **Best Practices:**
- Always specify `maxDepth` for `allPaths()`
- Use `shortestPath()` when you only need one path
- Limit relationship types when possible
- Consider indexes on node properties for faster lookups

---

## Advanced DSL Features

**Difficulty:** 🔴 Advanced

### OPTIONAL MATCH

Match patterns that may or may not exist:

```php
use WikibaseSolutions\CypherDSL\Query;

// Users with optional address data
$results = DB::connection('graph')->cypher()
    ->match(Query::node('users')->named('u'))
    ->raw('OPTIONAL MATCH', '(u)-[:HAS_ADDRESS]->(a:addresses)')
    ->returning([
        'user' => Query::variable('u'),
        'address' => Query::variable('a')
    ])
    ->get();
// MATCH (u:users)
// OPTIONAL MATCH (u)-[:HAS_ADDRESS]->(a:addresses)
// RETURN u AS user, a AS address
// Note: address will be null if relationship doesn't exist
```

### WITH Clause

Chain query parts with intermediate results:

```php
// Find popular users, then their recent posts
$results = DB::connection('graph')->cypher()
    ->match(Query::node('users')->named('u'))
    ->where(Query::variable('u')->property('follower_count')->gt(Query::literal(1000)))
    ->raw('WITH', 'u')
    ->match(Query::variable('u')->relationshipTo(Query::node('posts')->named('p'), ['HAS_POST']))
    ->where(Query::variable('p')->property('created_at')->gt(Query::literal(now()->subWeek()->toDateTimeString())))
    ->returning([
        'user' => Query::variable('u'),
        'post' => Query::variable('p')
    ])
    ->get();
// MATCH (u:users)
// WHERE u.follower_count > 1000
// WITH u
// MATCH (u)-[:HAS_POST]->(p:posts)
// WHERE p.created_at > '2025-10-19 00:00:00'
// RETURN u AS user, p AS post
```

### UNWIND

Transform lists into rows:

```php
// Process array of IDs
$userIds = [1, 2, 3, 4, 5];

$results = DB::connection('graph')->cypher()
    ->raw('UNWIND', '$ids AS userId')
    ->withParameter('ids', $userIds)
    ->match(Query::node('users')->named('u')->withProperties(['id' => Query::parameter('userId')]))
    ->returning(Query::variable('u'))
    ->get();
// UNWIND $ids AS userId
// MATCH (u:users {id: userId})
// RETURN u

// Generate computed rows
$results = DB::connection('graph')->cypher()
    ->raw('UNWIND', 'range(1, 10) AS x')
    ->returning([
        'number' => Query::rawExpression('x'),
        'square' => Query::rawExpression('x * x')
    ])
    ->get();
// UNWIND range(1, 10) AS x
// RETURN x AS number, x * x AS square
```

### UNION

Combine results from multiple queries:

```php
// Get users and companies in one result set
$query1 = DB::connection('graph')->cypher()
    ->match(Query::node('users')->named('n'))
    ->returning([
        'name' => Query::variable('n')->property('name'),
        'type' => Query::literal('user')
    ])
    ->toCypher();

$query2 = DB::connection('graph')->cypher()
    ->match(Query::node('companies')->named('n'))
    ->returning([
        'name' => Query::variable('n')->property('name'),
        'type' => Query::literal('company')
    ])
    ->toCypher();

$combined = DB::connection('graph')->cypher(
    $query1 . ' UNION ' . $query2
);
// MATCH (n:users) RETURN n.name AS name, 'user' AS type
// UNION
// MATCH (n:companies) RETURN n.name AS name, 'company' AS type
```

### Aggregations

Combine with Neo4j aggregate functions:

```php
use WikibaseSolutions\CypherDSL\Functions\RawFunction;

// Count posts per user
$results = User::match()
    ->match(Query::variable('n')->relationshipTo(Query::node('posts')->named('p'), ['HAS_POST']))
    ->returning([
        'user' => Query::variable('n'),
        'post_count' => new RawFunction('COUNT', [Query::variable('p')])
    ])
    ->get();
// MATCH (n:users)-[:HAS_POST]->(p:posts)
// RETURN n AS user, COUNT(p) AS post_count

// Average age by status
$results = DB::connection('graph')->cypher()
    ->match(Query::node('users')->named('u'))
    ->returning([
        'status' => Query::variable('u')->property('status'),
        'avg_age' => Query::rawExpression('AVG(u.age)'),
        'total' => Query::rawExpression('COUNT(u)')
    ])
    ->get();
// MATCH (u:users)
// RETURN u.status AS status, AVG(u.age) AS avg_age, COUNT(u) AS total
```

### Subqueries (Neo4j 4.0+)

Execute subqueries within a main query:

```php
// Find users and their top 3 posts by views
$results = DB::connection('graph')->cypher()
    ->match(Query::node('users')->named('u'))
    ->raw('CALL', '{
        MATCH (u)-[:HAS_POST]->(p:posts)
        RETURN p
        ORDER BY p.views DESC
        LIMIT 3
    }')
    ->returning([
        'user' => Query::variable('u'),
        'top_posts' => Query::rawExpression('COLLECT(p)')
    ])
    ->get();
```

---

## Model Integration & Hydration

**Difficulty:** 🟡 Intermediate

### Automatic Model Hydration

Models are automatically hydrated with casts applied:

```php
class User extends GraphModel
{
    protected $casts = [
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'metadata' => 'array'
    ];
}

// Query returns proper models
$users = User::match()
    ->where(Query::variable('n')->property('is_active')->equals(Query::literal(true)))
    ->get();

foreach ($users as $user) {
    echo $user->is_active;   // bool(true), not int(1)
    echo $user->created_at;  // Carbon instance
    echo $user->metadata;    // array, not JSON string
}
```

✅ **Hydration includes:**
- Type casting via `$casts`
- DateTime conversion from Neo4j
- JSON parsing for array casts
- Accessor methods
- Model attributes and original state

### Mixed Result Hydration

When returning multiple node types:

```php
// Returns Collection<stdClass> with mixed data
$results = DB::connection('graph')->cypher()
    ->match(
        Query::node('users')->named('u')
            ->relationshipTo(Query::node('posts')->named('p'), ['HAS_POST'])
    )
    ->returning([
        'user' => Query::variable('u'),
        'post' => Query::variable('p')
    ])
    ->get();

foreach ($results as $row) {
    // $row is stdClass with user and post properties
    $userName = $row->user->name ?? $row->user['name'];
    $postTitle = $row->post->title ?? $row->post['title'];
}

// To hydrate specific models, query separately
$users = User::match()
    ->match(Query::variable('n')->relationshipTo(Query::node('posts')->named('p'), ['HAS_POST']))
    ->returning(Query::variable('n'))
    ->get();
// Returns Collection<User>
```

### Eager Loading Compatibility

DSL queries work alongside Eloquent relationships:

```php
// Query with DSL, then eager load
$users = User::match()
    ->where(Query::variable('n')->property('active')->equals(Query::literal(true)))
    ->get();

// Now eager load relationships (uses Eloquent)
$users->load('posts', 'friends');

// Or combine in one call (requires manual handling)
$users = User::match()
    ->where(Query::variable('n')->property('active')->equals(Query::literal(true)))
    ->get()
    ->each(fn($u) => $u->load('posts'));
```

---

## Macros & Extensibility

**Difficulty:** 🟡 Intermediate

### Creating Macros

Register reusable query patterns:

```php
use Look\EloquentCypher\Builders\Neo4jCypherDslBuilder;
use WikibaseSolutions\CypherDSL\Query;

// In AppServiceProvider::boot()
Neo4jCypherDslBuilder::macro('activeOnly', function () {
    return $this->where(
        Query::variable('n')->property('active')->equals(Query::literal(true))
    );
});

Neo4jCypherDslBuilder::macro('verifiedOnly', function () {
    return $this->where(
        Query::variable('n')->property('verified')->equals(Query::literal(true))
    );
});

// Usage
$users = User::match()
    ->activeOnly()
    ->verifiedOnly()
    ->get();
// MATCH (n:users) WHERE n.active = true AND n.verified = true RETURN n
```

### Parameterized Macros

Add flexibility with parameters:

```php
Neo4jCypherDslBuilder::macro('olderThan', function (int $age) {
    return $this->where(
        Query::variable('n')->property('age')->gt(Query::literal($age))
    );
});

Neo4jCypherDslBuilder::macro('withStatus', function (string $status) {
    return $this->where(
        Query::variable('n')->property('status')->equals(Query::literal($status))
    );
});

// Usage
$seniorActiveUsers = User::match()
    ->olderThan(65)
    ->withStatus('active')
    ->get();
```

### Complex Reusable Patterns

Build domain-specific query patterns:

```php
Neo4jCypherDslBuilder::macro('withRecentActivity', function (int $days = 7) {
    $cutoff = now()->subDays($days)->toDateTimeString();
    return $this->where(
        Query::variable('n')
            ->property('last_active_at')
            ->gte(Query::literal($cutoff))
    );
});

Neo4jCypherDslBuilder::macro('influencers', function (int $minFollowers = 1000) {
    return $this->where(
        Query::variable('n')
            ->property('follower_count')
            ->gte(Query::literal($minFollowers))
    );
});

// Combine macros
$topInfluencers = User::match()
    ->influencers(5000)
    ->withRecentActivity(30)
    ->get();
```

### Model-Specific Macros

Define macros on model classes:

```php
// In User model or service provider
User::macro('premiumUsers', function () {
    return static::match()
        ->where(Query::variable('n')->property('subscription')->equals(Query::literal('premium')))
        ->returning(Query::variable('n'));
});

// Usage
$premiumUsers = User::premiumUsers()->get();
```

---

## Performance & Best Practices

**Difficulty:** 🟡 Intermediate

### DSL Overhead

The DSL adds minimal performance cost:

- **Query building**: ~1-2ms (negligible)
- **Model hydration**: Same as raw Cypher
- **Total impact**: <1% for typical workloads

### When to Use DSL vs Raw Cypher

| Use DSL | Use Raw Cypher |
|---------|----------------|
| Complex multi-condition queries | Very simple one-liners |
| Queries built dynamically | Static migration scripts |
| Team projects (maintainability) | One-off admin tasks |
| When you need model hydration | When you need raw speed |
| Composable, reusable patterns | Prototype/exploration |

### Query Optimization Tips

✅ **Index property lookups:**
```php
// Ensure indexed properties
$users = User::match()
    ->where(Query::variable('n')->property('email')->equals(Query::literal('user@example.com')))
    ->get();
// Fast if email is indexed
```

✅ **Limit traversal depth:**
```php
// Good
$path = $user->matchFrom()->shortestPath($target, 'KNOWS', 5)->get();

// Bad - unbounded search
$path = $user->matchFrom()->shortestPath($target, 'KNOWS')->get();
```

✅ **Return only what you need:**
```php
// Good - specific properties
$names = User::match()
    ->returning(['name' => Query::variable('n')->property('name')])
    ->get();

// Less efficient - entire nodes
$users = User::match()->returning(Query::variable('n'))->get();
```

✅ **Use DISTINCT for uniqueness:**
```php
$unique = DB::connection('graph')->cypher()
    ->match(Query::node('users')->named('u'))
    ->returning(Query::rawExpression('DISTINCT u.city AS city'))
    ->get();
```

### Profiling Queries

Use Neo4j's EXPLAIN and PROFILE:

```php
$builder = User::match()
    ->where(Query::variable('n')->property('age')->gt(Query::literal(25)));

// Get query string
$cypher = $builder->toCypher();

// Profile in Neo4j Browser
// PROFILE MATCH (n:users) WHERE n.age > 25 RETURN n

// Or via raw query
$profile = DB::connection('graph')->cypher("PROFILE {$cypher}");
```

---

## Complete Real-World Examples

**Difficulty:** 🔴 Advanced

### Recommendation Engine

Find similar users based on shared interests:

```php
use WikibaseSolutions\CypherDSL\Query;

function recommendFriends(User $user, int $minSharedInterests = 3) {
    return $user->matchFrom()
        ->match(
            Query::variable('n')
                ->relationshipTo(Query::node('interests')->named('interest'), ['INTERESTED_IN'])
        )
        ->match(
            Query::node('users')->named('similar')
                ->relationshipTo(Query::variable('interest'), ['INTERESTED_IN'])
        )
        ->where(Query::variable('similar')->property('id')
            ->equals(Query::variable('n')->property('id'))->not())
        ->returning([
            'user' => Query::variable('similar'),
            'shared_interests' => Query::rawExpression('COUNT(DISTINCT interest)'),
            'interests' => Query::rawExpression('COLLECT(DISTINCT interest.name)')
        ])
        ->raw('HAVING', 'COUNT(DISTINCT interest) >= ' . $minSharedInterests)
        ->orderBy(Query::rawExpression('COUNT(DISTINCT interest)'), 'DESC')
        ->limit(Query::literal(10))
        ->get();
}

// Usage
$recommendations = recommendFriends($currentUser, 3);
```

### Social Network Analytics

Calculate user influence score:

```php
function calculateInfluenceScore(User $user) {
    $stats = DB::connection('graph')->cypher()
        ->match(Query::node('users')->named('u')->withProperties(['id' => $user->id]))
        ->raw('OPTIONAL MATCH', '(u)-[:FOLLOWS]->(following:users)')
        ->raw('OPTIONAL MATCH', '(follower:users)-[:FOLLOWS]->(u)')
        ->raw('OPTIONAL MATCH', '(u)-[:HAS_POST]->(post:posts)')
        ->returning([
            'following_count' => Query::rawExpression('COUNT(DISTINCT following)'),
            'follower_count' => Query::rawExpression('COUNT(DISTINCT follower)'),
            'post_count' => Query::rawExpression('COUNT(DISTINCT post)'),
            'avg_post_views' => Query::rawExpression('AVG(post.views)')
        ])
        ->first();

    // Calculate weighted score
    $score = ($stats->follower_count * 2)
        + ($stats->following_count * 0.5)
        + ($stats->post_count * 1.5)
        + (($stats->avg_post_views ?? 0) * 0.01);

    return $score;
}
```

### Fraud Detection

Identify suspicious patterns:

```php
function detectSuspiciousActivity() {
    // Find accounts with unusual relationship patterns
    return DB::connection('graph')->cypher()
        ->match(Query::node('users')->named('u'))
        ->raw('OPTIONAL MATCH', '(u)-[:FRIENDS_WITH]->(friend:users)')
        ->raw('OPTIONAL MATCH', '(u)-[:PURCHASED]->(product:products)')
        ->raw('WITH', 'u, COUNT(DISTINCT friend) AS friend_count, COUNT(DISTINCT product) AS purchase_count')
        ->where(
            Query::rawExpression('friend_count')->gt(Query::literal(100))
                ->and(Query::rawExpression('purchase_count')->equals(Query::literal(0)))
        )
        ->returning([
            'user' => Query::variable('u'),
            'friend_count' => Query::rawExpression('friend_count'),
            'account_age_days' => Query::rawExpression('duration.between(u.created_at, datetime()).days')
        ])
        ->get();
}

// Find circular payment patterns
function detectCircularPayments(int $maxHops = 5) {
    return DB::connection('graph')->cypher()
        ->raw('MATCH', 'path = (start:users)-[:PAID*2..' . $maxHops . ']->(start)')
        ->returning([
            'path' => Query::variable('path'),
            'total_amount' => Query::rawExpression('REDUCE(s = 0, r IN relationships(path) | s + r.amount)')
        ])
        ->orderBy(Query::rawExpression('total_amount'), 'DESC')
        ->get();
}
```

---

## API Reference

### Neo4jCypherDslBuilder Methods

| Method | Return | Description |
|--------|--------|-------------|
| `get()` | `Collection` | Execute and return all results |
| `first()` | `?Model\|?stdClass` | Execute and return first result or null |
| `count()` | `int` | Execute and return count of results |
| `toCypher()` | `string` | Get generated Cypher query string |
| `toSql()` | `string` | Alias for `toCypher()` |
| `dump()` | `self` | Output query and bindings, continue |
| `dd()` | `never` | Output query and bindings, exit |
| `withModel(string)` | `self` | Set model class for hydration |
| `withSourceNode(Model)` | `self` | Set source node for traversals |
| `withParameter(string, mixed)` | `self` | Add named parameter |
| `extractBindings()` | `array` | Get all parameter bindings |

### Graph Pattern Helper Methods

| Method | Parameters | Description |
|--------|------------|-------------|
| `outgoing()` | `string $type, ?string $label` | Traverse outgoing relationships |
| `incoming()` | `string $type, ?string $label` | Traverse incoming relationships |
| `bidirectional()` | `string $type, ?string $label` | Traverse in any direction |
| `shortestPath()` | `Model\|int $target, ?string $type, ?int $depth` | Find shortest path |
| `allPaths()` | `Model\|int $target, ?string $type, int $depth` | Find all paths up to depth |

### HasCypherDsl Trait Methods

| Method | Type | Description |
|--------|------|-------------|
| `match()` | Static | Start DSL query for all nodes of model type |
| `matchFrom()` | Instance | Start DSL query from this specific node |

### Query Object Helpers

| Method | Returns | Example |
|--------|---------|---------|
| `Query::node(?string $label)` | `Node` | `Query::node('users')` |
| `Query::variable(string $name)` | `Variable` | `Query::variable('n')` |
| `Query::parameter(string $name, mixed $value)` | `Parameter` | `Query::parameter('age', 25)` |
| `Query::literal(mixed $value)` | `Literal` | `Query::literal(true)` |
| `Query::rawExpression(string $cypher)` | `RawExpression` | `Query::rawExpression('COUNT(n)')` |

### Cypher Facade Methods

| Method | Returns | Description |
|--------|---------|-------------|
| `Cypher::query()` | `Neo4jCypherDslBuilder` | Start new DSL builder |
| `Cypher::node(?string)` | `Node` | Create node pattern helper |
| `Cypher::parameter(string)` | `Parameter` | Create parameter helper |
| `Cypher::relationship()` | `Path` | Create relationship helper |

### Proxied DSL Methods

All methods from `WikibaseSolutions\CypherDSL\Query` are available via `__call()`:

| Method | Description |
|--------|-------------|
| `match(...)` | Add MATCH clause |
| `where(...)` | Add WHERE condition |
| `returning(...)` | Add RETURN clause |
| `orderBy(...)` | Add ORDER BY clause |
| `limit(...)` | Add LIMIT clause |
| `skip(...)` | Add SKIP clause |
| `raw(string $clause, string $cypher)` | Add raw Cypher |

**Complete DSL documentation:** [php-cypher-dsl Wiki](https://github.com/neo4j-php/php-cypher-dsl/wiki)

---

## Summary

The Cypher DSL brings type-safe, Laravel-style query building to Neo4j:

✅ **Type Safety** - Catch errors during development, not production
✅ **Laravel Conventions** - Familiar methods like `get()`, `first()`, `count()`
✅ **Model Hydration** - Automatic model instantiation with casts
✅ **Graph Helpers** - Convenient traversal and path finding methods
✅ **Extensible** - Add custom patterns with macros
✅ **Backward Compatible** - Existing raw Cypher unchanged
✅ **Production Ready** - 87 tests (100% passing)

**Start simple, scale up:**
1. Basic queries with `User::match()->where()->get()`
2. Traversals with `$user->matchFrom()->outgoing()->get()`
3. Advanced patterns with path finding and aggregations
4. Reusable patterns with macros
5. Complex real-world solutions

**Next steps:**
- Explore the [test suite](/tests/Feature/CypherDsl*.php) for more examples
- Read the [DSL library docs](https://github.com/neo4j-php/php-cypher-dsl/wiki)
- Check the [integration plan](/specs/CYPHER_DSL_INTEGRATION_PLAN.md) for architecture details

Happy graph querying! 🚀
