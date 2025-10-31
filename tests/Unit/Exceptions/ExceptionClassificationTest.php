<?php

declare(strict_types=1);

namespace Tests\Unit\Exceptions;

use Exception;
use Laudis\Neo4j\Exception\Neo4jException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class ExceptionClassificationTest extends TestCase
{
    protected \Look\EloquentCypher\GraphConnection $connection;

    protected ReflectionClass $reflection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = new \Look\EloquentCypher\GraphConnection([
            'driver' => 'neo4j',
            'host' => 'localhost',
            'port' => 7688,
            'username' => 'neo4j',
            'password' => 'password',
            'database' => 'neo4j',
        ], 'neo4j');

        $this->reflection = new ReflectionClass($this->connection);
    }

    protected function callProtectedMethod(string $method, ...$args)
    {
        $method = $this->reflection->getMethod($method);
        $method->setAccessible(true);

        return $method->invoke($this->connection, ...$args);
    }

    public function test_classifies_deadlock_exceptions_as_transient(): void
    {
        $exception = new Exception('ForsetiClient[0] can\'t acquire ExclusiveLock');
        $classification = $this->callProtectedMethod('classifyException', $exception);
        $this->assertEquals('transient', $classification);

        $exception = new Exception('DeadlockDetectedException');
        $classification = $this->callProtectedMethod('classifyException', $exception);
        $this->assertEquals('transient', $classification);

        $exception = new Exception('LockAcquisitionTimeout');
        $classification = $this->callProtectedMethod('classifyException', $exception);
        $this->assertEquals('transient', $classification);
    }

    public function test_classifies_network_timeout_as_transient()
    {
        $exception = new Exception('Connection timeout');
        $classification = $this->callProtectedMethod('classifyException', $exception);
        $this->assertEquals('network', $classification);

        $exception = new Exception('Connection refused');
        $classification = $this->callProtectedMethod('classifyException', $exception);
        $this->assertEquals('network', $classification);

        $exception = new Exception('Network is unreachable');
        $classification = $this->callProtectedMethod('classifyException', $exception);
        $this->assertEquals('network', $classification);

        $exception = new Exception('Connection reset by peer');
        $classification = $this->callProtectedMethod('classifyException', $exception);
        $this->assertEquals('network', $classification);
    }

    public function test_classifies_syntax_errors_as_permanent()
    {
        // Use regular Exception since Laudis Neo4jException needs special constructor
        $exception = new Exception('Neo.ClientError.Statement.SyntaxError');
        $classification = $this->callProtectedMethod('classifyException', $exception);
        $this->assertEquals('query', $classification);

        $exception = new Exception('Invalid input');
        $classification = $this->callProtectedMethod('classifyException', $exception);
        $this->assertEquals('query', $classification);

        $exception = new Exception('Unknown function');
        $classification = $this->callProtectedMethod('classifyException', $exception);
        $this->assertEquals('query', $classification);
    }

    public function test_classifies_constraint_violations_as_permanent()
    {
        $exception = new Exception('Neo.ClientError.Schema.ConstraintValidationFailed');
        $classification = $this->callProtectedMethod('classifyException', $exception);
        $this->assertEquals('constraint', $classification);

        $exception = new Exception('Node(100) already exists with label');
        $classification = $this->callProtectedMethod('classifyException', $exception);
        $this->assertEquals('constraint', $classification);

        $exception = new Exception('already exists with property');
        $classification = $this->callProtectedMethod('classifyException', $exception);
        $this->assertEquals('constraint', $classification);
    }

    public function test_detects_stale_connection_errors()
    {
        $exception = new Exception('Connection is stale');
        $shouldReconnect = $this->callProtectedMethod('shouldReconnect', $exception);
        $this->assertTrue($shouldReconnect);

        $exception = new Exception('The client is unauthorized due to authentication failure');
        $shouldReconnect = $this->callProtectedMethod('shouldReconnect', $exception);
        $this->assertFalse($shouldReconnect); // Auth failures should not trigger reconnect

        $exception = new Exception('Connection pool is closed');
        $shouldReconnect = $this->callProtectedMethod('shouldReconnect', $exception);
        $this->assertTrue($shouldReconnect);
    }

    public function test_detects_authentication_failures()
    {
        $exception = new Exception('The client is unauthorized due to authentication failure');
        $classification = $this->callProtectedMethod('classifyException', $exception);
        $this->assertEquals('authentication', $classification);

        $exception = new Exception('Neo.ClientError.Security.Unauthorized');
        $classification = $this->callProtectedMethod('classifyException', $exception);
        $this->assertEquals('authentication', $classification);

        $exception = new Exception('Invalid username or password');
        $classification = $this->callProtectedMethod('classifyException', $exception);
        $this->assertEquals('authentication', $classification);
    }

    public function test_determines_retryable_exceptions()
    {
        // Transient errors should be retryable
        $exception = new Exception('DeadlockDetectedException');
        $isRetryable = $this->callProtectedMethod('isRetryable', $exception);
        $this->assertTrue($isRetryable);

        // Network errors should be retryable
        $exception = new Exception('Connection timeout');
        $isRetryable = $this->callProtectedMethod('isRetryable', $exception);
        $this->assertTrue($isRetryable);

        // Query errors should NOT be retryable
        $exception = new Exception('Invalid input');
        $isRetryable = $this->callProtectedMethod('isRetryable', $exception);
        $this->assertFalse($isRetryable);

        // Constraint violations should NOT be retryable
        $exception = new Exception('already exists with property');
        $isRetryable = $this->callProtectedMethod('isRetryable', $exception);
        $this->assertFalse($isRetryable);

        // Authentication errors should NOT be retryable
        $exception = new Exception('The client is unauthorized due to authentication failure');
        $isRetryable = $this->callProtectedMethod('isRetryable', $exception);
        $this->assertFalse($isRetryable);
    }

    public function test_wraps_exceptions_with_appropriate_types()
    {
        $originalException = new Exception('DeadlockDetectedException');
        $wrapped = $this->callProtectedMethod('wrapException', $originalException, 'MATCH (n) RETURN n', ['param' => 'value']);

        $this->assertInstanceOf(\Look\EloquentCypher\Exceptions\GraphTransientException::class, $wrapped);
        $this->assertStringContainsString('DeadlockDetectedException', $wrapped->getMessage());
        $this->assertEquals('MATCH (n) RETURN n', $wrapped->getQuery());
        $this->assertEquals(['param' => 'value'], $wrapped->getParameters());

        $originalException = new Exception('Connection timeout');
        $wrapped = $this->callProtectedMethod('wrapException', $originalException, 'CREATE (n)', []);
        $this->assertInstanceOf(\Look\EloquentCypher\Exceptions\GraphNetworkException::class, $wrapped);

        $originalException = new Exception('Invalid username or password');
        $wrapped = $this->callProtectedMethod('wrapException', $originalException, '', []);
        $this->assertInstanceOf(\Look\EloquentCypher\Exceptions\GraphAuthenticationException::class, $wrapped);

        $originalException = new Exception('already exists with property');
        $wrapped = $this->callProtectedMethod('wrapException', $originalException, 'CREATE (n)', []);
        $this->assertInstanceOf(\Look\EloquentCypher\Exceptions\GraphConstraintException::class, $wrapped);

        $originalException = new Exception('Invalid input');
        $wrapped = $this->callProtectedMethod('wrapException', $originalException, 'INVALID CYPHER', []);
        $this->assertInstanceOf(\Look\EloquentCypher\Exceptions\GraphQueryException::class, $wrapped);
    }

    public function test_provides_helpful_migration_hints()
    {
        $exception = new Exception('already exists with property `email`');
        $wrapped = $this->callProtectedMethod('wrapException', $exception, 'CREATE (n:User)', ['email' => 'test@example.com']);

        $this->assertStringContainsString('already exists', $wrapped->getMessage());
        $this->assertStringContainsString('unique constraint', $wrapped->getHint());
        $this->assertStringContainsString('CREATE CONSTRAINT', $wrapped->getHint());

        $exception = new Exception('Unknown function `json_extract`');
        $wrapped = $this->callProtectedMethod('wrapException', $exception, 'RETURN json_extract()', []);
        $this->assertStringContainsString('APOC', $wrapped->getHint());
        $this->assertStringContainsString('apoc.json', $wrapped->getHint());
    }

    public function test_classifies_transaction_errors_correctly()
    {
        $exception = new Exception('Transaction has been terminated');
        $classification = $this->callProtectedMethod('classifyException', $exception);
        $this->assertEquals('transaction', $classification);

        $exception = new Exception('Transaction rolled back');
        $classification = $this->callProtectedMethod('classifyException', $exception);
        $this->assertEquals('transaction', $classification);

        $exception = new Exception('Neo.TransientError.Transaction.Terminated');
        $classification = $this->callProtectedMethod('classifyException', $exception);
        $this->assertEquals('transient', $classification);

        $exception = new Exception('Neo.TransientError.Transaction.LockClientStopped');
        $classification = $this->callProtectedMethod('classifyException', $exception);
        $this->assertEquals('transient', $classification);
    }
}
