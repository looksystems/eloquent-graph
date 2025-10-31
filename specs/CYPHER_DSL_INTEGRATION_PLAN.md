# Cypher DSL Integration Plan

**Status**: Planning
**Created**: 2025-10-26
**Target**: Phase 1.3 Feature

## Overview

Integrate `wikibase-solutions/php-cypher-dsl` into Eloquent Cypher to provide a fluent, Laravel-native query builder for advanced Cypher queries. This will give users the choice between raw Cypher strings and a type-safe, fluent builder for complex graph queries.

## Goals

1. **Full DSL Integration**: Wrap all DSL methods via magic proxy
2. **Laravel Conventions**: Familiar API (get(), first(), count(), dd())
3. **Facade Support**: `Cypher::query()` for convenient access
4. **Model Hydration**: Automatic hydration when querying via models
5. **Match Helpers**: Both static and instance `match()` methods
6. **Graph Patterns**: Shortcuts for common traversals and path finding
7. **Macro Support**: Extensible with custom patterns
8. **Backward Compatible**: Existing `cypher(string)` usage unchanged

## Core Features

### 1. Full DSL Method Wrapping

All `wikibase-solutions/php-cypher-dsl` methods proxied via `__call()`:

```php
use Look\EloquentCypher\Facades\Cypher;
use function WikibaseSolutions\CypherDSL\node;

$users = Cypher::query()
    ->match(node('User'))
    ->where(node('User')->property('age')->gt(25))
    ->returning(node('User'))
    ->get();
```

### 2. Facade Support

```php
use Look\EloquentCypher\Facades\Cypher;

// Start query
Cypher::query()->match(...)->get();

// Access DSL helper functions
Cypher::node('User');
Cypher::parameter('age');
Cypher::relationship('FOLLOWS');
```

### 3. Automatic Model Hydration

**When used on models** → Returns `Collection<Model>`
**When used on connection** → Returns `Collection<stdClass>`

```php
// Returns Collection<User>
User::match()->where('age', '>', 25)->get();

// Returns Collection<stdClass>
DB::connection('neo4j')->cypher()->match(...)->get();
```

### 4. Match Helpers

**Static Method**: General queries starting fresh
```php
User::match()
    ->where('age', '>', 25)
    ->returning('*')
    ->get(); // Collection<User>
```

**Instance Method**: Traversal from specific node
```php
$user = User::find(1);
$user->match()
    ->outgoing('FOLLOWS', 'User')
    ->where('active', true)
    ->get(); // Collection<User>
```

### 5. Graph Pattern Helpers

#### Relationship Traversal Shortcuts

```php
// Outgoing relationships
$user->match()
    ->outgoing('FOLLOWS', 'User')
    ->get();

// Incoming relationships
$post->match()
    ->incoming('HAS_POST', 'User')
    ->first();

// Bidirectional (any direction)
$user->match()
    ->bidirectional('FRIENDS', 'User')
    ->where('age', '>', 21)
    ->get();
```

#### Path Finding Helpers

```php
// Shortest path between nodes
User::find(1)->match()
    ->shortestPath(User::find(2), 'KNOWS')
    ->get();

// All paths with max depth
$user->match()
    ->allPaths($target, 'KNOWS', maxDepth: 5)
    ->get();
```

#### Pattern Matching Macros

```php
// Register macro in service provider
User::macro('activeWithPosts', function() {
    return $this->match()
        ->where('active', true)
        ->outgoing('HAS_POST', 'Post')
        ->where('published', true);
});

// Usage
User::activeWithPosts()->get();
$user->activeWithPosts()->count();
```

## Architecture

### File Structure

```
src/
├── Builders/
│   ├── Neo4jCypherDslBuilder.php      # Main DSL wrapper (~300 lines)
│   └── GraphPatternHelpers.php         # Mixin trait for helpers (~200 lines)
├── Facades/
│   └── Cypher.php                      # Laravel facade (~25 lines)
├── Traits/
│   └── HasCypherDsl.php                # Adds match() to models (~80 lines)
├── Support/
│   └── CypherMacroRegistry.php         # Macro management (~60 lines)
└── Providers/
    └── CypherDslServiceProvider.php    # Service provider (~50 lines)

tests/
└── Feature/
    ├── CypherDslIntegrationTest.php         # Core DSL tests (~200 lines)
    ├── CypherDslModelHydrationTest.php      # Hydration tests (~150 lines)
    ├── CypherDslGraphPatternsTest.php       # Pattern helpers (~200 lines)
    └── CypherDslMacrosTest.php              # Macro tests (~100 lines)
```

### Class Responsibilities

#### Neo4jCypherDslBuilder

**Primary wrapper class for DSL integration**

```php
namespace Look\EloquentCypher\Builders;

use Illuminate\Support\Collection;
use Illuminate\Support\Traits\Macroable;
use WikibaseSolutions\CypherDSL\Query;
use Look\EloquentCypher\Neo4jConnection;

class Neo4jCypherDslBuilder
{
    use GraphPatternHelpers, Macroable;

    protected Query $query;
    protected Neo4jConnection $connection;
    protected ?string $model = null;        // For auto-hydration
    protected ?Model $sourceNode = null;    // For instance match()

    public function __construct(Neo4jConnection $connection)
    {
        $this->connection = $connection;
        $this->query = \WikibaseSolutions\CypherDSL\query();
    }

    // ===== Laravel-style Execution =====

    public function get(): Collection
    {
        $cypher = $this->query->build();
        $bindings = $this->extractBindings();
        $results = $this->connection->select($cypher, $bindings);

        return $this->model
            ? $this->hydrateModels($results)
            : collect($results);
    }

    public function first(): ?Model|?\stdClass
    {
        return $this->get()->first();
    }

    public function count(): int
    {
        // Add COUNT to query and execute
        return (int) $this->get()->count();
    }

    // ===== Cypher String Retrieval =====

    public function toCypher(): string
    {
        return $this->query->build();
    }

    public function toSql(): string
    {
        return $this->toCypher(); // Laravel alias
    }

    // ===== Debug Helpers =====

    public function dd(): never
    {
        dd([
            'cypher' => $this->toCypher(),
            'bindings' => $this->extractBindings(),
        ]);
    }

    public function dump(): self
    {
        dump([
            'cypher' => $this->toCypher(),
            'bindings' => $this->extractBindings(),
        ]);

        return $this;
    }

    // ===== Configuration =====

    public function withModel(string $modelClass): self
    {
        $this->model = $modelClass;
        return $this;
    }

    public function withSourceNode(Model $node): self
    {
        $this->sourceNode = $node;
        return $this;
    }

    // ===== DSL Proxy =====

    public function __call(string $method, array $args)
    {
        $result = $this->query->$method(...$args);

        // Return $this for chaining if DSL returns Query
        if ($result instanceof Query) {
            $this->query = $result;
            return $this;
        }

        return $result;
    }

    // ===== Internal Helpers =====

    protected function hydrateModels(array $results): Collection
    {
        $model = new $this->model;

        return collect($results)->map(function($row) use ($model) {
            $attributes = $this->extractNodeAttributes($row);
            return $model->newFromBuilder($attributes);
        });
    }

    protected function extractBindings(): array
    {
        // Extract parameters from DSL query
        // DSL uses parameter() function - map to Laravel bindings
        return [];
    }

    protected function extractNodeAttributes(array $row): array
    {
        // Extract node properties from result row
        // Handle both n.property and whole node formats
        return $row;
    }
}
```

#### GraphPatternHelpers Trait

**Common graph traversal patterns**

```php
namespace Look\EloquentCypher\Builders;

use function WikibaseSolutions\CypherDSL\node;
use function WikibaseSolutions\CypherDSL\relationshipTo;
use function WikibaseSolutions\CypherDSL\relationshipFrom;
use function WikibaseSolutions\CypherDSL\relationship;
use function WikibaseSolutions\CypherDSL\variable;

trait GraphPatternHelpers
{
    // ===== Relationship Traversal =====

    public function outgoing(string $type, ?string $targetLabel = null): self
    {
        $rel = relationshipTo()->withType($type);
        $target = $targetLabel ? node($targetLabel) : node();

        $pattern = $this->getSourceNode()
            ->relationshipTo($target, $type);

        $this->query->match($pattern);

        return $this;
    }

    public function incoming(string $type, ?string $sourceLabel = null): self
    {
        $rel = relationshipFrom()->withType($type);
        $source = $sourceLabel ? node($sourceLabel) : node();

        $pattern = $source
            ->relationshipTo($this->getSourceNode(), $type);

        $this->query->match($pattern);

        return $this;
    }

    public function bidirectional(string $type, ?string $label = null): self
    {
        $rel = relationship()->withType($type);
        $other = $label ? node($label) : node();

        $pattern = $this->getSourceNode()
            ->relationship($rel, $other);

        $this->query->match($pattern);

        return $this;
    }

    // ===== Path Finding =====

    public function shortestPath(
        Model|int $target,
        ?string $relType = null,
        ?int $maxDepth = null
    ): self
    {
        $source = $this->getSourceNode();
        $targetNode = $this->resolveTargetNode($target);

        // Use DSL's shortestPath functionality
        $pathVar = variable('p');

        $pattern = shortestPath(
            $source,
            $targetNode,
            $pathVar,
            $relType,
            $maxDepth
        );

        $this->query->match($pattern);

        return $this;
    }

    public function allPaths(
        Model|int $target,
        ?string $relType = null,
        int $maxDepth = 5
    ): self
    {
        $source = $this->getSourceNode();
        $targetNode = $this->resolveTargetNode($target);

        // All paths pattern
        $pathVar = variable('p');

        $pattern = allPaths(
            $source,
            $targetNode,
            $pathVar,
            $relType,
            $maxDepth
        );

        $this->query->match($pattern);

        return $this;
    }

    // ===== Helper Methods =====

    protected function getSourceNode()
    {
        if ($this->sourceNode) {
            return node($this->sourceNode->getTable())
                ->withProperties(['id' => $this->sourceNode->getKey()]);
        }

        if ($this->model) {
            $instance = new $this->model;
            return node($instance->getTable());
        }

        return node();
    }

    protected function resolveTargetNode($target)
    {
        if ($target instanceof Model) {
            return node($target->getTable())
                ->withProperties(['id' => $target->getKey()]);
        }

        if ($this->model) {
            $instance = new $this->model;
            return node($instance->getTable())
                ->withProperties(['id' => $target]);
        }

        return node()->withProperties(['id' => $target]);
    }
}
```

#### HasCypherDsl Trait

**Add match() methods to Neo4JModel**

```php
namespace Look\EloquentCypher\Traits;

use Look\EloquentCypher\Builders\Neo4jCypherDslBuilder;
use function WikibaseSolutions\CypherDSL\node;

trait HasCypherDsl
{
    /**
     * Start a new DSL query for this model (static).
     */
    public static function match(): Neo4jCypherDslBuilder
    {
        $instance = new static;

        return $instance->getConnection()
            ->cypher()
            ->withModel(static::class)
            ->match(node($instance->getTable()));
    }

    /**
     * Start a DSL query from this specific node instance.
     */
    public function matchFrom(): Neo4jCypherDslBuilder
    {
        return $this->getConnection()
            ->cypher()
            ->withModel(static::class)
            ->withSourceNode($this)
            ->match(
                node($this->getTable())
                    ->withProperties([$this->getKeyName() => $this->getKey()])
            );
    }
}
```

#### Cypher Facade

```php
namespace Look\EloquentCypher\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Look\EloquentCypher\Builders\Neo4jCypherDslBuilder query()
 * @method static \WikibaseSolutions\CypherDSL\Types\StructuralTypes\Node node(?string $label = null)
 * @method static \WikibaseSolutions\CypherDSL\Query\Parameter parameter(?string $name = null)
 * @method static \WikibaseSolutions\CypherDSL\Types\StructuralTypes\Relationship relationship()
 *
 * @see \Look\EloquentCypher\Support\CypherDslFactory
 */
class Cypher extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'neo4j.cypher.dsl';
    }
}
```

#### CypherDslServiceProvider

```php
namespace Look\EloquentCypher\Providers;

use Illuminate\Support\ServiceProvider;
use Look\EloquentCypher\Support\CypherDslFactory;

class CypherDslServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->singleton('neo4j.cypher.dsl', function ($app) {
            return new CypherDslFactory($app['db']);
        });
    }
}
```

### Modified Files

#### Neo4jConnection

Update the `cypher()` method to support both string and builder modes:

```php
/**
 * Execute a raw Cypher query or return DSL builder.
 *
 * @param string|null $query Cypher query string, or null to get DSL builder
 * @param array $bindings Query bindings
 * @return mixed Results array or Neo4jCypherDslBuilder
 */
public function cypher($query = null, $bindings = [])
{
    // New: No args = return DSL builder
    if ($query === null) {
        return new Neo4jCypherDslBuilder($this);
    }

    // Existing: String query = execute
    return $this->select($query, $bindings);
}
```

#### Neo4JModel

Add the `HasCypherDsl` trait:

```php
class Neo4JModel extends Model
{
    use HasCypherDsl;
    // ... existing code
}
```

#### composer.json

Add the DSL package dependency:

```json
{
    "require": {
        "php": "^8.0|^8.1|^8.2|^8.3|^8.4",
        "illuminate/database": "^10.0|^11.0|^12.0",
        "laudis/neo4j-php-client": "^3.4",
        "wikibase-solutions/php-cypher-dsl": "^3.0"
    }
}
```

## Usage Examples

### Basic DSL Query

```php
use Look\EloquentCypher\Facades\Cypher;
use function WikibaseSolutions\CypherDSL\node;

// Build and execute
$users = Cypher::query()
    ->match(node('User'))
    ->where(node('User')->property('age')->gt(25))
    ->returning(node('User'))
    ->get();

// Just get the Cypher string
$cypher = Cypher::query()
    ->match(node('User'))
    ->where(node('User')->property('status')->equals('active'))
    ->toCypher();
```

### Model Static Match

```php
// Simple query
$activeUsers = User::match()
    ->where('status', 'active')
    ->get(); // Collection<User>

// Complex conditions
$users = User::match()
    ->where(node('User')->property('age')->gte(18))
    ->andWhere(node('User')->property('verified')->isTrue())
    ->returning(node('User'))
    ->get();
```

### Instance Traversal

```php
$user = User::find(1);

// Find who this user follows
$following = $user->matchFrom()
    ->outgoing('FOLLOWS', 'User')
    ->where('active', true)
    ->get();

// Find followers
$followers = $user->matchFrom()
    ->incoming('FOLLOWS', 'User')
    ->get();

// Mutual friends
$friends = $user->matchFrom()
    ->bidirectional('FRIENDS', 'User')
    ->where('age', '>', 21)
    ->get();
```

### Path Finding

```php
// Shortest path between two users
$path = User::find(1)->matchFrom()
    ->shortestPath(User::find(50), 'KNOWS')
    ->get();

// All paths with max depth
$allPaths = $user->matchFrom()
    ->allPaths($targetUser, 'KNOWS', maxDepth: 4)
    ->get();
```

### Macros

```php
// Register in AppServiceProvider
use Look\EloquentCypher\Facades\Cypher;

public function boot()
{
    User::macro('activeWithPosts', function() {
        return $this->match()
            ->where('active', true)
            ->outgoing('HAS_POST', 'Post')
            ->where('published', true);
    });

    User::macro('influencers', function(int $minFollowers = 1000) {
        return $this->match()
            ->withCount('incoming:FOLLOWS as follower_count')
            ->having('follower_count', '>=', $minFollowers);
    });
}

// Usage
$results = User::activeWithPosts()->get();
$influencers = User::influencers(5000)->get();
```

### Debugging

```php
// Dump query and continue
User::match()
    ->where('age', '>', 25)
    ->dump()  // Shows Cypher + bindings
    ->get();

// Dump and die
User::match()
    ->where('age', '>', 25)
    ->dd();  // Shows and exits
```

### Backward Compatibility

```php
// Old way still works
DB::connection('neo4j')->cypher('MATCH (n:User) RETURN n', []);

// New way
DB::connection('neo4j')->cypher()
    ->match(node('User'))
    ->returning(node('User'))
    ->get();
```

## Testing Strategy

### Test Coverage (~80-100 new tests)

#### CypherDslIntegrationTest (~25 tests)

- DSL method proxying via __call
- toCypher() returns valid Cypher
- toSql() alias works
- get() executes and returns Collection
- first() returns single result
- count() returns integer
- Backward compatibility with string queries
- dd() and dump() helpers
- Parameter extraction and binding
- Complex query building

#### CypherDslModelHydrationTest (~20 tests)

- Model::match() returns Collection<Model>
- Connection cypher() returns Collection<stdClass>
- Hydration with casts applied
- Hydration with relationships
- Null handling
- Empty result sets
- Custom attributes preserved

#### CypherDslGraphPatternsTest (~30 tests)

- outgoing() basic traversal
- incoming() basic traversal
- bidirectional() traversal
- outgoing() with label filtering
- Chained traversals (multi-hop)
- shortestPath() between nodes
- shortestPath() with relationship type
- shortestPath() with max depth
- allPaths() basic
- allPaths() with depth limit
- Combined patterns (outgoing + where)
- Instance vs static match()

#### CypherDslMacrosTest (~15 tests)

- Macro registration
- Macro execution
- Macro with parameters
- Macro chaining
- Macro returning DSL builder
- Static macro on model
- Instance macro
- Macro with relationships
- Multiple macros composition

## Implementation Phases

### Phase 1: Core DSL Wrapper (Foundation)

**Tasks**:
1. Add `wikibase-solutions/php-cypher-dsl` to composer
2. Create `Neo4jCypherDslBuilder` class
3. Implement `__call()` proxy
4. Implement execution methods (get, first, count)
5. Implement toCypher/toSql
6. Update `Neo4jConnection::cypher()`
7. Write integration tests

**Deliverable**: Basic DSL queries work via connection

### Phase 2: Model Integration

**Tasks**:
1. Create `HasCypherDsl` trait
2. Add static `match()` method
3. Add instance `matchFrom()` method
4. Implement model hydration logic
5. Add trait to `Neo4JModel`
6. Write hydration tests

**Deliverable**: Model::match() returns hydrated models

### Phase 3: Graph Pattern Helpers

**Tasks**:
1. Create `GraphPatternHelpers` trait
2. Implement outgoing/incoming/bidirectional
3. Implement shortestPath/allPaths
4. Add helper utilities
5. Write pattern tests

**Deliverable**: Convenient graph traversal methods

### Phase 4: Facade & Macros

**Tasks**:
1. Create `Cypher` facade
2. Create `CypherDslServiceProvider`
3. Implement macro support (Macroable trait)
4. Register service provider
5. Write facade and macro tests

**Deliverable**: Full feature set complete

### Phase 5: Documentation

**Tasks**:
1. Update CLAUDE.md with DSL feature
2. Update README.md with examples
3. Create DSL usage guide
4. Add to DOCUMENTATION.md
5. Update COMPATIBILITY_MATRIX.md

**Deliverable**: Complete documentation

## Success Metrics

- **All existing tests pass**: Backward compatibility maintained
- **80-100 new tests added**: Comprehensive DSL coverage
- **Zero breaking changes**: Existing code unchanged
- **Performance neutral**: DSL overhead minimal
- **Type safety**: IDE autocomplete works
- **Developer experience**: Easy to use, well documented

## Notes for Implementation

### TDD Approach

1. Write failing test for feature
2. Implement minimal code to pass
3. Refactor for quality
4. Never modify tests to make them pass

### DSL Package Notes

- Package namespace: `WikibaseSolutions\CypherDSL\`
- Main functions: `query()`, `node()`, `parameter()`, `relationship()`
- Query finalization: `->build()` returns Cypher string
- The package does NOT execute queries - just builds strings
- Parameter handling via `parameter()` function

### Laravel Integration Patterns

- Use `Macroable` trait for extensibility
- Follow Collection return type conventions
- Support `dd()` and `dump()` for debugging
- Facade should feel like `DB::` or `Cache::`
- Use service provider for registration

### Potential Challenges

1. **Parameter binding**: DSL has its own parameter system, need to map to Laravel bindings
2. **Result parsing**: DSL doesn't know about Neo4j responses, need to handle extraction
3. **Complex patterns**: Some DSL patterns may need special handling for hydration
4. **Performance**: Additional abstraction layer - keep it minimal
5. **Type complexity**: DSL is type-safe but verbose - provide convenience helpers

## Future Enhancements (Post-MVP)

- **Query caching**: Cache compiled Cypher strings
- **Query builder macros**: Global pattern registry
- **Relationship eager loading**: `->with()` using DSL
- **Subquery support**: Nested DSL builders
- **Union queries**: Combine multiple DSL queries
- **Aggregation helpers**: Wrapped aggregate functions
- **Transaction support**: DSL within transactions

## References

- DSL Package: https://github.com/neo4j-php/php-cypher-dsl
- DSL Documentation: https://github.com/neo4j-php/php-cypher-dsl/wiki
- Packagist: https://packagist.org/packages/wikibase-solutions/php-cypher-dsl
