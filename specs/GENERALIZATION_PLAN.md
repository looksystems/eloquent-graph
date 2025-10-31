# Eloquent-Cypher Generalization Plan

**Version**: 2.0.0
**Date**: October 30, 2025
**Status**: Planning

## Overview

Transform eloquent-cypher from Neo4j-specific to generic Cypher graph database support using "Graph" prefix, with driver abstraction and clean break (v2.0 major release).

## Strategic Decisions

- **Class Naming**: All `Neo4j*` → `Graph*` (GraphModel, GraphConnection, etc.)
- **Config Key**: `'neo4j'` → `'graph'`
- **Commands**: `neo4j:*` → `graph:*` (e.g., `graph:schema`, `graph:schema:labels`)
- **Architecture**: Introduce `GraphDriverInterface` with `Neo4jDriver` implementation
- **Backward Compatibility**: Clean break - no aliases (requires v2.0 major version)
- **Environment Variables**: Keep standard `NEO4J_*` for Neo4j driver compatibility
- **Tooling**: Hybrid approach - Bash for file renames, Rector for PHP code transformations
- **Timeline**: 18 days (~3 weeks)

---

## Phase 1: Driver Abstraction Layer (Days 1-3)

### 1.1 Create Driver Contracts

Create the following interfaces in `src/Contracts/`:

#### GraphDriverInterface.php
```php
namespace Look\EloquentCypher\Contracts;

interface GraphDriverInterface
{
    /**
     * Connect to the database
     */
    public function connect(array $config): void;

    /**
     * Disconnect from the database
     */
    public function disconnect(): void;

    /**
     * Execute a Cypher query
     */
    public function executeQuery(string $cypher, array $parameters = []): ResultSetInterface;

    /**
     * Execute multiple queries in a batch
     */
    public function executeBatch(array $queries): array;

    /**
     * Begin a transaction
     */
    public function beginTransaction(): TransactionInterface;

    /**
     * Commit a transaction
     */
    public function commit(TransactionInterface $transaction): void;

    /**
     * Rollback a transaction
     */
    public function rollback(TransactionInterface $transaction): void;

    /**
     * Check connection health
     */
    public function ping(): bool;

    /**
     * Get database capabilities
     */
    public function getCapabilities(): CapabilitiesInterface;

    /**
     * Get schema introspector
     */
    public function getSchemaIntrospector(): SchemaIntrospectorInterface;
}
```

#### ResultSetInterface.php
```php
namespace Look\EloquentCypher\Contracts;

interface ResultSetInterface
{
    /**
     * Convert results to array
     */
    public function toArray(): array;

    /**
     * Get row count
     */
    public function count(): int;

    /**
     * Get first result
     */
    public function first();

    /**
     * Get summary statistics
     */
    public function getSummary(): SummaryInterface;

    /**
     * Check if result set is empty
     */
    public function isEmpty(): bool;
}
```

#### CapabilitiesInterface.php
```php
namespace Look\EloquentCypher\Contracts;

interface CapabilitiesInterface
{
    /**
     * Check if database supports JSON operations
     */
    public function supportsJsonOperations(): bool;

    /**
     * Check if database supports schema introspection
     */
    public function supportsSchemaIntrospection(): bool;

    /**
     * Check if database supports transactions
     */
    public function supportsTransactions(): bool;

    /**
     * Check if database supports batch execution
     */
    public function supportsBatchExecution(): bool;

    /**
     * Get database version
     */
    public function getVersion(): string;

    /**
     * Get database type (neo4j, memgraph, age, etc.)
     */
    public function getDatabaseType(): string;
}
```

#### SchemaIntrospectorInterface.php
```php
namespace Look\EloquentCypher\Contracts;

interface SchemaIntrospectorInterface
{
    /**
     * Get all node labels
     */
    public function getLabels(): array;

    /**
     * Get all relationship types
     */
    public function getRelationshipTypes(): array;

    /**
     * Get all property keys
     */
    public function getPropertyKeys(): array;

    /**
     * Get all constraints
     */
    public function getConstraints(?string $type = null): array;

    /**
     * Get all indexes
     */
    public function getIndexes(?string $type = null): array;

    /**
     * Get complete schema introspection
     */
    public function introspect(): array;
}
```

#### TransactionInterface.php
```php
namespace Look\EloquentCypher\Contracts;

interface TransactionInterface
{
    /**
     * Execute a query within the transaction
     */
    public function run(string $cypher, array $parameters = []): ResultSetInterface;

    /**
     * Commit the transaction
     */
    public function commit(): void;

    /**
     * Rollback the transaction
     */
    public function rollback(): void;

    /**
     * Check if transaction is open
     */
    public function isOpen(): bool;
}
```

#### SummaryInterface.php
```php
namespace Look\EloquentCypher\Contracts;

interface SummaryInterface
{
    /**
     * Get execution time in milliseconds
     */
    public function getExecutionTime(): float;

    /**
     * Get counters (nodes created, relationships created, etc.)
     */
    public function getCounters(): array;

    /**
     * Get query plan if available
     */
    public function getPlan(): ?array;
}
```

### 1.2 Implement Neo4j Driver

Create Neo4j driver implementation in `src/Drivers/Neo4j/`:

#### Neo4jDriver.php
```php
namespace Look\EloquentCypher\Drivers\Neo4j;

use Laudis\Neo4j\Authentication\Authenticate;
use Laudis\Neo4j\ClientBuilder;
use Laudis\Neo4j\Contracts\ClientInterface;
use Look\EloquentCypher\Contracts\GraphDriverInterface;
use Look\EloquentCypher\Contracts\ResultSetInterface;
use Look\EloquentCypher\Contracts\TransactionInterface;
use Look\EloquentCypher\Contracts\CapabilitiesInterface;
use Look\EloquentCypher\Contracts\SchemaIntrospectorInterface;

class Neo4jDriver implements GraphDriverInterface
{
    protected ClientInterface $client;
    protected Neo4jCapabilities $capabilities;
    protected Neo4jSchemaIntrospector $schemaIntrospector;
    protected array $config;

    public function connect(array $config): void
    {
        $this->config = $config;

        $auth = Authenticate::basic(
            $config['username'],
            $config['password']
        );

        $protocol = $config['protocol'] ?? 'bolt';
        $uri = "{$protocol}://{$config['host']}:{$config['port']}";

        $this->client = ClientBuilder::create()
            ->withDriver($protocol, $uri, $auth)
            ->withDefaultDriver($protocol)
            ->build();

        $this->capabilities = new Neo4jCapabilities($this->client, $config);
        $this->schemaIntrospector = new Neo4jSchemaIntrospector($this->client);
    }

    public function disconnect(): void
    {
        // Laudis client handles connection pooling automatically
        unset($this->client);
    }

    public function executeQuery(string $cypher, array $parameters = []): ResultSetInterface
    {
        $result = $this->client->run($cypher, $parameters);
        return new Neo4jResultSet($result);
    }

    public function executeBatch(array $queries): array
    {
        $results = [];
        foreach ($queries as $query) {
            $results[] = $this->executeQuery($query['cypher'], $query['parameters'] ?? []);
        }
        return $results;
    }

    public function beginTransaction(): TransactionInterface
    {
        $transaction = $this->client->beginTransaction();
        return new Neo4jTransaction($transaction);
    }

    public function commit(TransactionInterface $transaction): void
    {
        $transaction->commit();
    }

    public function rollback(TransactionInterface $transaction): void
    {
        $transaction->rollback();
    }

    public function ping(): bool
    {
        try {
            $result = $this->executeQuery('RETURN 1 as ping');
            return $result->count() > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getCapabilities(): CapabilitiesInterface
    {
        return $this->capabilities;
    }

    public function getSchemaIntrospector(): SchemaIntrospectorInterface
    {
        return $this->schemaIntrospector;
    }
}
```

#### Neo4jResultSet.php
```php
namespace Look\EloquentCypher\Drivers\Neo4j;

use Laudis\Neo4j\Types\CypherList;
use Look\EloquentCypher\Contracts\ResultSetInterface;
use Look\EloquentCypher\Contracts\SummaryInterface;

class Neo4jResultSet implements ResultSetInterface
{
    protected CypherList $result;

    public function __construct(CypherList $result)
    {
        $this->result = $result;
    }

    public function toArray(): array
    {
        return $this->result->toArray();
    }

    public function count(): int
    {
        return $this->result->count();
    }

    public function first()
    {
        return $this->result->first();
    }

    public function getSummary(): SummaryInterface
    {
        return new Neo4jSummary($this->result);
    }

    public function isEmpty(): bool
    {
        return $this->result->count() === 0;
    }
}
```

#### Neo4jCapabilities.php
```php
namespace Look\EloquentCypher\Drivers\Neo4j;

use Laudis\Neo4j\Contracts\ClientInterface;
use Look\EloquentCypher\Contracts\CapabilitiesInterface;

class Neo4jCapabilities implements CapabilitiesInterface
{
    protected ClientInterface $client;
    protected array $config;
    protected ?bool $hasApoc = null;
    protected ?string $version = null;

    public function __construct(ClientInterface $client, array $config)
    {
        $this->client = $client;
        $this->config = $config;
    }

    public function supportsJsonOperations(): bool
    {
        // Check if APOC is available (config override or auto-detect)
        if (isset($this->config['features']['apoc'])) {
            return $this->config['features']['apoc'];
        }

        return $this->detectApoc();
    }

    public function supportsSchemaIntrospection(): bool
    {
        return true; // Neo4j 4.0+ supports SHOW CONSTRAINTS/INDEXES
    }

    public function supportsTransactions(): bool
    {
        return true;
    }

    public function supportsBatchExecution(): bool
    {
        return true;
    }

    public function getVersion(): string
    {
        if ($this->version === null) {
            $this->version = $this->detectVersion();
        }
        return $this->version;
    }

    public function getDatabaseType(): string
    {
        return 'neo4j';
    }

    protected function detectApoc(): bool
    {
        if ($this->hasApoc !== null) {
            return $this->hasApoc;
        }

        try {
            $result = $this->client->run('RETURN apoc.version() as version');
            $this->hasApoc = $result->count() > 0;
        } catch (\Exception $e) {
            $this->hasApoc = false;
        }

        return $this->hasApoc;
    }

    protected function detectVersion(): string
    {
        try {
            $result = $this->client->run('CALL dbms.components() YIELD versions RETURN versions[0] as version');
            return $result->first()['version'] ?? 'unknown';
        } catch (\Exception $e) {
            return 'unknown';
        }
    }
}
```

#### Neo4jSchemaIntrospector.php
```php
namespace Look\EloquentCypher\Drivers\Neo4j;

use Laudis\Neo4j\Contracts\ClientInterface;
use Look\EloquentCypher\Contracts\SchemaIntrospectorInterface;

class Neo4jSchemaIntrospector implements SchemaIntrospectorInterface
{
    protected ClientInterface $client;

    public function __construct(ClientInterface $client)
    {
        $this->client = $client;
    }

    public function getLabels(): array
    {
        $result = $this->client->run('CALL db.labels() YIELD label RETURN label');
        return $result->pluck('label')->toArray();
    }

    public function getRelationshipTypes(): array
    {
        $result = $this->client->run('CALL db.relationshipTypes() YIELD relationshipType RETURN relationshipType');
        return $result->pluck('relationshipType')->toArray();
    }

    public function getPropertyKeys(): array
    {
        $result = $this->client->run('CALL db.propertyKeys() YIELD propertyKey RETURN propertyKey');
        return $result->pluck('propertyKey')->toArray();
    }

    public function getConstraints(?string $type = null): array
    {
        $cypher = 'SHOW CONSTRAINTS';
        if ($type) {
            $cypher .= " WHERE type = '{$type}'";
        }

        $result = $this->client->run($cypher);
        return $result->toArray();
    }

    public function getIndexes(?string $type = null): array
    {
        $cypher = 'SHOW INDEXES';
        if ($type) {
            $cypher .= " WHERE type = '{$type}'";
        }

        $result = $this->client->run($cypher);
        return $result->toArray();
    }

    public function introspect(): array
    {
        return [
            'labels' => $this->getLabels(),
            'relationships' => $this->getRelationshipTypes(),
            'properties' => $this->getPropertyKeys(),
            'constraints' => $this->getConstraints(),
            'indexes' => $this->getIndexes(),
        ];
    }
}
```

#### Neo4jTransaction.php
```php
namespace Look\EloquentCypher\Drivers\Neo4j;

use Laudis\Neo4j\Contracts\UnmanagedTransactionInterface;
use Look\EloquentCypher\Contracts\TransactionInterface;
use Look\EloquentCypher\Contracts\ResultSetInterface;

class Neo4jTransaction implements TransactionInterface
{
    protected UnmanagedTransactionInterface $transaction;
    protected bool $isOpen = true;

    public function __construct(UnmanagedTransactionInterface $transaction)
    {
        $this->transaction = $transaction;
    }

    public function run(string $cypher, array $parameters = []): ResultSetInterface
    {
        $result = $this->transaction->run($cypher, $parameters);
        return new Neo4jResultSet($result);
    }

    public function commit(): void
    {
        $this->transaction->commit();
        $this->isOpen = false;
    }

    public function rollback(): void
    {
        $this->transaction->rollback();
        $this->isOpen = false;
    }

    public function isOpen(): bool
    {
        return $this->isOpen;
    }
}
```

#### Neo4jSummary.php
```php
namespace Look\EloquentCypher\Drivers\Neo4j;

use Look\EloquentCypher\Contracts\SummaryInterface;

class Neo4jSummary implements SummaryInterface
{
    protected $result;

    public function __construct($result)
    {
        $this->result = $result;
    }

    public function getExecutionTime(): float
    {
        // Implementation depends on Laudis result object
        return 0.0;
    }

    public function getCounters(): array
    {
        return [];
    }

    public function getPlan(): ?array
    {
        return null;
    }
}
```

### 1.3 Create Driver Manager

Create `src/Drivers/DriverManager.php`:

```php
namespace Look\EloquentCypher\Drivers;

use Look\EloquentCypher\Contracts\GraphDriverInterface;
use Look\EloquentCypher\Drivers\Neo4j\Neo4jDriver;
use InvalidArgumentException;

class DriverManager
{
    protected static array $drivers = [
        'neo4j' => Neo4jDriver::class,
        // Future: 'memgraph' => MemgraphDriver::class,
        // Future: 'age' => AgeDriver::class,
    ];

    /**
     * Create a driver instance based on configuration
     */
    public static function create(array $config): GraphDriverInterface
    {
        $databaseType = $config['database_type'] ?? 'neo4j';

        if (!isset(static::$drivers[$databaseType])) {
            throw new InvalidArgumentException(
                "Unsupported database type: {$databaseType}. " .
                "Supported types: " . implode(', ', array_keys(static::$drivers))
            );
        }

        $driverClass = static::$drivers[$databaseType];
        $driver = new $driverClass();
        $driver->connect($config);

        return $driver;
    }

    /**
     * Register a custom driver
     */
    public static function register(string $name, string $driverClass): void
    {
        if (!is_subclass_of($driverClass, GraphDriverInterface::class)) {
            throw new InvalidArgumentException(
                "Driver must implement GraphDriverInterface"
            );
        }

        static::$drivers[$name] = $driverClass;
    }

    /**
     * Get list of supported database types
     */
    public static function supportedTypes(): array
    {
        return array_keys(static::$drivers);
    }
}
```

---

## Phase 2: Core Class Renaming (Days 4-6)

### 2.1 File Renaming Script

Create `scripts/rename-files.sh`:

```bash
#!/bin/bash
# Rename all Neo4j-prefixed files to Graph prefix

set -e  # Exit on error

echo "========================================"
echo "Eloquent-Cypher File Renaming Script"
echo "Neo4j → Graph"
echo "========================================"

# Function to rename with git
rename_file() {
    if [ -f "$1" ]; then
        echo "  $1 → $2"
        git mv "$1" "$2"
    else
        echo "  ⚠️  File not found: $1"
    fi
}

echo ""
echo "📁 Renaming core source files..."
rename_file "src/Neo4jConnection.php" "src/GraphConnection.php"
rename_file "src/Neo4JModel.php" "src/GraphModel.php"
rename_file "src/Neo4jQueryBuilder.php" "src/GraphQueryBuilder.php"
rename_file "src/Neo4jEloquentBuilder.php" "src/GraphEloquentBuilder.php"
rename_file "src/Neo4jServiceProvider.php" "src/GraphServiceProvider.php"
rename_file "src/Neo4jGrammar.php" "src/GraphGrammar.php"
rename_file "src/Neo4jEdgePivot.php" "src/EdgePivot.php"
rename_file "src/Neo4jSchemaGrammar.php" "src/GraphSchemaGrammar.php"

echo ""
echo "📁 Renaming schema files..."
rename_file "src/Schema/Neo4jSchemaBuilder.php" "src/Schema/GraphSchemaBuilder.php"
rename_file "src/Schema/Neo4jSchemaGrammar.php" "src/Schema/GraphSchemaGrammar.php"
rename_file "src/Schema/Neo4jBlueprint.php" "src/Schema/GraphBlueprint.php"

echo ""
echo "📁 Renaming relationship files..."
rename_file "src/Relations/Neo4jHasMany.php" "src/Relations/GraphHasMany.php"
rename_file "src/Relations/Neo4jHasOne.php" "src/Relations/GraphHasOne.php"
rename_file "src/Relations/Neo4jBelongsTo.php" "src/Relations/GraphBelongsTo.php"
rename_file "src/Relations/Neo4jBelongsToMany.php" "src/Relations/GraphBelongsToMany.php"
rename_file "src/Relations/Neo4jHasOneThrough.php" "src/Relations/GraphHasOneThrough.php"
rename_file "src/Relations/Neo4jHasManyThrough.php" "src/Relations/GraphHasManyThrough.php"
rename_file "src/Relations/Neo4jMorphToMany.php" "src/Relations/GraphMorphToMany.php"
rename_file "src/Relations/Neo4jRelationHelpers.php" "src/Relations/GraphRelationHelpers.php"

echo ""
echo "📁 Renaming exception files..."
rename_file "src/Exceptions/Neo4jException.php" "src/Exceptions/GraphException.php"
rename_file "src/Exceptions/Neo4jQueryException.php" "src/Exceptions/GraphQueryException.php"
rename_file "src/Exceptions/Neo4jConnectionException.php" "src/Exceptions/GraphConnectionException.php"
rename_file "src/Exceptions/Neo4jNetworkException.php" "src/Exceptions/GraphNetworkException.php"
rename_file "src/Exceptions/Neo4jAuthenticationException.php" "src/Exceptions/GraphAuthenticationException.php"
rename_file "src/Exceptions/Neo4jConstraintException.php" "src/Exceptions/GraphConstraintException.php"
rename_file "src/Exceptions/Neo4jTransactionException.php" "src/Exceptions/GraphTransactionException.php"
rename_file "src/Exceptions/Neo4jTransientException.php" "src/Exceptions/GraphTransientException.php"

echo ""
echo "📁 Renaming supporting files..."
rename_file "src/Builders/Neo4jCypherDslBuilder.php" "src/Builders/GraphCypherDslBuilder.php"
rename_file "src/Services/Neo4jEdgeManager.php" "src/Services/EdgeManager.php"
rename_file "src/Facades/Neo4jSchema.php" "src/Facades/GraphSchema.php"
rename_file "src/Concerns/Neo4jSoftDeletes.php" "src/Concerns/GraphSoftDeletes.php"
rename_file "src/Traits/Neo4jNativeRelationships.php" "src/Traits/NativeRelationships.php"

echo ""
echo "📁 Renaming test files..."
rename_file "tests/TestCase/Neo4jTestCase.php" "tests/TestCase/GraphTestCase.php"
rename_file "tests/TestCase/Helpers/Neo4jTestHelper.php" "tests/TestCase/Helpers/GraphTestHelper.php"
rename_file "tests/Fixtures/Neo4jUser.php" "tests/Fixtures/GraphUser.php"

echo ""
echo "📁 Renaming test suite files (pattern-based)..."
find tests -name "*Neo4j*.php" -type f | while read file; do
    newfile=$(echo "$file" | sed 's/Neo4j/Graph/g')
    if [ "$file" != "$newfile" ]; then
        echo "  $file → $newfile"
        git mv "$file" "$newfile"
    fi
done

echo ""
echo "✅ File renaming complete!"
echo ""
echo "Next steps:"
echo "  1. Run Rector to update class names and imports"
echo "  2. Update configuration files"
echo "  3. Run tests to verify"
```

### 2.2 Rector Configuration

Create `rector-refactor.php`:

```php
<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Renaming\Rector\Name\RenameClassRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withRules([
        RenameClassRector::class,
    ])
    ->withConfiguredRule(RenameClassRector::class, [
        // Core classes
        'Look\\EloquentCypher\\Neo4JModel' => 'Look\\EloquentCypher\\GraphModel',
        'Look\\EloquentCypher\\Neo4jConnection' => 'Look\\EloquentCypher\\GraphConnection',
        'Look\\EloquentCypher\\Neo4jQueryBuilder' => 'Look\\EloquentCypher\\GraphQueryBuilder',
        'Look\\EloquentCypher\\Neo4jEloquentBuilder' => 'Look\\EloquentCypher\\GraphEloquentBuilder',
        'Look\\EloquentCypher\\Neo4jServiceProvider' => 'Look\\EloquentCypher\\GraphServiceProvider',
        'Look\\EloquentCypher\\Neo4jGrammar' => 'Look\\EloquentCypher\\GraphGrammar',
        'Look\\EloquentCypher\\Neo4jEdgePivot' => 'Look\\EloquentCypher\\EdgePivot',
        'Look\\EloquentCypher\\Neo4jSchemaGrammar' => 'Look\\EloquentCypher\\GraphSchemaGrammar',

        // Schema classes
        'Look\\EloquentCypher\\Schema\\Neo4jSchemaBuilder' => 'Look\\EloquentCypher\\Schema\\GraphSchemaBuilder',
        'Look\\EloquentCypher\\Schema\\Neo4jSchemaGrammar' => 'Look\\EloquentCypher\\Schema\\GraphSchemaGrammar',
        'Look\\EloquentCypher\\Schema\\Neo4jBlueprint' => 'Look\\EloquentCypher\\Schema\\GraphBlueprint',

        // Relationship classes
        'Look\\EloquentCypher\\Relations\\Neo4jHasMany' => 'Look\\EloquentCypher\\Relations\\GraphHasMany',
        'Look\\EloquentCypher\\Relations\\Neo4jHasOne' => 'Look\\EloquentCypher\\Relations\\GraphHasOne',
        'Look\\EloquentCypher\\Relations\\Neo4jBelongsTo' => 'Look\\EloquentCypher\\Relations\\GraphBelongsTo',
        'Look\\EloquentCypher\\Relations\\Neo4jBelongsToMany' => 'Look\\EloquentCypher\\Relations\\GraphBelongsToMany',
        'Look\\EloquentCypher\\Relations\\Neo4jHasOneThrough' => 'Look\\EloquentCypher\\Relations\\GraphHasOneThrough',
        'Look\\EloquentCypher\\Relations\\Neo4jHasManyThrough' => 'Look\\EloquentCypher\\Relations\\GraphHasManyThrough',
        'Look\\EloquentCypher\\Relations\\Neo4jMorphToMany' => 'Look\\EloquentCypher\\Relations\\GraphMorphToMany',
        'Look\\EloquentCypher\\Relations\\Neo4jRelationHelpers' => 'Look\\EloquentCypher\\Relations\\GraphRelationHelpers',

        // Exception classes
        'Look\\EloquentCypher\\Exceptions\\Neo4jException' => 'Look\\EloquentCypher\\Exceptions\\GraphException',
        'Look\\EloquentCypher\\Exceptions\\Neo4jQueryException' => 'Look\\EloquentCypher\\Exceptions\\GraphQueryException',
        'Look\\EloquentCypher\\Exceptions\\Neo4jConnectionException' => 'Look\\EloquentCypher\\Exceptions\\GraphConnectionException',
        'Look\\EloquentCypher\\Exceptions\\Neo4jNetworkException' => 'Look\\EloquentCypher\\Exceptions\\GraphNetworkException',
        'Look\\EloquentCypher\\Exceptions\\Neo4jAuthenticationException' => 'Look\\EloquentCypher\\Exceptions\\GraphAuthenticationException',
        'Look\\EloquentCypher\\Exceptions\\Neo4jConstraintException' => 'Look\\EloquentCypher\\Exceptions\\GraphConstraintException',
        'Look\\EloquentCypher\\Exceptions\\Neo4jTransactionException' => 'Look\\EloquentCypher\\Exceptions\\GraphTransactionException',
        'Look\\EloquentCypher\\Exceptions\\Neo4jTransientException' => 'Look\\EloquentCypher\\Exceptions\\GraphTransientException',

        // Supporting classes
        'Look\\EloquentCypher\\Builders\\Neo4jCypherDslBuilder' => 'Look\\EloquentCypher\\Builders\\GraphCypherDslBuilder',
        'Look\\EloquentCypher\\Services\\Neo4jEdgeManager' => 'Look\\EloquentCypher\\Services\\EdgeManager',
        'Look\\EloquentCypher\\Facades\\Neo4jSchema' => 'Look\\EloquentCypher\\Facades\\GraphSchema',
        'Look\\EloquentCypher\\Concerns\\Neo4jSoftDeletes' => 'Look\\EloquentCypher\\Concerns\\GraphSoftDeletes',
        'Look\\EloquentCypher\\Traits\\Neo4jNativeRelationships' => 'Look\\EloquentCypher\\Traits\\NativeRelationships',

        // Test classes
        'Look\\EloquentCypher\\Tests\\TestCase\\Neo4jTestCase' => 'Look\\EloquentCypher\\Tests\\TestCase\\GraphTestCase',
        'Look\\EloquentCypher\\Tests\\TestCase\\Helpers\\Neo4jTestHelper' => 'Look\\EloquentCypher\\Tests\\TestCase\\Helpers\\GraphTestHelper',
        'Look\\EloquentCypher\\Tests\\Fixtures\\Neo4jUser' => 'Look\\EloquentCypher\\Tests\\Fixtures\\GraphUser',
    ]);
```

### 2.3 Additional String Replacements

Create `scripts/update-strings.sh`:

```bash
#!/bin/bash
# Update string references that Rector can't handle

set -e

echo "Updating string references..."

# Update connection name in config files
find src tests -type f -name "*.php" -exec sed -i '' "s/'neo4j'/'graph'/g" {} \;

# Update command names in console files
find src/Console -type f -name "*.php" -exec sed -i '' "s/neo4j:/graph:/g" {} \;

# Update facade accessor
find src/Facades -type f -name "*.php" -exec sed -i '' 's/"neo4j-schema"/"graph-schema"/g' {} \;

echo "✅ String replacement complete!"
```

---

## Phase 3: Connection Refactoring (Days 7-8)

### 3.1 Update GraphConnection

Key changes to `src/GraphConnection.php`:

```php
// Before:
protected ClientInterface $neo4jClient;

// After:
protected GraphDriverInterface $driver;

// Constructor changes:
public function __construct($pdo, $database = '', $tablePrefix = '', array $config = [])
{
    parent::__construct($pdo, $database, $tablePrefix, $config);

    // Create driver based on config
    $this->driver = DriverManager::create($config);

    // Initialize other components...
}

// Query execution:
public function select($query, $bindings = [], $useReadPdo = true)
{
    return $this->run($query, $bindings, function ($query, $bindings) {
        $result = $this->driver->executeQuery($query, $bindings);
        return $this->transformer->transform($result);
    });
}

// Transaction methods:
public function beginTransaction()
{
    ++$this->transactions;

    if ($this->transactions === 1) {
        $this->currentTransaction = $this->driver->beginTransaction();
    }
}

// Capability checks:
public function hasAPOC(): bool
{
    return $this->driver->getCapabilities()->supportsJsonOperations();
}
```

### 3.2 Update ResponseTransformer

Update `src/Query/ResponseTransformer.php` to work with `ResultSetInterface`:

```php
public function transform(ResultSetInterface $result): array
{
    $rows = [];

    foreach ($result->toArray() as $row) {
        $rows[] = $this->transformRow($row);
    }

    return $rows;
}

protected function transformRow($row): array
{
    // Handle both array and object formats
    $data = [];

    if (is_array($row)) {
        foreach ($row as $key => $value) {
            $data[$key] = $this->transformValue($value);
        }
    } else {
        foreach ($row->keys() as $key) {
            $data[$key] = $this->transformValue($row[$key]);
        }
    }

    return $data;
}
```

---

## Phase 4: Schema Abstraction (Days 9-10)

### 4.1 Update GraphSchemaBuilder

Update `src/Schema/GraphSchemaBuilder.php`:

```php
protected SchemaIntrospectorInterface $introspector;

public function __construct(GraphConnection $connection)
{
    parent::__construct($connection);
    $this->introspector = $connection->getDriver()->getSchemaIntrospector();
}

public function getAllLabels(): array
{
    return $this->introspector->getLabels();
}

public function getAllRelationshipTypes(): array
{
    return $this->introspector->getRelationshipTypes();
}

// ... etc
```

### 4.2 Update Artisan Commands

Rename command files:
- `GraphSchemaCommand.php`
- `GraphSchemaLabelsCommand.php`
- `GraphSchemaRelationshipsCommand.php`
- etc.

Update command signatures:
```php
protected $signature = 'graph:schema {--json : Output as JSON}';
protected $signature = 'graph:schema:labels {--count : Include node counts}';
// ... etc
```

---

## Phase 5: Configuration Updates (Day 11)

### 5.1 Example Configuration

Update default config structure in service provider:

```php
// config/database.php
'connections' => [
    'graph' => [
        'driver' => 'graph',
        'database_type' => env('GRAPH_DATABASE_TYPE', 'neo4j'),
        'protocol' => env('GRAPH_PROTOCOL', 'bolt'),

        // Neo4j standard environment variables
        'host' => env('NEO4J_HOST', 'localhost'),
        'port' => env('NEO4J_PORT', 7687),
        'username' => env('NEO4J_USERNAME', 'neo4j'),
        'password' => env('NEO4J_PASSWORD', 'password'),

        // Database capabilities
        'features' => [
            'apoc' => env('NEO4J_APOC', true),
            'schema_introspection' => true,
        ],

        // Relationship storage
        'default_relationship_storage' => env('GRAPH_RELATIONSHIP_STORAGE', 'hybrid'),

        // Batch execution
        'enable_batch_execution' => env('GRAPH_BATCH_ENABLED', true),
        'batch_size' => env('GRAPH_BATCH_SIZE', 100),

        // Connection pooling
        'pool' => [
            'max_connections' => env('GRAPH_POOL_MAX', 50),
            'idle_timeout' => env('GRAPH_POOL_IDLE_TIMEOUT', 300),
        ],

        // Retry configuration
        'retry' => [
            'max_attempts' => env('GRAPH_RETRY_MAX', 3),
            'initial_delay_ms' => env('GRAPH_RETRY_DELAY', 100),
            'max_delay_ms' => env('GRAPH_RETRY_MAX_DELAY', 5000),
            'multiplier' => 2.0,
            'jitter' => true,
        ],
    ],
],
```

### 5.2 Environment Variables Documentation

**Neo4j (default)**:
- `NEO4J_HOST` - Database host
- `NEO4J_PORT` - Database port (default: 7687)
- `NEO4J_USERNAME` - Authentication username
- `NEO4J_PASSWORD` - Authentication password
- `NEO4J_APOC` - APOC plugin available (default: true)

**Future databases** (Memgraph example):
- `MEMGRAPH_HOST`
- `MEMGRAPH_PORT`
- etc.

---

## Phase 6: Test Suite Updates (Days 12-14)

### 6.1 Test File Updates

After running the rename script and Rector, manually verify:

1. **Base test case** (`GraphTestCase.php`):
   ```php
   protected function getConnection(): GraphConnection
   {
       return DB::connection('graph');
   }
   ```

2. **Test fixtures** (all model classes):
   ```php
   use Look\EloquentCypher\GraphModel;

   class User extends GraphModel
   {
       protected $connection = 'graph';
   }
   ```

3. **Connection references**:
   ```php
   // Before:
   config(['database.connections.neo4j' => [...]]);

   // After:
   config(['database.connections.graph' => [...]]);
   ```

### 6.2 Create Driver Tests

Create `tests/Unit/Drivers/Neo4jDriverTest.php`:

```php
<?php

namespace Look\EloquentCypher\Tests\Unit\Drivers;

use Look\EloquentCypher\Drivers\Neo4j\Neo4jDriver;
use Look\EloquentCypher\Contracts\GraphDriverInterface;
use Look\EloquentCypher\Tests\TestCase\GraphTestCase;

class Neo4jDriverTest extends GraphTestCase
{
    protected Neo4jDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = new Neo4jDriver();
        $this->driver->connect(config('database.connections.graph'));
    }

    public function test_driver_implements_interface(): void
    {
        $this->assertInstanceOf(GraphDriverInterface::class, $this->driver);
    }

    public function test_can_execute_query(): void
    {
        $result = $this->driver->executeQuery('RETURN 1 as num');

        $this->assertFalse($result->isEmpty());
        $this->assertEquals(1, $result->count());
    }

    public function test_can_ping_database(): void
    {
        $this->assertTrue($this->driver->ping());
    }

    public function test_can_get_capabilities(): void
    {
        $capabilities = $this->driver->getCapabilities();

        $this->assertEquals('neo4j', $capabilities->getDatabaseType());
        $this->assertTrue($capabilities->supportsTransactions());
    }

    public function test_can_get_schema_introspector(): void
    {
        $introspector = $this->driver->getSchemaIntrospector();

        $labels = $introspector->getLabels();
        $this->assertIsArray($labels);
    }

    public function test_can_begin_and_commit_transaction(): void
    {
        $transaction = $this->driver->beginTransaction();

        $this->assertTrue($transaction->isOpen());

        $transaction->run('CREATE (n:TestNode {name: $name})', ['name' => 'test']);
        $transaction->commit();

        $this->assertFalse($transaction->isOpen());
    }
}
```

Create `tests/Unit/Drivers/DriverManagerTest.php`:

```php
<?php

namespace Look\EloquentCypher\Tests\Unit\Drivers;

use Look\EloquentCypher\Drivers\DriverManager;
use Look\EloquentCypher\Drivers\Neo4j\Neo4jDriver;
use Look\EloquentCypher\Contracts\GraphDriverInterface;
use Look\EloquentCypher\Tests\TestCase\GraphTestCase;

class DriverManagerTest extends GraphTestCase
{
    public function test_can_create_neo4j_driver(): void
    {
        $config = config('database.connections.graph');
        $driver = DriverManager::create($config);

        $this->assertInstanceOf(GraphDriverInterface::class, $driver);
        $this->assertInstanceOf(Neo4jDriver::class, $driver);
    }

    public function test_throws_exception_for_unsupported_database(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DriverManager::create(['database_type' => 'unknown']);
    }

    public function test_can_register_custom_driver(): void
    {
        $this->expectNotToPerformAssertions();

        DriverManager::register('custom', Neo4jDriver::class);
    }

    public function test_returns_supported_types(): void
    {
        $types = DriverManager::supportedTypes();

        $this->assertIsArray($types);
        $this->assertContains('neo4j', $types);
    }
}
```

### 6.3 Run Full Test Suite

```bash
# Run all tests
composer test

# Run with coverage
composer test:coverage

# Expected: 1,470+ tests passing (including new driver tests)
```

---

## Phase 7: Documentation Updates (Days 15-16)

### 7.1 Update Core Documentation Files

Update the following files with new class names and conventions:

1. **README.md**
   - Change "Neo4j" to "Graph Database" in title/description
   - Update installation instructions
   - Update model examples: `GraphModel`
   - Update connection config examples
   - Update command examples: `graph:schema`
   - Add multi-database support section

2. **CLAUDE.md**
   - Update all command examples
   - Update class references
   - Update architecture section
   - Update test commands

3. **docs/getting-started.md**
   - Update configuration examples
   - Update model base class
   - Update connection setup

4. **docs/models-and-crud.md**
   - Change `Neo4JModel` to `GraphModel`
   - Update all examples

5. **docs/relationships.md**
   - Update relationship class names
   - Update examples

6. **docs/querying.md**
   - Update connection references
   - Update examples

7. **docs/schema-introspection.md**
   - Update command names: `neo4j:*` → `graph:*`
   - Update facade name: `GraphSchema`
   - Update examples

### 7.2 Create/Update Database-Specific Documentation

1. Rename `docs/neo4j-overview.md` → `docs/database-support.md`
   - Add section on Neo4j
   - Add section on future database support (Memgraph, AGE)
   - Add driver architecture overview

2. Rename `docs/neo4j-aggregates.md` → `docs/graph-aggregates.md`
   - Update references
   - Clarify database compatibility

3. Create `docs/driver-development.md`
   - Guide for implementing custom drivers
   - Driver interface documentation
   - Neo4j driver as reference implementation

### 7.3 Create Migration Guide

Create `MIGRATION_V2.md`:

```markdown
# Migration Guide: v1.x to v2.0

## Overview

Version 2.0 represents a major refactoring of eloquent-cypher to support any Cypher-based graph database, not just Neo4j. This guide will help you migrate your existing code.

## Breaking Changes

### 1. Class Names

All `Neo4j*` classes have been renamed to `Graph*`:

| v1.x | v2.0 |
|------|------|
| `Neo4JModel` | `GraphModel` |
| `Neo4jConnection` | `GraphConnection` |
| `Neo4jQueryBuilder` | `GraphQueryBuilder` |
| `Neo4jEloquentBuilder` | `GraphEloquentBuilder` |
| `Neo4jException` | `GraphException` |
| `Neo4jSchema` (facade) | `GraphSchema` |
| ... | ... |

### 2. Configuration

The connection key has changed from `neo4j` to `graph`:

**Before (v1.x)**:
```php
'connections' => [
    'neo4j' => [
        'driver' => 'neo4j',
        // ...
    ]
]
```

**After (v2.0)**:
```php
'connections' => [
    'graph' => [
        'driver' => 'graph',
        'database_type' => 'neo4j',  // NEW
        // ...
    ]
]
```

### 3. Environment Variables

**Good news**: Neo4j environment variables remain unchanged!

- `NEO4J_HOST` ✅
- `NEO4J_PORT` ✅
- `NEO4J_USERNAME` ✅
- `NEO4J_PASSWORD` ✅
- `NEO4J_APOC` ✅

### 4. Artisan Commands

All commands have been renamed:

| v1.x | v2.0 |
|------|------|
| `php artisan neo4j:schema` | `php artisan graph:schema` |
| `neo4j:schema:labels` | `graph:schema:labels` |
| `neo4j:schema:relationships` | `graph:schema:relationships` |
| `neo4j:check-compatibility` | `graph:check-compatibility` |
| `neo4j:migrate-to-edges` | `graph:migrate-to-edges` |

### 5. Model Base Class

**Before (v1.x)**:
```php
use Look\EloquentCypher\Neo4JModel;

class User extends Neo4JModel
{
    protected $connection = 'neo4j';
}
```

**After (v2.0)**:
```php
use Look\EloquentCypher\GraphModel;

class User extends GraphModel
{
    protected $connection = 'graph';
}
```

### 6. Facade

**Before (v1.x)**:
```php
use Look\EloquentCypher\Facades\Neo4jSchema;

$labels = Neo4jSchema::getAllLabels();
```

**After (v2.0)**:
```php
use Look\EloquentCypher\Facades\GraphSchema;

$labels = GraphSchema::getAllLabels();
```

## Migration Steps

### Step 1: Update Composer

```bash
composer require look/eloquent-cypher:^2.0
```

### Step 2: Update Configuration

Edit `config/database.php`:

```php
'connections' => [
    // Remove 'neo4j' connection
    // Add 'graph' connection
    'graph' => [
        'driver' => 'graph',
        'database_type' => 'neo4j',
        'host' => env('NEO4J_HOST', 'localhost'),
        // ... copy other settings
    ]
]
```

### Step 3: Update Models

Find and replace in all model files:

```bash
# Option 1: Manual search and replace
find app/Models -type f -exec sed -i '' 's/Neo4JModel/GraphModel/g' {} \;
find app/Models -type f -exec sed -i '' "s/'neo4j'/'graph'/g" {} \;

# Option 2: Use IDE refactoring tools
# Search for "Neo4JModel" → Replace with "GraphModel"
# Search for "'neo4j'" → Replace with "'graph'"
```

### Step 4: Update Imports

```php
// Before
use Look\EloquentCypher\Neo4JModel;
use Look\EloquentCypher\Facades\Neo4jSchema;
use Look\EloquentCypher\Exceptions\Neo4jException;

// After
use Look\EloquentCypher\GraphModel;
use Look\EloquentCypher\Facades\GraphSchema;
use Look\EloquentCypher\Exceptions\GraphException;
```

### Step 5: Update Command Calls

Update any scripts or documentation:

```bash
# Before
php artisan neo4j:schema

# After
php artisan graph:schema
```

### Step 6: Test

```bash
# Run your test suite
php artisan test

# Or phpunit
./vendor/bin/phpunit
```

## Common Issues

### Issue: "Class Neo4JModel not found"

**Solution**: Update all model imports to use `GraphModel`.

### Issue: "Connection [neo4j] not configured"

**Solution**: Update connection references from `'neo4j'` to `'graph'` in:
- Model `$connection` properties
- Database facade calls: `DB::connection('graph')`
- Config files

### Issue: "Command 'neo4j:schema' is not defined"

**Solution**: Use the new command names: `graph:schema`, `graph:schema:labels`, etc.

## Need Help?

- Check the [documentation](docs/)
- Review [examples](examples/)
- Open an issue on [GitHub](https://github.com/look/eloquent-cypher/issues)
```

### 7.4 Update Package Metadata

Update `composer.json`:

```json
{
    "name": "look/eloquent-cypher",
    "description": "Laravel Eloquent adapter for Cypher-based graph databases (Neo4j, Memgraph, AGE)",
    "keywords": [
        "laravel",
        "eloquent",
        "graph",
        "graph-database",
        "cypher",
        "neo4j",
        "memgraph",
        "age",
        "database"
    ],
    "version": "2.0.0"
}
```

Update README.md badges and description:

```markdown
# Eloquent Cypher

> Laravel Eloquent adapter for Cypher-based graph databases

Eloquent Cypher brings the power of Laravel's Eloquent ORM to graph databases that support the Cypher query language, including Neo4j, Memgraph, and Apache AGE.
```

---

## Phase 8: Final Polish (Days 17-18)

### 8.1 Code Quality Checks

```bash
# Run PHPStan
composer analyse

# Fix any issues found
# Re-run until clean

# Run PHP CS Fixer / Pint
composer format:fix

# Verify formatting
composer format
```

### 8.2 Update CHANGELOG

Create/update `CHANGELOG.md`:

```markdown
# Changelog

All notable changes to this project will be documented in this file.

## [2.0.0] - 2025-10-30

### Added
- **Driver Abstraction Layer**: New `GraphDriverInterface` for multi-database support
- **Neo4j Driver**: Neo4j-specific driver implementation
- **Driver Manager**: Factory for creating database drivers
- **Database Type Config**: New `database_type` configuration option
- Support for future databases: Memgraph, Apache AGE, etc.

### Changed
- **[BREAKING]** All `Neo4j*` classes renamed to `Graph*`
  - `Neo4JModel` → `GraphModel`
  - `Neo4jConnection` → `GraphConnection`
  - `Neo4jQueryBuilder` → `GraphQueryBuilder`
  - And 40+ more classes
- **[BREAKING]** Connection key changed from `neo4j` to `graph`
- **[BREAKING]** Artisan commands renamed from `neo4j:*` to `graph:*`
- **[BREAKING]** Facade renamed from `Neo4jSchema` to `GraphSchema`
- Architecture refactored for database-agnostic implementation

### Maintained
- ✅ Environment variables: `NEO4J_*` variables unchanged
- ✅ Full backward compatibility for Neo4j functionality
- ✅ All existing features work exactly as before
- ✅ 100% test coverage maintained (1,470+ tests)

### Migration
See [MIGRATION_V2.md](MIGRATION_V2.md) for detailed upgrade instructions.

## [1.3.0] - 2025-10-25
...
```

### 8.3 Version Bump

Update `composer.json`:

```json
{
    "version": "2.0.0"
}
```

Tag release:

```bash
git add .
git commit -m "Release v2.0.0: Generalize to support any Cypher database"
git tag -a v2.0.0 -m "Version 2.0.0"
git push origin master --tags
```

### 8.4 Final Testing

```bash
# Full test suite
composer test

# With coverage
composer test:coverage

# Verify: 1,470+ tests passing
# Expected results:
#   - All existing tests: PASS
#   - New driver tests: PASS
#   - Integration tests: PASS
```

---

## Implementation Checklist

### Phase 1: Driver Abstraction ✓
- [ ] Create `GraphDriverInterface`
- [ ] Create `ResultSetInterface`
- [ ] Create `CapabilitiesInterface`
- [ ] Create `SchemaIntrospectorInterface`
- [ ] Create `TransactionInterface`
- [ ] Create `SummaryInterface`
- [ ] Implement `Neo4jDriver`
- [ ] Implement `Neo4jResultSet`
- [ ] Implement `Neo4jCapabilities`
- [ ] Implement `Neo4jSchemaIntrospector`
- [ ] Implement `Neo4jTransaction`
- [ ] Implement `Neo4jSummary`
- [ ] Create `DriverManager`

### Phase 2: File Renaming ✓
- [ ] Create rename script (`scripts/rename-files.sh`)
- [ ] Run rename script
- [ ] Create Rector config (`rector-refactor.php`)
- [ ] Run Rector transformations
- [ ] Create string replacement script
- [ ] Run string replacements
- [ ] Verify all files renamed

### Phase 3: Connection Refactoring ✓
- [ ] Update `GraphConnection` to use driver
- [ ] Update query execution methods
- [ ] Update transaction methods
- [ ] Update capability detection
- [ ] Update `ResponseTransformer`
- [ ] Update `ParameterHelper`

### Phase 4: Schema Abstraction ✓
- [ ] Update `GraphSchemaBuilder`
- [ ] Rename command files
- [ ] Update command signatures
- [ ] Update command logic
- [ ] Test all schema commands

### Phase 5: Configuration ✓
- [ ] Update default config structure
- [ ] Document env var strategy
- [ ] Update service provider
- [ ] Update connection resolver

### Phase 6: Tests ✓
- [ ] Rename test files
- [ ] Update test imports
- [ ] Update connection references
- [ ] Create driver tests
- [ ] Run full test suite
- [ ] Fix any failures
- [ ] Verify 100% pass rate

### Phase 7: Documentation ✓
- [ ] Update README.md
- [ ] Update CLAUDE.md
- [ ] Update getting-started.md
- [ ] Update models-and-crud.md
- [ ] Update relationships.md
- [ ] Update querying.md
- [ ] Update schema-introspection.md
- [ ] Rename database-specific docs
- [ ] Create MIGRATION_V2.md
- [ ] Create driver-development.md
- [ ] Update composer.json metadata

### Phase 8: Polish ✓
- [ ] Run PHPStan analysis
- [ ] Fix style issues
- [ ] Update CHANGELOG.md
- [ ] Version bump to 2.0.0
- [ ] Final test run
- [ ] Tag release

---

## Timeline Summary

| Week | Days | Focus |
|------|------|-------|
| Week 1 | 1-5 | Driver abstraction + File renaming |
| Week 2 | 6-10 | Connection refactoring + Schema abstraction |
| Week 3 | 11-15 | Config + Tests + Documentation |
| Week 4 | 16-18 | Final polish + Release |

---

## Success Metrics

- ✅ All 1,470+ tests passing
- ✅ GraphDriverInterface fully implemented
- ✅ Neo4jDriver working with full functionality
- ✅ All 224+ files renamed and updated
- ✅ All documentation current
- ✅ PHPStan level 9 passing
- ✅ Code style compliant
- ✅ Migration guide complete
- ✅ v2.0.0 released

---

## Future Roadmap (v2.1+)

### v2.1: Memgraph Driver
- Implement `MemgraphDriver`
- Memgraph-specific optimizations
- Multi-database CI testing
- Timeline: 2-3 weeks

### v2.2: AGE Driver
- Implement `AgeDriver` (PostgreSQL extension)
- AGE-specific considerations
- Performance benchmarking
- Timeline: 3-4 weeks

### v2.3: Performance Optimizations
- Database-specific query optimizations
- Connection pooling improvements
- Caching strategies
- Timeline: 2 weeks

---

## Risk Mitigation

### Risk: Test Failures After Refactoring
**Mitigation**:
- Rename files first, verify git tracking
- Run Rector incrementally
- Test after each major change
- Keep rollback commits

### Risk: Performance Degradation
**Mitigation**:
- Minimal abstraction layer overhead
- Benchmark critical paths
- Profile before and after
- Optimize hot paths

### Risk: Breaking User Code
**Mitigation**:
- Comprehensive migration guide
- Clear CHANGELOG
- Examples for all changes
- Beta release for testing

### Risk: Incomplete Abstractions
**Mitigation**:
- Start with Neo4j only
- Validate interface design
- Document extension points
- Plan for future databases

---

## Notes

- **Environment Variables**: NEO4J_* variables remain unchanged for Neo4j driver
- **Backward Compatibility**: No aliases in v2.0 - clean break
- **Testing**: Always run tests sequentially, never in parallel
- **Documentation**: Keep all docs in sync with code changes
- **Git History**: Use `git mv` to preserve file history

---

## Contact

For questions or issues during implementation:
- Open issue on GitHub
- Review specs/ directory for detailed plans
- Consult CLAUDE.md for project context
