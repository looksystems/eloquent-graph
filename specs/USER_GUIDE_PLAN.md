# User Guide Planning Document

**Created**: 2025-10-26
**Status**: Planning Phase
**Target Audience**: Laravel developers familiar with Eloquent

## Overview

This document outlines the structure and content plan for a comprehensive, multi-file user guide for Eloquent Cypher. The guide will help Laravel developers quickly get up to speed by highlighting similarities and differences with standard Eloquent.

## Core Principles

### Self-Contained
- Each file includes ALL necessary information for its topic
- Complete, runnable code examples
- No external references to specs/ folder files
- Configuration and setup shown inline where relevant

### Navigation
- ✅ Cross-link within docs/ folder
- ❌ Don't reference specs/, README.md, or other external files
- Each file ends with "Next Steps" linking to related docs/ files

### Audience Focus
- Written for Laravel developers who know Eloquent
- Constant comparisons: Eloquent → Eloquent Cypher
- Highlight similarities first (reassurance)
- Then explain differences (understanding)

### Writing Style
- **✅ Same**: Callout for identical Eloquent behavior
- **⚠️ Different**: Callout for Neo4j-specific changes
- Side-by-side code comparisons
- Practical, runnable examples
- Minimal prose, maximum code

## File Structure

```
docs/
├── index.md                 # Overview & navigation hub (~300 lines)
├── getting-started.md       # Installation & first model (~400 lines)
├── models-and-crud.md       # Models & CRUD operations (~500 lines)
├── relationships.md         # All relationship types (~700 lines)
├── querying.md             # Query builder reference (~600 lines)
├── neo4j-features.md       # Neo4j-specific features (~500 lines)
├── performance.md          # Performance & optimization (~400 lines)
├── migration-guide.md      # Migrating from SQL (~500 lines)
├── troubleshooting.md      # Common issues & solutions (~350 lines)
└── quick-reference.md      # Cheat sheets & tables (~400 lines)
```

**Total**: ~4,650 lines across 10 files

---

## Detailed File Plans

### 1. index.md (~300 lines)

**Purpose**: Central hub with complete overview and navigation

**Sections**:
1. **Welcome** (50 lines)
   - What is Eloquent Cypher?
   - Philosophy: 100% Eloquent compatibility
   - Quick "it just works" example

2. **Eloquent ↔ Neo4j Concepts** (80 lines)
   - Complete comparison table:
     - Table → Label
     - Row → Node
     - Column → Property
     - Join → Relationship/Edge
     - Foreign Key → Relationship (configurable)
     - Index → Index/Constraint
     - Schema → Graph Schema

3. **When to Use This Package** (40 lines)
   - Ideal use cases
   - When to choose Neo4j + Eloquent
   - What you gain vs traditional SQL

4. **Quick Example** (60 lines)
   - Side-by-side: Standard Eloquent vs Eloquent Cypher
   - Show they're identical
   - Build confidence

5. **Guide Navigation** (70 lines)
   - Brief description of each guide
   - Recommended reading order
   - Jump-to links for experienced users

**Next Steps**: Link to getting-started.md for new users, or jump to specific guides

---

### 2. getting-started.md (~400 lines)

**Purpose**: Complete installation and setup guide

**Sections**:
1. **Prerequisites** (30 lines)
   - PHP 8.0+, Laravel 10-12, Composer
   - Docker recommended for Neo4j

2. **Installation** (60 lines)
   - `composer require` command
   - Service provider registration
   - Complete, copy-paste ready

3. **Neo4j Setup** (100 lines)
   - Docker Compose setup (full YAML if needed)
   - Docker run command
   - Neo4j Browser access
   - Verification steps

4. **Configuration** (80 lines)
   - Complete `.env` example
   - Full `config/database.php` connection array
   - Explain each config option
   - Performance settings explained

5. **Your First Model** (80 lines)
   - Create User model
   - Side-by-side with standard Eloquent Model
   - Highlight what changed (base class)
   - What stayed the same (everything else)

6. **First Queries** (50 lines)
   - Create a user
   - Find, update, delete
   - Show it works identically
   - Build confidence

**Next Steps**: Link to models-and-crud.md for deep dive, relationships.md for connecting data

---

### 3. models-and-crud.md (~500 lines)

**Purpose**: Complete guide to models and CRUD operations

**Sections**:
1. **Creating Models** (80 lines)
   - Neo4JModel base class
   - ✅ Same: $fillable, $guarded, $hidden, $casts
   - ⚠️ Different: $incrementing = false, $keyType = 'int'
   - ⚠️ Different: $connection = 'neo4j'
   - Side-by-side comparison

2. **CRUD Operations** (120 lines)
   - **Create**: create(), save(), firstOrCreate(), updateOrCreate()
   - **Read**: find(), findOrFail(), all(), first()
   - **Update**: update(), save(), fill()
   - **Delete**: delete(), destroy()
   - All with examples showing identical Eloquent API

3. **Timestamps** (40 lines)
   - ✅ Same: created_at, updated_at automatic
   - ✅ Same: $timestamps property
   - ✅ Same: touch() method
   - Example showing it works

4. **Attribute Casting** (80 lines)
   - ✅ Same: All cast types work (int, string, bool, array, json, datetime, etc.)
   - Examples of each type
   - Custom casts work identically

5. **Mutators & Accessors** (60 lines)
   - ✅ Same: getAttribute, setAttribute patterns
   - ✅ Same: Modern attribute classes
   - Example showing identical usage

6. **Mass Assignment** (40 lines)
   - ✅ Same: $fillable, $guarded
   - ✅ Same: Protection against mass assignment
   - Example showing it works

7. **Soft Deletes** (80 lines)
   - ✅ Same: Use Neo4jSoftDeletes trait (instead of SoftDeletes)
   - ✅ Same: deleted_at column, trash methods
   - Complete example with restore(), forceDelete()
   - Query scopes (withTrashed, onlyTrashed)

**Next Steps**: Link to relationships.md for connecting models, querying.md for advanced queries

---

### 4. relationships.md (~700 lines)

**Purpose**: Complete guide to all relationship types

**Sections**:
1. **Overview** (60 lines)
   - All Eloquent relationship types supported
   - Key decision: Foreign keys vs Native edges
   - Quick comparison table

2. **The Storage Decision** (100 lines)
   - **Foreign Key Mode**: Traditional (like SQL)
     - Stores relationship as property on child node
     - 100% Eloquent compatible
     - Fast with indexes
   - **Native Edge Mode**: Graph relationships
     - Real Neo4j edges/relationships
     - Graph traversal benefits
     - Edge properties (pivot data)
   - **Hybrid Mode**: Both (recommended)
     - Edge exists AND property stored
     - Best of both worlds
   - When to choose each

3. **HasMany** (80 lines)
   - Standard foreign key example
   - Native edge example with useNativeEdges()
   - Eager loading
   - ✅ Same: All Eloquent methods work

4. **HasOne** (60 lines)
   - Standard example
   - Native edge option
   - ✅ Same: Identical to Eloquent

5. **BelongsTo** (60 lines)
   - Standard example
   - Native edge option
   - ✅ Same: Identical to Eloquent

6. **BelongsToMany** (100 lines)
   - Standard pivot table approach
   - Native edge with properties
   - Pivot operations (attach, detach, sync)
   - withPivot, using timestamps
   - ✅ Same: API identical

7. **HasManyThrough** (80 lines)
   - Standard example
   - Native edge traversal benefits
   - Complete working example

8. **HasOneThrough** (60 lines)
   - Standard example
   - Native edge option
   - Complete working example

9. **Polymorphic Relationships** (100 lines)
   - morphOne, morphMany, morphTo
   - ⚠️ Note: Uses foreign keys (for performance/compatibility)
   - Complete examples
   - Why foreign keys for polymorphic (explained)

**Next Steps**: Link to querying.md for relationship queries, neo4j-features.md for graph traversal

---

### 5. querying.md (~600 lines)

**Purpose**: Complete query builder reference

**Sections**:
1. **Basic Queries** (80 lines)
   - where(), orWhere()
   - whereIn(), whereNotIn()
   - whereBetween(), whereNull()
   - ✅ Same: All operators work (=, !=, >, <, >=, <=, LIKE)
   - Examples showing identical usage

2. **Advanced Where Clauses** (100 lines)
   - whereDate(), whereYear(), whereMonth()
   - whereColumn()
   - whereRaw() with ⚠️ Neo4j syntax notes
   - Nested where groups
   - Complete examples

3. **Ordering & Limiting** (60 lines)
   - orderBy(), latest(), oldest()
   - limit(), take(), skip()
   - ✅ Same: Identical to Eloquent

4. **Aggregations** (100 lines)
   - Standard: count(), sum(), avg(), min(), max()
   - Neo4j-specific: percentileDisc(), percentileCont(), stdev(), stdevp(), collect()
   - Examples of each
   - When to use Neo4j aggregates

5. **Selecting Columns** (80 lines)
   - select(), addSelect()
   - ⚠️ selectRaw() needs `n.` prefix for Neo4j
   - Examples showing proper usage
   - Why the prefix is needed

6. **Eager Loading** (100 lines)
   - with(), load()
   - Nested eager loading
   - Constrained eager loading
   - loadCount(), withCount()
   - ✅ Same: Identical to Eloquent

7. **Query Scopes** (60 lines)
   - Local scopes
   - Global scopes
   - ✅ Same: Identical to Eloquent
   - Complete example

8. **Relationship Queries** (120 lines)
   - whereHas(), doesntHave()
   - orWhereHas()
   - has() for existence
   - withCount() for counting
   - Complete examples showing all patterns

**Next Steps**: Link to neo4j-features.md for graph queries, performance.md for optimization

---

### 6. neo4j-features.md (~500 lines)

**Purpose**: Complete guide to Neo4j-specific features

**Sections**:
1. **Multi-Label Nodes** (100 lines)
   - What are multi-label nodes?
   - When to use them (organization, query optimization)
   - protected $labels property
   - Complete example: User as :users:Person:Individual
   - getLabels(), hasLabel() methods
   - withLabels() scope
   - All CRUD preserves labels

2. **Neo4j Aggregate Functions** (120 lines)
   - **Percentiles**:
     - percentileDisc($column, $percentile) - Discrete
     - percentileCont($column, $percentile) - Continuous/interpolated
     - Use cases (95th percentile, median)
   - **Standard Deviation**:
     - stdev() - Sample standard deviation
     - stdevp() - Population standard deviation
   - **Collect**:
     - collect() - Collect values into array
   - Usage in queries, relationships, withAggregate()
   - Complete examples

3. **Cypher DSL Basics** (120 lines)
   - What is the Cypher DSL?
   - Type-safe query building
   - Basic usage: User::match()
   - Instance traversal: $user->matchFrom()
   - Graph pattern helpers: outgoing(), incoming(), bidirectional()
   - Path finding: shortestPath(), allPaths()
   - Complete practical examples
   - When to use DSL vs standard Eloquent

4. **Schema Introspection** (80 lines)
   - Programmatic API (Neo4jSchema facade)
   - getAllLabels(), getAllRelationshipTypes()
   - getConstraints(), getIndexes()
   - Artisan commands:
     - php artisan neo4j:schema
     - php artisan neo4j:schema:labels --count
     - php artisan neo4j:schema:relationships
     - php artisan neo4j:schema:export
   - Complete examples

5. **Array & JSON Storage** (80 lines)
   - Hybrid storage strategy explained
   - Flat arrays → Native Neo4j LISTs
   - Nested arrays → JSON strings
   - whereJsonContains() with paths
   - whereJsonLength() operations
   - APOC optional enhancement
   - Complete examples

**Next Steps**: Link to performance.md for optimization, querying.md for standard queries

---

### 7. performance.md (~400 lines)

**Purpose**: Complete performance and optimization guide

**Sections**:
1. **Batch Operations** (100 lines)
   - Automatic batch execution (50-70% faster!)
   - insert() batching example
   - upsert() batching
   - Performance improvements shown
   - Configuration: batch_size, enable_batch_execution
   - Complete examples

2. **Managed Transactions** (120 lines)
   - write() for write transactions
   - read() for read transactions
   - Automatic retry on transient errors
   - Exponential backoff configuration
   - When to use vs DB::transaction()
   - Complete examples with retry config

3. **Indexing & Constraints** (80 lines)
   - Creating indexes via Schema builder
   - Unique constraints
   - When to index (foreign keys, frequently queried properties)
   - Index strategy for relationships
   - Complete examples

4. **Connection Pooling** (50 lines)
   - How connection pooling works
   - Configuration options
   - Health checks with ping()
   - Reconnection strategies

5. **Query Optimization** (50 lines)
   - Eager loading to avoid N+1
   - Using indexes effectively
   - When to use selectRaw
   - Batch operations over loops
   - Query logging for debugging

**Next Steps**: Link to troubleshooting.md for issues, migration-guide.md for optimization during migration

---

### 8. migration-guide.md (~500 lines)

**Purpose**: Step-by-step guide to migrate from SQL to Neo4j

**Sections**:
1. **Migration Strategy** (80 lines)
   - Incremental migration recommended
   - Dual-database setup during transition
   - Testing approach
   - Rollback plan

2. **Step 1: Setup** (60 lines)
   - Install package
   - Add Neo4j connection
   - Keep existing database connection
   - Run both databases in parallel

3. **Step 2: Convert Models** (100 lines)
   - Change base class: Model → Neo4JModel
   - Update connection property
   - Handle primary keys ($incrementing)
   - Test each model in isolation
   - Side-by-side before/after examples

4. **Step 3: Migrate Relationships** (150 lines)
   - Start with hasMany/belongsTo (simple)
   - Then belongsToMany
   - Finally through relationships
   - Use foreign key mode first (safe)
   - Optionally migrate to native edges later
   - Testing strategy for each relationship
   - Complete examples

5. **Step 4: Migrate Data** (60 lines)
   - Export from SQL
   - Transform for Neo4j
   - Import strategies
   - Verification steps

6. **Common Gotchas** (50 lines)
   - Primary keys (incrementing)
   - Operator mapping (!= → <>)
   - selectRaw needs n. prefix
   - JSON operations differences
   - Solutions for each

**Next Steps**: Link to troubleshooting.md for issues, performance.md for optimization

---

### 9. troubleshooting.md (~350 lines)

**Purpose**: Complete troubleshooting and FAQ guide

**Sections**:
1. **Connection Issues** (70 lines)
   - "Connection refused" → Neo4j not running
   - "Authentication failed" → Wrong credentials
   - "Timeout" → Connection pooling settings
   - Solutions with commands to verify

2. **Query Issues** (80 lines)
   - "Operator not supported" → Use Neo4j equivalents
   - "Property not found" → Check selectRaw with n. prefix
   - "Parameter binding error" → Array vs CypherList
   - Solutions with examples

3. **Relationship Issues** (70 lines)
   - "Foreign key not found" → Indexing issue
   - "Pivot not working" → Check storage mode
   - "Eager loading fails" → Check relationship definition
   - Solutions with examples

4. **Performance Issues** (60 lines)
   - "Queries slow" → Check indexes, use batch operations
   - "N+1 queries" → Use eager loading
   - "Transaction timeout" → Use managed transactions with retry
   - Solutions with examples

5. **Debugging Techniques** (40 lines)
   - Query logging
   - dd() and dump()
   - toCypher() to see raw query
   - Neo4j Browser for inspection

6. **Getting Help** (30 lines)
   - Check test suite for examples
   - GitHub issues
   - Review relevant docs/ guides
   - Community resources

**Next Steps**: Link to relevant docs/ files for specific topics, quick-reference.md for cheat sheets

---

### 10. quick-reference.md (~400 lines)

**Purpose**: Complete cheat sheets and quick reference

**Sections**:
1. **Concept Mapping** (80 lines)
   - SQL → Neo4j concepts table
   - Eloquent → Eloquent Cypher API mapping
   - Quick lookup for conversions

2. **Common Patterns** (120 lines)
   - CRUD operations cheat sheet
   - Relationship patterns
   - Query patterns
   - All with code snippets

3. **Configuration Reference** (80 lines)
   - All config options explained
   - Default values
   - When to change each setting
   - Complete config example

4. **Artisan Commands** (60 lines)
   - All neo4j:* commands
   - Options for each
   - Common usage examples

5. **Comparison Tables** (60 lines)
   - Foreign keys vs Native edges
   - Standard vs Neo4j aggregates
   - When to use what feature
   - Quick decision guides

**Next Steps**: Link back to index.md for navigation, specific docs/ files for deep dives

---

## Writing Guidelines

### Code Example Format

```php
// ✅ ELOQUENT (Standard Laravel)
class User extends Model
{
    protected $fillable = ['name', 'email'];
}

// ✅ ELOQUENT CYPHER (This Package)
class User extends Neo4JModel
{
    protected $connection = 'neo4j';
    protected $fillable = ['name', 'email'];
}
```

### Callout Format

**✅ Same as Eloquent**:
```
All standard Eloquent methods work identically:
- create(), find(), update(), delete()
- where(), orderBy(), limit()
- Timestamps, casting, mass assignment
```

**⚠️ Different from Eloquent**:
```
Minor differences to be aware of:
- Base class: Neo4JModel instead of Model
- Primary keys: Set $incrementing = false
- Connection: Set $connection = 'neo4j'
```

### Section Structure

Each section should follow:
1. Brief intro (1-2 sentences)
2. ✅/⚠️ Callout if applicable
3. Complete code example
4. Explanation (if needed)
5. Additional examples
6. "Next Steps" at end

---

## Implementation Checklist

### Phase 1: Foundation
- [ ] Create all 10 markdown files
- [ ] Write index.md (navigation hub)
- [ ] Write getting-started.md (complete setup)

### Phase 2: Core Content
- [ ] Write models-and-crud.md
- [ ] Write relationships.md
- [ ] Write querying.md

### Phase 3: Advanced Features
- [ ] Write neo4j-features.md
- [ ] Write performance.md

### Phase 4: Support Content
- [ ] Write migration-guide.md
- [ ] Write troubleshooting.md
- [ ] Write quick-reference.md

### Phase 5: Review & Polish
- [ ] Cross-link all files
- [ ] Verify all examples are complete
- [ ] Test code examples
- [ ] Check for consistency
- [ ] Proofread for clarity

---

## Success Criteria

✅ **Self-Contained**: No external references to specs/
✅ **Complete**: All topics covered thoroughly
✅ **Practical**: Runnable code examples throughout
✅ **Laravel-Focused**: Constant Eloquent comparisons
✅ **Navigable**: Clear cross-linking within docs/
✅ **Approachable**: Friendly tone, progressive learning
✅ **Succinct**: Maximum value, minimum words

---

## Notes for Implementation

- Start with index.md to establish tone and style
- getting-started.md sets the foundation
- Ensure each file can stand alone
- Use consistent formatting throughout
- Test all code examples before finalizing
- Get feedback after each phase
