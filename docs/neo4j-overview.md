# Neo4j Feature Overview

Eloquent Cypher brings Neo4j's graph database power to Laravel with familiar Eloquent syntax. This guide helps you navigate Neo4j-specific features and choose the right tool for your use case.

---

## Introduction

Neo4j offers unique capabilities beyond traditional SQL databases:

**Graph-Native Features**:
- **Multi-Label Nodes**: Tag nodes with multiple types simultaneously for rich categorization
- **Property Graphs**: Store data directly on relationships, not just in join tables
- **Path Finding**: Built-in algorithms for shortest paths, degrees of separation, and network analysis
- **Pattern Matching**: Express complex graph queries with intuitive visual patterns

**Why Use Neo4j-Specific Features?**

Most Laravel developers can build 80% of their app using familiar Eloquent patterns. Neo4j features unlock the remaining 20% - complex graph queries, multi-hop traversals, and relationship analytics that would be painful in SQL.

**✅ When to Use Neo4j Features**:
- Social networks (friends-of-friends, recommendations)
- Organizational hierarchies (reporting chains, permissions)
- Network analysis (shortest paths, influence mapping)
- Multi-dimensional categorization (products with overlapping types)
- Relationship-heavy queries (who bought what with whom)

**⚠️ When to Stick with Eloquent**:
- Simple CRUD operations
- Standard where/orderBy queries
- Eager/lazy loading relationships
- Single-hop relationship queries

This overview helps you quickly identify which Neo4j feature solves your problem, then links to detailed guides for implementation.

---

## Feature Comparison Table

| Feature | Best For | Performance | Complexity | Laravel-Like? |
|---------|----------|-------------|------------|---------------|
| **Multi-Label Nodes** | Categorization, type hierarchies | ⚡ Fast (indexed) | 🟢 Easy | ✅ Yes |
| **Neo4j Aggregates** | Statistical analysis, percentiles | ⚡ Fast | 🟢 Easy | ✅ Yes |
| **Cypher DSL** | Complex traversals, path finding | ⚡⚡ Very fast | 🟡 Moderate | ⚠️ Different |
| **Schema Introspection** | Dynamic UIs, documentation | ⚡ Fast | 🟢 Easy | ✅ Yes |
| **Arrays & JSON** | Lists, nested data structures | ⚡ Fast (flat arrays) | 🟢 Easy | ✅ Yes |

**Performance Notes**:
- Multi-label queries benefit from label-specific indexes
- Aggregates run efficiently on large datasets
- Cypher DSL excels at multi-hop traversals (faster than N+1 queries)
- Array operations use native Neo4j LISTs when possible

**Complexity Guide**:
- 🟢 **Easy**: Drop-in replacement for Eloquent patterns
- 🟡 **Moderate**: New concepts, but well-documented
- 🔴 **Advanced**: Requires graph thinking (none in this list!)

---

## Quick Decision Tree

**Not sure which feature you need?** Follow this decision tree:

### Do you need to categorize nodes with multiple types?
→ **Use Multi-Label Nodes** ([guide](multi-label-nodes.md))

```php
// Example: User who is also an Employee and Manager
class User extends GraphModel {
    protected $labels = ['Person', 'Employee', 'Manager'];
}
```

### Do you need statistical analysis beyond count/sum/avg?
→ **Use Neo4j Aggregates** ([guide](neo4j-aggregates.md))

```php
// Example: p95 latency, standard deviation, collect all tags
$p95 = Request::percentileDisc('response_time', 0.95);
$stdDev = Product::stdev('price');
$tags = Post::collect('tags');
```

### Do you need to traverse multiple relationship hops?
→ **Use Cypher DSL** ([guide](cypher-dsl.md))

```php
// Example: Friends-of-friends, shortest path, recommendations
$fof = $user->matchFrom()
    ->outgoing('FOLLOWS', 'friend')
    ->outgoing('FOLLOWS', 'fof')
    ->get();
```

### Do you need to query your database schema programmatically?
→ **Use Schema Introspection** ([guide](schema-introspection.md))

```php
// Example: Build dynamic UIs, generate docs
$labels = Neo4jSchema::getAllLabels();
$constraints = Neo4jSchema::getConstraints();
```

### Do you need to store lists or nested JSON?
→ **Use Arrays & JSON** ([guide](arrays-and-json.md))

```php
// Example: Tags, settings, preferences
$user->tags = ['php', 'laravel'];  // Flat array → native LIST
$user->settings = ['theme' => 'dark'];  // Nested → JSON
```

---

## Feature Summaries

### Multi-Label Nodes

**What**: Tag nodes with multiple labels for rich categorization and query optimization.

**Key Capabilities**:
- Define multiple labels per model (`$labels` property)
- Query by specific label combinations (`withLabels()`)
- Check labels at runtime (`hasLabel()`, `getLabels()`)
- Automatic label preservation during CRUD operations

**Quick Example**:
```php
class User extends GraphModel {
    protected $labels = ['Person', 'Individual'];
    // Result: :users:Person:Individual
}

// Query only active employees
$users = User::withLabels(['users', 'Person', 'Employee'])->get();
```

**Use Cases**:
- ✅ Type hierarchies (Animal → Mammal → Dog)
- ✅ Multi-dimensional categorization (Product → Electronics → Smartphone)
- ✅ Query optimization (indexes on label combinations)

**⚠️ Note**: Labels are preserved automatically during updates - no special handling required.

📖 **Full Guide**: [Multi-Label Nodes](multi-label-nodes.md)

---

### Neo4j Aggregates

**What**: Statistical functions beyond standard SQL aggregates (count, sum, avg, min, max).

**Key Functions**:
- `percentileDisc()` - Discrete percentile (actual dataset value)
- `percentileCont()` - Continuous percentile (interpolated value)
- `stdev()` - Sample standard deviation
- `stdevp()` - Population standard deviation
- `collect()` - Aggregate values into an array

**Quick Example**:
```php
// p95 response time (SLA monitoring)
$p95 = Request::percentileDisc('response_time', 0.95);

// Standard deviation of prices
$stdDev = Product::stdev('price');

// Collect all tags
$allTags = Post::collect('tags');
```

**Use Cases**:
- ✅ SLA monitoring (p95, p99 latency)
- ✅ Statistical analysis (standard deviation, quartiles)
- ✅ Data aggregation (collect related values)

**⚠️ Note**: Percentile values must be between 0.0 and 1.0 (e.g., 0.95 = 95th percentile).

📖 **Full Guide**: [Neo4j Aggregates](neo4j-aggregates.md)

---

### Cypher DSL

**What**: Type-safe graph pattern matching for complex traversals and path finding.

**Key Capabilities**:
- Static match on models (`User::match()`)
- Instance-based traversal (`$user->matchFrom()`)
- Directional traversal (`outgoing()`, `incoming()`, `bidirectional()`)
- Path finding (`shortestPath()`, `allPaths()`)

**Quick Example**:
```php
// Friends-of-friends
$fof = $user->matchFrom()
    ->outgoing('FRIENDS_WITH', 'friend')
    ->outgoing('FRIENDS_WITH', 'fof')
    ->where('fof.id', '<>', $user->id)
    ->get();

// Shortest path between users
$path = $user1->matchFrom()
    ->shortestPath('KNOWS', $user2->id, 'target', 1, 6)
    ->first();
```

**Use Cases**:
- ✅ Multi-hop traversals (friends-of-friends)
- ✅ Recommendation engines
- ✅ Degrees of separation
- ✅ Network analysis

**⚠️ Important**: DSL queries return raw Cypher results (not Eloquent models). Access via array keys.

📖 **Full Guide**: [Cypher DSL](cypher-dsl.md)

---

### Schema Introspection

**What**: Programmatically inspect your Neo4j database schema.

**Key Capabilities**:
- Get labels, relationships, constraints, indexes
- Check if schema elements exist
- CLI commands for quick inspection
- Export schema as JSON

**Quick Example**:
```php
// Programmatic API
$labels = Neo4jSchema::getAllLabels();
$relationships = Neo4jSchema::getAllRelationshipTypes();
$constraints = Neo4jSchema::getConstraints();

// Check existence
if (Neo4jSchema::hasLabel('users')) {
    // Label exists
}
```

**CLI Commands**:
```bash
php artisan neo4j:schema              # Full overview
php artisan neo4j:schema:labels       # List all labels
php artisan neo4j:schema:relationships --count  # Relationships with counts
php artisan neo4j:schema:export > schema.json   # Export schema
```

**Use Cases**:
- ✅ Dynamic UI generation
- ✅ Documentation generation
- ✅ Schema versioning
- ✅ Debugging and validation

📖 **Full Guide**: [Schema Introspection](schema-introspection.md)

---

### Arrays & JSON

**What**: Hybrid storage strategy for arrays and nested data structures.

**Storage Strategy**:
- **Flat arrays** → Native Neo4j LISTs (fast, indexable)
- **Nested arrays** → JSON strings (compatible, flexible)
- Automatic detection and conversion

**Quick Example**:
```php
// Flat array (native LIST)
$user->tags = ['php', 'laravel', 'neo4j'];

// Nested array (JSON string)
$user->settings = [
    'notifications' => ['email' => true, 'sms' => false],
    'theme' => 'dark'
];

// Query nested JSON
$users = User::whereJsonContains('settings->notifications->email', true)->get();

// Query array length
$users = User::whereJsonLength('tags', '>', 5)->get();
```

**Key Methods**:
- `whereJsonContains()` - Query nested structures
- `whereJsonLength()` - Check array/object size
- Optional APOC enhancement for faster JSON queries

**Use Cases**:
- ✅ Tag lists
- ✅ Configuration settings
- ✅ User preferences
- ✅ Metadata storage

**⚠️ Best Practice**: Prefer flat arrays when possible for better performance and indexability.

📖 **Full Guide**: [Arrays & JSON](arrays-and-json.md)

---

## Getting Started

**Recommended Learning Path**:

1. **Start with Multi-Label Nodes** ([guide](multi-label-nodes.md))
   - Easiest Neo4j-specific feature
   - Familiar Eloquent patterns
   - Immediate benefits for categorization

2. **Add Neo4j Aggregates** ([guide](neo4j-aggregates.md))
   - Drop-in replacements for Eloquent methods
   - Powerful statistical functions
   - Works with existing queries

3. **Explore Arrays & JSON** ([guide](arrays-and-json.md))
   - Understand Neo4j storage patterns
   - Learn when to use flat vs. nested
   - Master JSON query methods

4. **Master Cypher DSL** ([guide](cypher-dsl.md))
   - Most powerful but different paradigm
   - Essential for complex graph queries
   - Unlocks full Neo4j capabilities

5. **Use Schema Introspection** ([guide](schema-introspection.md))
   - Build dynamic features
   - Debug and document your schema
   - Validate database state

**Time Investment**:
- Multi-Label Nodes: 15 minutes
- Neo4j Aggregates: 10 minutes
- Arrays & JSON: 20 minutes
- Cypher DSL: 45 minutes
- Schema Introspection: 15 minutes

**Total**: ~2 hours to master all Neo4j-specific features

---

## Next Steps

**Detailed Feature Guides**:
- 📖 [Multi-Label Nodes](multi-label-nodes.md) - Comprehensive multi-label documentation
- 📖 [Neo4j Aggregates](neo4j-aggregates.md) - Statistical functions reference
- 📖 [Cypher DSL](cypher-dsl.md) - Graph traversal patterns and examples
- 📖 [Schema Introspection](schema-introspection.md) - Programmatic schema access
- 📖 [Arrays & JSON](arrays-and-json.md) - Data structure storage strategies

**Related Documentation**:
- 📖 [Relationships](relationships.md) - Graph relationships and edge properties
- 📖 [Querying](querying.md) - Advanced query patterns
- 📖 [Performance](performance.md) - Optimization and best practices

**Need Help?**
- Review [Quick Reference](quick-reference.md) for syntax reminders
- Check [Troubleshooting](troubleshooting.md) for common issues

---

**Ready to dive deeper?** Pick a feature guide above and start building graph-powered Laravel applications.
