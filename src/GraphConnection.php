<?php

namespace Look\EloquentCypher;

use Illuminate\Database\Connection;
use Laudis\Neo4j\Databags\Statement;
use Look\EloquentCypher\Contracts\GraphDriverInterface;
use Look\EloquentCypher\Contracts\TransactionInterface;
use Look\EloquentCypher\Drivers\DriverManager;
use Look\EloquentCypher\Query\ResponseTransformer;
use Look\EloquentCypher\Support\LabelResolver;

class GraphConnection extends Connection
{
    protected GraphDriverInterface $driver;

    /**
     * Backward compatibility: Reference to driver's client (for tests).
     * Note: This is dynamically populated via __get magic method.
     */
    protected $neo4jClient = null;

    /**
     * The active graph database transaction instance.
     */
    protected ?TransactionInterface $currentTransaction = null;

    /**
     * The number of active transactions.
     */
    protected int $transactionLevel = 0;

    /**
     * Connection pool configuration.
     */
    protected array $poolConfig = [];

    /**
     * Retry configuration.
     */
    protected array $retryConfig = [];

    /**
     * Whether the connection is lazy (not yet connected).
     */
    protected bool $lazy = true;

    /**
     * Whether APOC procedures are available.
     */
    protected ?bool $hasApoc = null;

    /**
     * APOC version if available.
     */
    protected ?string $apocVersion = null;

    /**
     * Whether to use APOC for JSON operations when available.
     */
    protected bool $useApocForJson = true;

    /**
     * Whether the connection is lazy (not yet connected).
     */
    protected bool $isLazy = false;

    /**
     * Whether the connection has been established.
     */
    protected bool $connected = false;

    /**
     * Connection pool statistics.
     */
    protected array $poolStats = [
        'total_connections' => 0,
        'active_connections' => 0,
        'peak_connections' => 0,
    ];

    /**
     * Read preference configuration.
     */
    protected ?string $readPreference = null;

    /**
     * Response transformer for handling Neo4j responses.
     */
    protected ResponseTransformer $responseTransformer;

    /**
     * Label resolver for handling label prefixing.
     */
    protected LabelResolver $labelResolver;

    /**
     * Indicates whether queries are being logged.
     */
    protected $loggingQueries = false;

    /**
     * All of the queries that have been run.
     */
    protected $queryLog = [];

    public function __construct($config)
    {
        parent::__construct(null, '', $config['database'] ?? '', $config);

        // Store pool configuration
        $this->poolConfig = $config['pool'] ?? [];
        $this->retryConfig = $config['retry'] ?? [];
        $this->isLazy = $config['lazy'] ?? false;
        $this->readPreference = $config['read_preference'] ?? null;
        $this->useApocForJson = $config['use_apoc_for_json'] ?? true;
        $this->responseTransformer = new ResponseTransformer;

        // Initialize label resolver with configured prefix (like table_prefix in MySQL)
        $labelPrefix = $config['label_prefix'] ?? null;
        // Fall back to environment variable for backwards compatibility
        if (! $labelPrefix && getenv('TEST_NAMESPACE')) {
            $labelPrefix = getenv('TEST_NAMESPACE');
        }
        $this->labelResolver = new LabelResolver($labelPrefix);

        // Initialize driver using DriverManager
        if (! $this->isLazy) {
            $this->initializeDriver();
        }
    }

    protected function initializeDriver(): void
    {
        // Create driver instance using DriverManager
        $this->driver = DriverManager::create($this->config);

        // Populate neo4jClient for backward compatibility
        $this->syncNeo4jClientReference();

        // Configure connection pooling if enabled
        if (! empty($this->poolConfig['enabled'])) {
            // Track statistics for visibility
            $this->poolStats['total_connections'] = 1;
            $this->poolStats['active_connections'] = 1;
            $this->poolStats['peak_connections'] = 1;
        }

        $this->connected = true;
    }

    /**
     * Sync the neo4jClient reference from the driver (for backward compatibility).
     */
    protected function syncNeo4jClientReference(): void
    {
        if (isset($this->driver)) {
            $reflection = new \ReflectionClass($this->driver);
            if ($reflection->hasProperty('client')) {
                $property = $reflection->getProperty('client');
                $property->setAccessible(true);
                $this->neo4jClient = $property->getValue($this->driver);
            }
        }
    }

    public function getDefaultQueryGrammar()
    {
        // Grammar functionality is now integrated into Neo4jQueryBuilder
        return null;
    }

    public function table($table, $as = null)
    {
        return $this->query()->from($table);
    }

    public function query()
    {
        $builder = new \Look\EloquentCypher\GraphQueryBuilder($this, null, null);
        $builder->setLabelResolver($this->labelResolver);

        return $builder;
    }

    /**
     * Get the label resolver instance.
     */
    public function getLabelResolver(): LabelResolver
    {
        return $this->labelResolver;
    }

    /**
     * Update the label prefix for the connection.
     * This is useful for test isolation in parallel execution.
     */
    public function setLabelPrefix(?string $prefix): void
    {
        $this->labelResolver->setPrefix($prefix);
    }

    /**
     * Get the configured label prefix.
     */
    public function getLabelPrefix(): ?string
    {
        return $this->labelResolver->getPrefix();
    }

    public function select($query, $bindings = [], $useReadPdo = true)
    {
        // Ensure connection is established for lazy connections
        if (! $this->connected && $this->isLazy) {
            $this->initializeDriver();
        }

        return $this->run($query, $bindings, function ($query, $bindings) {
            // Use transaction if one is active, otherwise use driver
            if ($this->currentTransaction) {
                $results = $this->currentTransaction->run($query, $bindings);
            } else {
                $results = $this->driver->executeQuery($query, $bindings);
            }

            return $this->responseTransformer->transformResultSet($results)->toArray();
        });
    }

    public function insert($query, $bindings = [])
    {
        return $this->affectingStatement($query, $bindings);
    }

    /**
     * Process an insert and get the last inserted ID
     * (Replaces functionality from Neo4jProcessor)
     *
     * @param  string  $query
     * @param  array  $bindings
     * @param  string|null  $sequence
     * @return int|null
     */
    public function processInsertGetId($query, $bindings = [], $sequence = null)
    {
        $this->insert($query, $bindings);

        // Get the last inserted ID
        $cypher = 'MATCH (n) RETURN id(n) as id ORDER BY id(n) DESC LIMIT 1';
        $result = $this->select($cypher);

        return ! empty($result) ? $result[0]['id'] : null;
    }

    public function update($query, $bindings = [])
    {
        return $this->affectingStatement($query, $bindings);
    }

    public function delete($query, $bindings = [])
    {
        return $this->affectingStatement($query, $bindings);
    }

    public function affectingStatement($query, $bindings = [])
    {
        // Ensure connection is established for lazy connections
        if (! $this->connected && $this->isLazy) {
            $this->initializeDriver();
        }

        return $this->run($query, $bindings, function ($query, $bindings) {
            // Use transaction if one is active, otherwise use driver
            if ($this->currentTransaction) {
                $result = $this->currentTransaction->run($query, $bindings);
            } else {
                $result = $this->driver->executeQuery($query, $bindings);
            }

            // Get the summary to access statistics
            $summary = $result->getSummary();
            // If query returns an 'affected' count, use that
            if (stripos($query, 'RETURN') !== false && stripos($query, 'as affected') !== false) {
                try {
                    $first = $result->first();
                    if ($first) {
                        $affected = $first->get('affected') ?? 0;

                        return intval($affected);
                    }
                } catch (\Exception $e) {
                    // Fallback to counters
                }
            }

            $counters = $summary->getCounters();

            // For UPDATE queries (SET with RETURN node), use result count instead of propertiesSet
            // This ensures updated events fire correctly even when values don't change
            if (stripos($query, 'SET') !== false &&
                stripos($query, 'RETURN') !== false &&
                stripos($query, 'as affected') === false &&
                stripos($query, 'RETURN n') !== false) {
                return $result->count();
            }

            // Return the appropriate count based on the query type
            if (stripos($query, 'DELETE') !== false) {
                return $counters->nodesDeleted();
            } elseif (stripos($query, 'CREATE') !== false) {
                return $counters->nodesCreated();
            } elseif (stripos($query, 'SET') !== false) {
                return $counters->propertiesSet() > 0 ? 1 : 0;
            }

            // Default to result count for compatibility
            return $result->count();
        });
    }

    public function statement($query, $bindings = [])
    {
        // Ensure connection is established for lazy connections
        if (! $this->connected && $this->isLazy) {
            $this->initializeDriver();
        }

        return $this->run($query, $bindings, function ($query, $bindings) {
            // Use transaction if one is active, otherwise use driver
            if ($this->currentTransaction) {
                $this->currentTransaction->run($query, $bindings);
            } else {
                $this->driver->executeQuery($query, $bindings);
            }

            return true;
        });
    }

    /**
     * Execute multiple statements in a batch for improved performance.
     *
     * @param  array  $statements  An array of statements to execute
     * @return array Array of results for each statement
     */
    public function statements(array $statements): array
    {
        // Ensure connection is established for lazy connections
        if (! $this->connected && $this->isLazy) {
            $this->initializeDriver();
        }

        if (empty($statements)) {
            return [];
        }

        // Normalize statements to array format
        $normalizedStatements = [];
        foreach ($statements as $statement) {
            if ($statement instanceof Statement) {
                $parameters = $statement->getParameters();
                // Handle parameters being either array or CypherMap
                if (is_object($parameters) && method_exists($parameters, 'toArray')) {
                    $parameters = $parameters->toArray();
                }
                $normalizedStatements[] = [
                    'cypher' => $statement->getText(),
                    'parameters' => $parameters,
                ];
            } elseif (is_array($statement)) {
                $normalizedStatements[] = [
                    'cypher' => $statement['query'] ?? '',
                    'parameters' => $statement['parameters'] ?? [],
                ];
            } else {
                throw new \InvalidArgumentException('Each statement must be an array or Statement object');
            }
        }

        try {
            // Use transaction if one is active, otherwise use driver
            if ($this->currentTransaction) {
                // Execute within existing transaction
                $results = [];
                foreach ($normalizedStatements as $stmt) {
                    $result = $this->currentTransaction->run($stmt['cypher'], $stmt['parameters']);
                    $results[] = $this->responseTransformer->transformResultSet($result)->toArray();
                }

                return $results;
            } else {
                // Execute as batch using driver
                $resultSets = $this->driver->executeBatch($normalizedStatements);

                $results = [];
                foreach ($resultSets as $result) {
                    $results[] = $this->responseTransformer->transformResultSet($result)->toArray();
                }

                return $results;
            }
        } catch (\Throwable $e) {
            throw new \Look\EloquentCypher\Exceptions\GraphConnectionException(
                'Batch statement execution failed: '.$e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    public function getDriver(): GraphDriverInterface
    {
        return $this->driver;
    }

    /**
     * Execute a raw Cypher query or return DSL builder.
     *
     * @param  string|null  $query  Cypher query string, or null to get DSL builder
     * @param  array  $bindings  Query bindings
     * @return mixed Results array or Neo4jCypherDslBuilder
     */
    public function cypher($query = null, $bindings = [])
    {
        // New: No args = return DSL builder
        if ($query === null) {
            return new \Look\EloquentCypher\Builders\GraphCypherDslBuilder($this);
        }

        // Existing: String query = execute
        return $this->select($query, $bindings);
    }

    /**
     * Start a new database transaction.
     *
     * @return void
     *
     * @throws \Throwable
     */
    public function beginTransaction()
    {
        $this->transactionLevel++;

        if ($this->transactionLevel == 1) {
            try {
                $this->currentTransaction = $this->driver->beginTransaction();
            } catch (\Throwable $e) {
                $this->handleBeginTransactionException($e);
            }
        }

        $this->fireConnectionEvent('beganTransaction');
    }

    /**
     * Commit the active database transaction.
     *
     * @return void
     *
     * @throws \Throwable
     */
    public function commit()
    {
        if ($this->transactionLevel == 1 && $this->currentTransaction) {
            try {
                $this->driver->commit($this->currentTransaction);
            } catch (\Throwable $e) {
                $this->handleCommitTransactionException($e, 1, 1);
            }
        }

        $this->transactionLevel = max(0, $this->transactionLevel - 1);

        if ($this->transactionLevel == 0) {
            $this->currentTransaction = null;
        }

        $this->fireConnectionEvent('committed');
    }

    /**
     * Rollback the active database transaction.
     *
     * @return void
     *
     * @throws \Throwable
     */
    public function rollBack($toLevel = null)
    {
        // We allow setting the transaction level to a specific value or decrementing it.
        $toLevel = is_null($toLevel)
            ? $this->transactionLevel - 1
            : $toLevel;

        if ($toLevel < 0) {
            $toLevel = 0;
        }

        if ($toLevel == 0 && $this->currentTransaction) {
            try {
                $this->driver->rollback($this->currentTransaction);
            } catch (\Throwable $e) {
                $this->handleRollBackException($e);
            }
            $this->currentTransaction = null;
        }

        $this->transactionLevel = $toLevel;

        $this->fireConnectionEvent('rollingBack');
    }

    /**
     * Get the number of active transactions.
     *
     * @return int
     */
    public function transactionLevel()
    {
        return $this->transactionLevel;
    }

    /**
     * Handle an exception encountered when beginning a transaction.
     *
     * @return void
     *
     * @throws \Throwable
     */
    protected function handleBeginTransactionException(\Throwable $e)
    {
        if ($this->causedByConcurrencyError($e)) {
            $this->transactionLevel--;

            throw $e;
        }

        $this->transactionLevel--;

        throw $e;
    }

    /**
     * Handle an exception encountered when committing a transaction.
     *
     * @param  int  $currentAttempt
     * @param  int  $maxAttempts
     * @return void
     *
     * @throws \Throwable
     */
    protected function handleCommitTransactionException(\Throwable $e, $currentAttempt, $maxAttempts)
    {
        $this->transactionLevel = max(0, $this->transactionLevel - 1);

        if ($this->causedByConcurrencyError($e) && $currentAttempt < $maxAttempts) {
            return;
        }

        throw $e;
    }

    /**
     * Handle an exception encountered when rolling back a transaction.
     *
     * @return void
     *
     * @throws \Throwable
     */
    protected function handleRollBackException(\Throwable $e)
    {
        // If the transaction was already terminated (e.g., due to an error during execution),
        // we don't need to throw the rollback error - just log it or ignore it
        if (str_contains($e->getMessage(), "Can't rollback, transaction has been terminated")) {
            // Transaction was already terminated - this is expected when an error occurred
            // during statement execution. We can safely ignore this.
            return;
        }

        // For other rollback errors, throw them
        throw $e;
    }

    /**
     * Validate that required credentials are present in configuration.
     *
     * @throws \Look\EloquentCypher\Exceptions\GraphConnectionException
     */
    protected function validateCredentials(array $config, string $connectionType = 'default'): void
    {
        if (empty($config['username'])) {
            throw \Look\EloquentCypher\Exceptions\GraphConnectionException::missingCredentials(
                'username',
                $connectionType
            );
        }

        if (empty($config['password'])) {
            throw \Look\EloquentCypher\Exceptions\GraphConnectionException::missingCredentials(
                'password',
                $connectionType
            );
        }
    }

    /**
     * Determine if the given exception was caused by a concurrency error such as a deadlock or serialization failure.
     *
     * @param  \Throwable  $e
     * @return bool
     */
    protected function causedByConcurrencyError($e)
    {
        // Enhanced error detection with more patterns and error codes
        $message = $e->getMessage();
        $code = $e->getCode();

        // Check message patterns
        $patterns = [
            'DeadlockDetected',
            'LockClient',
            'ForsetiClient',
            'deadlock',
            'LockAcquisitionTimeout',
            'concurrent modification',
            'serialization failure',
            'lock timeout',
            'Neo.TransientError.Transaction',
        ];

        foreach ($patterns as $pattern) {
            if (stripos($message, $pattern) !== false) {
                return true;
            }
        }

        // Check Neo4j error codes if available
        if ($e instanceof \Laudis\Neo4j\Exception\Neo4jException) {
            // Laudis exception might have the code in the message
            if (strpos($message, 'Neo.TransientError') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Classify the type of exception for better error handling.
     *
     * @return string One of: 'transient', 'network', 'authentication', 'constraint', 'query', 'transaction', 'unknown'
     */
    protected function classifyException(\Throwable $e): string
    {
        $message = $e->getMessage();

        // Authentication errors
        if (stripos($message, 'unauthorized') !== false ||
            stripos($message, 'authentication') !== false ||
            stripos($message, 'Invalid username or password') !== false ||
            ($e instanceof \Laudis\Neo4j\Exception\Neo4jException && stripos($message, 'Security.Unauthorized') !== false)) {
            return 'authentication';
        }

        // Network errors
        if (stripos($message, 'Connection timeout') !== false ||
            stripos($message, 'Connection refused') !== false ||
            stripos($message, 'Network is unreachable') !== false ||
            stripos($message, 'Connection reset') !== false ||
            stripos($message, 'Connection pool is closed') !== false) {
            return 'network';
        }

        // Constraint violations
        if (stripos($message, 'already exists with') !== false ||
            stripos($message, 'constraint') !== false ||
            ($e instanceof \Laudis\Neo4j\Exception\Neo4jException && stripos($message, 'Schema.ConstraintValidation') !== false)) {
            return 'constraint';
        }

        // Check for specific Neo4j transient error codes first
        if (strpos($message, 'Neo.TransientError') !== false) {
            return 'transient';
        }

        // Transaction errors (non-transient)
        if (stripos($message, 'Transaction has been terminated') !== false ||
            stripos($message, 'Transaction rolled back') !== false) {
            return 'transaction';
        }

        // Other transient errors (retryable)
        if ($this->causedByConcurrencyError($e) ||
            stripos($message, 'DeadlockDetected') !== false ||
            stripos($message, 'LockAcquisitionTimeout') !== false) {
            return 'transient';
        }

        // Query errors
        if (stripos($message, 'Invalid input') !== false ||
            stripos($message, 'Unknown function') !== false ||
            stripos($message, 'syntax') !== false ||
            ($e instanceof \Laudis\Neo4j\Exception\Neo4jException && stripos($message, 'Statement.SyntaxError') !== false)) {
            return 'query';
        }

        return 'unknown';
    }

    /**
     * Determine if an exception is retryable.
     */
    protected function isRetryable(\Throwable $e): bool
    {
        $classification = $this->classifyException($e);

        return in_array($classification, ['transient', 'network']);
    }

    /**
     * Determine if the connection should be reconnected based on the exception.
     */
    protected function shouldReconnect(\Throwable $e): bool
    {
        $message = $e->getMessage();

        $stalePatterns = [
            'Connection pool is closed',
            'Connection is stale',
            'Socket has been closed',
            'Connection reset by peer',
            'Broken pipe',
        ];

        foreach ($stalePatterns as $pattern) {
            if (stripos($message, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reconnect to the database.
     *
     * @throws \Look\EloquentCypher\Exceptions\GraphAuthenticationException
     */
    public function reconnect(): void
    {
        $this->disconnect();
        $this->isStale = false;
        $this->connected = false;

        try {
            $this->initializeDriver();
        } catch (\Throwable $e) {
            if ($this->classifyException($e) === 'authentication') {
                throw new \Look\EloquentCypher\Exceptions\GraphAuthenticationException(
                    $e->getMessage(),
                    $e->getCode(),
                    $e
                );
            }
            throw $e;
        }
    }

    /**
     * Set the connection configuration.
     */
    public function setConfig(array $config): void
    {
        $this->config = $config;
    }

    /**
     * Reconnect if the connection is stale.
     */
    public function reconnectIfStale(): void
    {
        if ($this->isStale ?? false) {
            $this->reconnect();
        }
    }

    /**
     * Check if the connection is healthy.
     */
    public function ping(): bool
    {
        try {
            if ($this->isStale ?? false) {
                return false;
            }

            $result = $this->select('RETURN 1 as ping');

            return ! empty($result) && ($result[0]->ping ?? $result[0]['ping'] ?? 0) === 1;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Calculate backoff delay for retries.
     *
     * @return int Delay in milliseconds
     */
    protected function calculateBackoffDelay(int $attempt, array $config = []): int
    {
        $config = array_merge([
            'initial_delay_ms' => 100,
            'max_delay_ms' => 5000,
            'multiplier' => 2.0,
            'jitter' => false,
        ], $config);

        $baseDelay = $config['initial_delay_ms'] * pow($config['multiplier'], $attempt);
        $delay = min($baseDelay, $config['max_delay_ms']);

        if ($config['jitter']) {
            // Add random jitter between 50% and 150% of base delay
            $jitterMin = $delay * 0.5;
            $jitterMax = $delay * 1.5;
            $delay = rand((int) $jitterMin, (int) $jitterMax);
        }

        return (int) $delay;
    }

    /**
     * Wrap an exception with additional context.
     */
    protected function wrapException(\Throwable $e, string $query = '', array $parameters = []): \Look\EloquentCypher\Exceptions\GraphException
    {
        $classification = $this->classifyException($e);
        $message = $e->getMessage();

        // Create appropriate exception type based on classification
        switch ($classification) {
            case 'transient':
                $wrapped = new \Look\EloquentCypher\Exceptions\GraphTransientException($message, $e->getCode(), $e, $query, $parameters);
                break;

            case 'network':
                $wrapped = new \Look\EloquentCypher\Exceptions\GraphNetworkException($message, $e->getCode(), $e, $query, $parameters);
                break;

            case 'authentication':
                $wrapped = new \Look\EloquentCypher\Exceptions\GraphAuthenticationException($message, $e->getCode(), $e, $query, $parameters);
                break;

            case 'constraint':
                $wrapped = new \Look\EloquentCypher\Exceptions\GraphConstraintException($message, $e->getCode(), $e);
                $wrapped->setCypher($query)->setParameters($parameters);

                // Add specific hint for constraint violations
                if (stripos($message, 'already exists with property') !== false) {
                    $wrapped->setMigrationHint(
                        "A unique constraint violation occurred. To fix this:\n".
                        "1. Use MERGE instead of CREATE for upserts\n".
                        "2. Check for existing nodes before creating\n".
                        '3. Create a unique constraint: CREATE CONSTRAINT FOR (n:Label) REQUIRE n.property IS UNIQUE'
                    );
                }
                break;

            case 'query':
                $wrapped = new \Look\EloquentCypher\Exceptions\GraphQueryException($message, $e->getCode(), $e);
                $wrapped->setCypher($query)->setParameters($parameters);

                // Add hint for missing APOC functions
                if (stripos($message, 'Unknown function') !== false &&
                    (stripos($message, 'json') !== false || stripos($query, 'json') !== false)) {
                    $wrapped->setMigrationHint(
                        "This query requires APOC plugin for JSON operations.\n".
                        "Install APOC: https://neo4j.com/labs/apoc/\n".
                        "Use apoc.json functions for JSON operations.\n".
                        'Or use the package without JSON operations (they will use fallback string matching).'
                    );
                }
                break;

            case 'transaction':
                $wrapped = new \Look\EloquentCypher\Exceptions\GraphTransactionException($message, $e->getCode(), $e);
                $wrapped->setCypher($query)->setParameters($parameters);
                break;

            default:
                $wrapped = new \Look\EloquentCypher\Exceptions\GraphException($message, $e->getCode(), $e);
                $wrapped->setCypher($query)->setParameters($parameters);
        }

        return $wrapped;
    }

    /**
     * Mark the connection as stale.
     */
    protected bool $isStale = false;

    /**
     * Execute a select statement with automatic retry on transient errors.
     */
    public function selectWithRetry(string $query, array $bindings = [], int $maxRetries = 3): array
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < $maxRetries) {
            try {
                if ($attempt > 0 && $this->shouldReconnect($lastException)) {
                    $this->reconnect();
                }

                return $this->select($query, $bindings);
            } catch (\Throwable $e) {
                $lastException = $e;

                if (! $this->isRetryable($e) || $attempt >= $maxRetries - 1) {
                    throw $this->wrapException($e, $query, $bindings);
                }

                $delay = $this->calculateBackoffDelay($attempt, $this->retryConfig);
                usleep($delay * 1000); // Convert ms to microseconds
                $attempt++;
            }
        }

        throw $this->wrapException($lastException, $query, $bindings);
    }

    /**
     * Get the schema builder for the connection.
     *
     * @return \Look\EloquentCypher\Schema\GraphSchemaBuilder
     */
    public function getSchemaBuilder()
    {
        if (is_null($this->schemaGrammar)) {
            $this->useDefaultSchemaGrammar();
        }

        return new \Look\EloquentCypher\Schema\GraphSchemaBuilder($this);
    }

    /**
     * Get the default schema grammar instance.
     *
     * @return \Look\EloquentCypher\Schema\GraphSchemaGrammar
     */
    protected function getDefaultSchemaGrammar()
    {
        return new \Look\EloquentCypher\Schema\GraphSchemaGrammar;
    }

    /**
     * Check if connection has read/write splitting enabled.
     */
    public function hasReadWriteSplit(): bool
    {
        return isset($this->config['read']) && isset($this->config['write']);
    }

    /**
     * Get the connection pool configuration.
     */
    public function getPoolConfig(): array
    {
        return $this->poolConfig;
    }

    /**
     * Get the retry configuration.
     */
    public function getRetryConfig(): array
    {
        return $this->retryConfig;
    }

    /**
     * Check if the connection has been established.
     */
    public function isConnected(): bool
    {
        return $this->connected;
    }

    /**
     * Get connection pool statistics.
     */
    public function getPoolStats(): array
    {
        // Update statistics based on pool configuration
        if (! empty($this->poolConfig['enabled'])) {
            // Track current active connections
            if ($this->connected) {
                $activeCount = 1; // One driver connection
                $this->poolStats['active_connections'] = min($activeCount, $this->poolConfig['max_connections'] ?? 10);
                $this->poolStats['total_connections'] = $this->poolStats['active_connections'];
                $this->poolStats['peak_connections'] = max(
                    $this->poolStats['peak_connections'],
                    $this->poolStats['active_connections']
                );
            }
        }

        return $this->poolStats;
    }

    /**
     * Get the read preference setting.
     */
    public function getReadPreference(): ?string
    {
        return $this->readPreference;
    }

    /**
     * Enable the query log.
     */
    public function enableQueryLog(): void
    {
        $this->loggingQueries = true;
    }

    /**
     * Disable the query log.
     */
    public function disableQueryLog(): void
    {
        $this->loggingQueries = false;
    }

    /**
     * Determine whether we're logging queries.
     */
    public function logging(): bool
    {
        return $this->loggingQueries;
    }

    /**
     * Get the connection query log.
     */
    public function getQueryLog(): array
    {
        return $this->queryLog;
    }

    /**
     * Clear the query log.
     */
    public function flushQueryLog(): void
    {
        $this->queryLog = [];
    }

    /**
     * Reset the transaction level for test isolation.
     */
    public function resetTransactionLevel(): void
    {
        $this->transactionLevel = 0;
    }

    /**
     * Log a query in the connection's query log.
     *
     * @param  string  $query
     * @param  array  $bindings
     * @param  float|null  $time
     * @return void
     */
    public function logQuery($query, $bindings = [], $time = null)
    {
        if ($this->loggingQueries) {
            $this->queryLog[] = compact('query', 'bindings', 'time');
        }
    }

    /**
     * Check if APOC procedures are available.
     */
    public function hasAPOC(): bool
    {
        if ($this->hasApoc === null) {
            $this->detectAPOC();
        }

        return $this->hasApoc;
    }

    /**
     * Get the APOC version if available.
     */
    public function getAPOCVersion(): ?string
    {
        if ($this->hasApoc === null) {
            $this->detectAPOC();
        }

        return $this->apocVersion;
    }

    /**
     * Check if APOC should be used for JSON operations.
     * Returns true only if APOC is both available AND enabled in config.
     */
    public function shouldUseApocForJson(): bool
    {
        return $this->useApocForJson && $this->hasAPOC();
    }

    /**
     * Detect if APOC procedures are available.
     */
    protected function detectAPOC(): void
    {
        // Use driver capabilities to detect APOC support
        $this->hasApoc = $this->driver->getCapabilities()->supportsJsonOperations();

        // Try to get the version if APOC is available
        if ($this->hasApoc) {
            try {
                $result = $this->select('RETURN apoc.version() as version');
                $this->apocVersion = $result[0]['version'] ?? $result[0]->version ?? null;
            } catch (\Exception $e) {
                $this->apocVersion = null;
            }
        } else {
            $this->apocVersion = null;
        }
    }

    /**
     * Execute a write transaction with automatic retry logic.
     * Optimized for Neo4j cluster write operations.
     */
    public function write(callable $callback, ?int $maxRetries = null)
    {
        $maxRetries = $maxRetries ?? $this->retryConfig['max_attempts'] ?? 3;
        $lastException = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                // Begin a transaction
                $this->beginTransaction();

                // Execute the callback within the transaction
                $result = $callback($this);

                // Commit the transaction
                $this->commit();

                return $result;
            } catch (\Throwable $e) {
                // Rollback the transaction
                try {
                    $this->rollBack();
                } catch (\Throwable $rollbackException) {
                    // Log rollback failure but continue with original exception
                }

                $lastException = $e;

                // Check if this is a transient error that should be retried
                if (! $this->isTransientError($e) || $attempt >= $maxRetries) {
                    throw $e;
                }

                // Apply exponential backoff before retry
                if ($attempt < $maxRetries) {
                    $this->applyBackoffDelay($attempt);
                }
            }
        }

        throw $lastException;
    }

    /**
     * Execute a read transaction with automatic retry logic.
     * Optimized for Neo4j cluster read operations.
     */
    public function read(callable $callback, ?int $maxRetries = null)
    {
        $maxRetries = $maxRetries ?? $this->retryConfig['max_attempts'] ?? 3;
        $lastException = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                // For read transactions, we don't need explicit transaction management
                // but we still want retry logic for network/transient errors
                return $callback($this);
            } catch (\Throwable $e) {
                $lastException = $e;

                // Check if this is a transient error that should be retried
                if (! $this->isTransientError($e) || $attempt >= $maxRetries) {
                    throw $e;
                }

                // Apply exponential backoff before retry
                if ($attempt < $maxRetries) {
                    $this->applyBackoffDelay($attempt);
                }
            }
        }

        throw $lastException;
    }

    /**
     * Determine if an exception is a transient error that should be retried.
     */
    protected function isTransientError(\Throwable $e): bool
    {
        // First check if it's a concurrency error (existing method)
        if ($this->causedByConcurrencyError($e)) {
            return true;
        }

        $message = strtolower($e->getMessage());

        // Check for additional transient patterns
        $transientPatterns = [
            'timeout',
            'connection refused',
            'connection reset',
            'broken pipe',
            'network is unreachable',
            'unable to connect',
            'socket exception',
            'temporarily unavailable',
            'transient',
        ];

        foreach ($transientPatterns as $pattern) {
            if (str_contains($message, $pattern)) {
                return true;
            }
        }

        // Check for Neo4j transient error codes
        if ($e instanceof \Laudis\Neo4j\Exception\Neo4jException) {
            $code = (string) $e->getCode();
            // Neo4j transient errors typically start with specific prefixes
            if (str_starts_with($code, 'Neo.TransientError.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Apply exponential backoff delay between retry attempts.
     */
    protected function applyBackoffDelay(int $attempt): void
    {
        $config = $this->retryConfig;
        $initialDelay = $config['initial_delay_ms'] ?? 100;
        $maxDelay = $config['max_delay_ms'] ?? 5000;
        $multiplier = $config['multiplier'] ?? 2.0;
        $useJitter = $config['jitter'] ?? true;

        // Calculate exponential backoff
        $delay = $initialDelay * pow($multiplier, $attempt - 1);
        $delay = min($delay, $maxDelay);

        // Apply jitter to prevent thundering herd
        if ($useJitter) {
            $jitterRange = $delay * 0.5;
            $delay = $delay + mt_rand((int) -$jitterRange, (int) $jitterRange);
            $delay = max(0, $delay);
        }

        // Convert milliseconds to microseconds and sleep
        usleep((int) ($delay * 1000));
    }

    /**
     * Magic getter for backward compatibility.
     * Provides access to driver's internal client via 'neo4jClient' property name.
     */
    public function __get(string $name)
    {
        if ($name === 'neo4jClient' && isset($this->driver)) {
            // Access the client from the Neo4jDriver
            $reflection = new \ReflectionClass($this->driver);
            if ($reflection->hasProperty('client')) {
                $property = $reflection->getProperty('client');
                $property->setAccessible(true);

                return $property->getValue($this->driver);
            }
        }

        // Fall back to parent behavior
        if (method_exists(parent::class, '__get')) {
            return parent::__get($name);
        }

        trigger_error("Undefined property: ".get_class($this)."::\${$name}", E_USER_NOTICE);

        return null;
    }
}
