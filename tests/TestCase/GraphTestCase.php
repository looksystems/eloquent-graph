<?php

namespace Tests\TestCase;

use Laudis\Neo4j\Authentication\Authenticate;
use Laudis\Neo4j\ClientBuilder;
use Laudis\Neo4j\Contracts\ClientInterface;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Tests\TestCase\Assertions\GraphAssertions;

abstract class GraphTestCase extends BaseTestCase
{
    use GraphAssertions;

    protected ClientInterface $neo4jClient;

    protected ?string $testNamespace = null;

    /**
     * Track registered DB listeners for precise cleanup
     */
    protected array $registeredListeners = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Clear any event listeners from previous tests
        $this->clearModelEventListeners();

        // Force connection recreation for test isolation
        $this->recreateNeo4jConnection();

        $this->setupNeo4jConnection();
        $this->clearNeo4jDatabase();

        // Create a fresh event dispatcher for this test to ensure isolation
        $testEventDispatcher = clone $this->app['events'];

        // Set the event dispatcher for models
        \Look\EloquentCypher\GraphModel::setEventDispatcher($testEventDispatcher);
    }

    protected function tearDown(): void
    {
        // Reset any active transactions FIRST
        $this->resetTransactions();

        // Clear database after transactions are reset
        $this->clearNeo4jDatabase();

        // Flush all event listeners for test models to prevent pollution
        $this->clearModelEventListeners();

        // Clear model states
        $this->clearModelStates();

        // Clear any registered DB listeners
        $this->clearRegisteredListeners();

        // Verify cleanup was successful
        $this->verifyCleanup();

        // Close and reset connection
        $this->resetNeo4jConnection();

        parent::tearDown();
    }

    protected function clearModelEventListeners(): void
    {
        $models = [
            '\Tests\Models\User',
            '\Tests\Models\Post',
            '\Tests\Models\Comment',
            '\Tests\Models\Role',
            '\Tests\Models\Profile',
            '\Tests\Models\Image',
            '\Tests\Models\Video',
            '\Tests\Models\AdminUser',
            '\Tests\Models\UserWithCasting',
            '\Tests\Models\UserWithSoftDeletes',
            '\Tests\Models\PostWithSoftDeletes',
            '\Tests\Models\CommentWithSoftDeletes',
            '\Tests\Models\RoleWithSoftDeletes',
            '\Tests\Models\ProfileWithSoftDeletes',
            '\Tests\Models\TagWithSoftDeletes',
            '\Tests\Models\Product',
            '\Tests\Models\Tag',
            '\Tests\Models\Taggable',
            // Native relationship models (using graph edges)
            '\Tests\Models\NativeUser',
            '\Tests\Models\NativePost',
            '\Tests\Models\NativeComment',
            '\Tests\Models\NativeProfile',
            '\Tests\Models\NativeImage',
            '\Tests\Models\NativeVideo',
            '\Tests\Models\NativeAuthor',
            '\Tests\Models\NativeBook',
        ];

        foreach ($models as $model) {
            if (class_exists($model)) {
                // Flush event listeners
                $model::flushEventListeners();

                // Also unset the event dispatcher to ensure complete isolation
                $model::unsetEventDispatcher();
            }
        }
    }

    protected function setupNeo4jConnection(): void
    {
        $host = env('NEO4J_HOST', 'localhost');
        $port = env('NEO4J_PORT', 7687);
        $username = env('NEO4J_USERNAME', 'neo4j');
        $password = env('NEO4J_PASSWORD', 'password');
        $database = env('NEO4J_DATABASE', 'neo4j');

        $auth = Authenticate::basic($username, $password);

        $this->neo4jClient = ClientBuilder::create()
            ->withDriver('bolt', "bolt://{$host}:{$port}", $auth)
            ->withDefaultDriver('bolt')
            ->build();
    }

    /**
     * Clear the Neo4j database for test isolation.
     *
     * In parallel mode: Only deletes nodes with the current test's namespace prefix
     * In sequential mode: Deletes all nodes in the database
     */
    protected function clearNeo4jDatabase(): void
    {
        try {
            // Kill any existing transactions first
            $this->neo4jClient->run('SHOW TRANSACTIONS YIELD transactionId, currentQuery WHERE currentQuery <> "SHOW TRANSACTIONS YIELD *" RETURN transactionId');
        } catch (\Exception $e) {
            // Ignore if SHOW TRANSACTIONS not supported
        }

        $maxRetries = 3;
        $retryDelay = 100000; // 100ms in microseconds

        if ($this->isParallelExecution()) {
            // PARALLEL MODE: Only delete nodes with our namespace
            // This ensures complete isolation between parallel test processes
            $namespace = $this->getTestNamespace();
            $labels = $this->getTestLabels();

            // Step 1: Delete all relationships for namespaced nodes first
            $retry = 0;
            while ($retry < $maxRetries) {
                try {
                    // Delete relationships where either end has our namespace
                    foreach ($labels as $label) {
                        $namespacedLabel = $namespace.$label;
                        $this->neo4jClient->run("MATCH (n:`{$namespacedLabel}`)-[r]-() DELETE r");
                    }
                    break;
                } catch (\Exception $e) {
                    // Retry on exception
                }
                $retry++;
                if ($retry < $maxRetries) {
                    usleep($retryDelay * $retry);
                }
            }

            // Step 2: Delete all namespaced nodes
            foreach ($labels as $label) {
                $namespacedLabel = $namespace.$label;
                $retry = 0;
                while ($retry < $maxRetries) {
                    try {
                        $this->neo4jClient->run("MATCH (n:`{$namespacedLabel}`) DELETE n");

                        // Verify deletion
                        $result = $this->neo4jClient->run("MATCH (n:`{$namespacedLabel}`) RETURN count(n) as count");
                        $count = $result->first()->get('count');
                        if ($count == 0) {
                            break; // Successfully deleted
                        }
                    } catch (\Exception $e) {
                        // Retry on exception
                    }

                    $retry++;
                    if ($retry < $maxRetries) {
                        usleep($retryDelay * $retry); // Exponential backoff
                    }
                }
            }
        } else {
            // SEQUENTIAL MODE: Delete all nodes
            // Safe to clear everything since tests run one at a time
            $retry = 0;
            while ($retry < $maxRetries) {
                try {
                    // Step 1: Delete all relationships explicitly first
                    // This ensures pivot relationships and native edges are removed
                    $this->neo4jClient->run('MATCH ()-[r]-() DELETE r');

                    // Step 2: Delete all nodes (including pivot tables)
                    $this->neo4jClient->run('MATCH (n) DELETE n');

                    // Verify deletion is complete
                    $result = $this->neo4jClient->run('MATCH (n) RETURN count(n) as count');
                    $count = $result->first()->get('count');
                    if ($count == 0) {
                        break; // Successfully cleared
                    }
                } catch (\Exception $e) {
                    // Retry on exception
                }

                $retry++;
                if ($retry < $maxRetries) {
                    usleep($retryDelay * $retry); // Exponential backoff
                }
            }
        }
    }

    protected function getPackageProviders($app)
    {
        return [
            \Look\EloquentCypher\GraphServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        // Initialize test namespace first
        $this->initializeTestNamespace();

        // Use the instance-specific namespace as label prefix
        $labelPrefix = $this->testNamespace;

        // Neo4j connection configuration (shared by both 'graph' and 'neo4j' connections)
        $neo4jConfig = [
            'driver' => 'graph',
            'database_type' => 'neo4j',
            'host' => env('NEO4J_HOST', 'localhost'),
            'port' => env('NEO4J_PORT', 7687),
            'username' => env('NEO4J_USERNAME', 'neo4j'),
            'password' => env('NEO4J_PASSWORD', 'password'),
            'database' => env('NEO4J_DATABASE', 'neo4j'),
            'label_prefix' => $labelPrefix, // Use connection config instead of env var
            'pool' => [
                'enabled' => false, // Disable pooling for tests
            ],
            // Force new connections for each test in parallel mode
            'options' => [
                'persistent' => false,
            ],
        ];

        // Configure graph connection (v2.0)
        $app['config']->set('database.connections.graph', $neo4jConfig);

        // Configure neo4j connection (v1.x backward compatibility)
        $app['config']->set('database.connections.neo4j', $neo4jConfig);

        // Set default database connection
        $app['config']->set('database.default', 'graph');
    }

    protected function resetTransactions(): void
    {
        try {
            // Reset transaction state on connection if it exists
            $connection = \DB::connection('graph');
            if ($connection && method_exists($connection, 'resetTransactionLevel')) {
                $connection->resetTransactionLevel();
            }
            // Force rollback any active transaction
            if ($connection && method_exists($connection, 'transactionLevel')) {
                while ($connection->transactionLevel() > 0) {
                    $connection->rollBack();
                }
            }
        } catch (\Exception $e) {
            // Ignore errors during transaction reset
        }
    }

    protected function clearModelStates(): void
    {
        // Clear booted models to reset static state
        $models = [
            '\Tests\Models\User',
            '\Tests\Models\Post',
            '\Tests\Models\Comment',
            '\Tests\Models\Role',
            '\Tests\Models\Profile',
            '\Tests\Models\Image',
            '\Tests\Models\Video',
            '\Tests\Models\Product',
            '\Tests\Models\Tag',
            '\Tests\Models\Taggable',
            '\Tests\Models\AdminUser',
            '\Tests\Models\UserWithCasting',
            '\Tests\Models\UserWithSoftDeletes',
            '\Tests\Models\PostWithSoftDeletes',
            '\Tests\Models\CommentWithSoftDeletes',
            '\Tests\Models\RoleWithSoftDeletes',
            '\Tests\Models\ProfileWithSoftDeletes',
            '\Tests\Models\TagWithSoftDeletes',
            // Native relationship models (using graph edges)
            '\Tests\Models\NativeUser',
            '\Tests\Models\NativePost',
            '\Tests\Models\NativeComment',
            '\Tests\Models\NativeProfile',
            '\Tests\Models\NativeImage',
            '\Tests\Models\NativeVideo',
            '\Tests\Models\NativeAuthor',
            '\Tests\Models\NativeBook',
        ];

        foreach ($models as $model) {
            if (class_exists($model)) {
                // Clear booted state - Laravel's Model class has this method
                if (method_exists($model, 'clearBootedModels')) {
                    $model::clearBootedModels();
                }
                // Clear global scopes
                if (property_exists($model, 'globalScopes')) {
                    $reflection = new \ReflectionClass($model);
                    $property = $reflection->getProperty('globalScopes');
                    $property->setAccessible(true);
                    $property->setValue(null, []);
                }
            }
        }

        // Clear any DB listeners
        if (method_exists(\DB::class, 'forgetRecordedQueries')) {
            \DB::forgetRecordedQueries();
        }
    }

    protected function resetNeo4jConnection(): void
    {
        try {
            // Disconnect and purge the graph connection from Laravel's cache
            $connection = \DB::connection('graph');
            if (method_exists($connection, 'disconnect')) {
                $connection->disconnect();
            }
            \DB::purge('graph');
        } catch (\Exception $e) {
            // Ignore errors during connection purge
        }

        try {
            // Also purge the neo4j connection (backward compatibility)
            \DB::purge('neo4j');
        } catch (\Exception $e) {
            // Ignore errors during connection purge
        }
    }

    /**
     * Force recreation of the Neo4j connection for test isolation.
     * This ensures each test gets a fresh connection with the correct namespace.
     */
    protected function recreateNeo4jConnection(): void
    {
        // Purge any existing connections
        try {
            \DB::purge('graph');
        } catch (\Exception $e) {
            // Ignore if no connection exists
        }

        try {
            \DB::purge('neo4j');
        } catch (\Exception $e) {
            // Ignore if no connection exists
        }

        // Update the label prefix in the configuration
        if ($this->isParallelExecution()) {
            $this->initializeTestNamespace();
            $this->app['config']->set('database.connections.graph.label_prefix', $this->testNamespace);
            $this->app['config']->set('database.connections.neo4j.label_prefix', $this->testNamespace);
        }

        // Force Laravel to create new connections with updated config
        \DB::reconnect('graph');
        \DB::reconnect('neo4j');

        // Verify the label resolver has the correct prefix
        $connection = \DB::connection('graph');
        if ($connection instanceof \Look\EloquentCypher\GraphConnection) {
            $connection->setLabelPrefix($this->testNamespace);
        }
    }

    /**
     * Initialize test namespace for parallel execution isolation.
     *
     * When running tests in parallel, each test process gets a unique namespace
     * to prevent data conflicts. The namespace is used as a prefix for all
     * Neo4j node labels, ensuring complete isolation between parallel test runs.
     */
    protected function initializeTestNamespace(): void
    {
        // Generate a unique namespace for each test instance to prevent conflicts
        // in parallel execution. Each test gets its own isolated namespace.
        if ($this->isParallelExecution()) {
            // Use test class name hash, process ID and high-resolution time for uniqueness
            $testId = substr(md5(get_class($this)), 0, 8);
            // Add test method name for even more uniqueness if available
            $methodName = 'unknown';
            if (method_exists($this, 'getName')) {
                $methodName = $this->getName(false) ?: 'unknown';
            } elseif (isset($_ENV['TEST_TOKEN'])) {
                // In parallel mode, use the test token as additional uniqueness
                $methodName = $_ENV['TEST_TOKEN'];
            }
            $methodId = substr(md5($methodName), 0, 4);
            // Add random component for additional uniqueness
            $random = substr(md5(uniqid('', true)), 0, 4);
            $this->testNamespace = 'test_'.getmypid().'_'.$testId.'_'.$methodId.'_'.$random.'_';
        }
    }

    protected function getTestNamespace(): string
    {
        return $this->testNamespace ?? '';
    }

    protected function isParallelExecution(): bool
    {
        // Check if running in parallel mode by looking for paratest environment variables
        return getenv('TEST_TOKEN') !== false || getenv('PARATEST') !== false;
    }

    /**
     * Clear registered DB listeners to prevent accumulation.
     */
    protected function clearRegisteredListeners(): void
    {
        // Remove any DB query listeners that were registered during the test
        if (! empty($this->registeredListeners)) {
            foreach ($this->registeredListeners as $listener) {
                // Attempt to remove the specific listener if possible
                try {
                    \DB::getEventDispatcher()->forget('Illuminate\\Database\\Events\\QueryExecuted');
                } catch (\Exception $e) {
                    // Ignore if listener removal fails
                }
            }
            $this->registeredListeners = [];
        }
    }

    /**
     * Register a DB listener and track it for cleanup.
     *
     * @param  callable  $listener  The listener callback
     */
    protected function registerDbListener(callable $listener): void
    {
        \DB::listen($listener);
        $this->registeredListeners[] = $listener;
    }

    /**
     * Verify that cleanup was successful.
     * This helps ensure test isolation is maintained.
     */
    protected function verifyCleanup(): void
    {
        // Only verify in debug mode or when explicitly enabled
        if (! env('TEST_VERIFY_CLEANUP', false)) {
            return;
        }

        // Verify no listeners remain for critical models
        $criticalModels = [
            '\\Tests\\Models\\User',
            '\\Tests\\Models\\Post',
            '\\Tests\\Models\\Comment',
        ];

        foreach ($criticalModels as $model) {
            if (class_exists($model)) {
                $dispatcher = $model::getEventDispatcher();
                if ($dispatcher && method_exists($dispatcher, 'hasListeners')) {
                    $events = ['creating', 'created', 'updating', 'updated', 'deleting', 'deleted'];
                    foreach ($events as $event) {
                        $eventName = "eloquent.{$event}: {$model}";
                        if ($dispatcher->hasListeners($eventName)) {
                            // Log warning but don't fail the test
                            error_log("Warning: Event listeners still registered for {$eventName}");
                        }
                    }
                }
            }
        }
    }

    protected function getTestLabels(): array
    {
        // Return all possible test labels used in tests
        return [
            'User', 'users',
            'Post', 'posts',
            'Comment', 'comments',
            'Role', 'roles',
            'Profile', 'profiles',
            'Image', 'images',
            'Video', 'videos',
            'Product', 'products',
            'Tag', 'tags',
            'Taggable', 'taggables',
            'TestModel', 'test_models',
            'Category', 'categories',
            'Department', 'departments',
            'Employee', 'employees',
            'Project', 'projects',
            'Task', 'tasks',
            'Attachment', 'attachments',
            // Pivot tables
            'role_user',
            'author_book',
            'taggables',
            'post_tag',
            'user_role',
            // Native models
            'NativeUser', 'native_users',
            'NativePost', 'native_posts',
            'NativeComment', 'native_comments',
            'NativeProfile', 'native_profiles',
            'NativeImage', 'native_images',
            'NativeVideo', 'native_videos',
            'NativeAuthor', 'native_authors',
            'NativeBook', 'native_books',
        ];
    }

    /**
     * Get the prefixed label for a model class.
     * This is used for raw Cypher queries to respect label prefixing in parallel mode.
     *
     * @param  string  $modelClass  The fully qualified model class name
     * @return string The prefixed label name
     */
    protected function getPrefixedLabel(string $modelClass): string
    {
        if (! class_exists($modelClass)) {
            throw new \InvalidArgumentException("Model class {$modelClass} does not exist");
        }

        $model = new $modelClass;

        return $model->getTable();
    }

    /**
     * Get prefixed labels for common test models.
     * Returns an array with model names as keys and prefixed labels as values.
     *
     * @return array<string, string>
     */
    protected function getPrefixedLabels(): array
    {
        return [
            'users' => (new \Tests\Models\User)->getTable(),
            'posts' => (new \Tests\Models\Post)->getTable(),
            'comments' => (new \Tests\Models\Comment)->getTable(),
            'roles' => (new \Tests\Models\Role)->getTable(),
            'profiles' => (new \Tests\Models\Profile)->getTable(),
            'tags' => (new \Tests\Models\Tag)->getTable(),
            'products' => (new \Tests\Models\Product)->getTable(),
            'images' => (new \Tests\Models\Image)->getTable(),
            'videos' => (new \Tests\Models\Video)->getTable(),
        ];
    }
}
