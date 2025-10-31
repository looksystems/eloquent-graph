<?php

namespace Tests\Unit\Exceptions;

use Look\EloquentCypher\Exceptions\Neo4jException;
use PHPUnit\Framework\TestCase;

class Neo4jTransactionExceptionTest extends TestCase
{
    /**
     * Test that it extends Neo4jException
     */
    public function test_extends_neo4j_exception(): void
    {
        $exception = new \Look\EloquentCypher\Exceptions\GraphTransactionException('Test');

        $this->assertInstanceOf(\Look\EloquentCypher\Exceptions\GraphException::class, $exception);
    }

    /**
     * Test deadlock detected exception
     */
    public function test_deadlock_detected(): void
    {
        $exception = \Look\EloquentCypher\Exceptions\GraphTransactionException::deadlockDetected();

        $this->assertInstanceOf(\Look\EloquentCypher\Exceptions\GraphTransactionException::class, $exception);
        $this->assertStringContainsString('Transaction deadlock detected', $exception->getMessage());
        $this->assertStringContainsString('rolled back', $exception->getMessage());

        // Check migration hint
        $hint = $exception->getMigrationHint();
        $this->assertNotNull($hint);
        $this->assertStringContainsString('Deadlocks can occur', $hint);
        $this->assertStringContainsString('Retry the transaction', $hint);
        $this->assertStringContainsString('exponential backoff', $hint);
        $this->assertStringContainsString('consistent order', $hint);
        $this->assertStringContainsString('shorter transactions', $hint);
        $this->assertStringContainsString('optimistic locking', $hint);
    }

    /**
     * Test transaction rolled back exception
     */
    public function test_transaction_rolled_back(): void
    {
        $reason = 'Constraint violation occurred';
        $exception = \Look\EloquentCypher\Exceptions\GraphTransactionException::transactionRolledBack($reason);

        $this->assertInstanceOf(\Look\EloquentCypher\Exceptions\GraphTransactionException::class, $exception);
        $this->assertStringContainsString('Transaction was rolled back', $exception->getMessage());
        $this->assertStringContainsString($reason, $exception->getMessage());

        // Check migration hint
        $hint = $exception->getMigrationHint();
        $this->assertNotNull($hint);
        $this->assertStringContainsString('Constraint violations', $hint);
        $this->assertStringContainsString('Explicit rollback', $hint);
        $this->assertStringContainsString('Connection failures', $hint);
        $this->assertStringContainsString('Timeout exceeded', $hint);
    }

    public function test_transaction_rolled_back_with_complex_reason(): void
    {
        $reason = 'Multiple failures: connection lost, timeout exceeded, and constraint violation';
        $exception = \Look\EloquentCypher\Exceptions\GraphTransactionException::transactionRolledBack($reason);

        $this->assertStringContainsString($reason, $exception->getMessage());
    }

    /**
     * Test nested transactions not supported exception
     */
    public function test_nested_transactions_not_supported(): void
    {
        $exception = \Look\EloquentCypher\Exceptions\GraphTransactionException::nestedTransactionsNotSupported();

        $this->assertInstanceOf(\Look\EloquentCypher\Exceptions\GraphTransactionException::class, $exception);
        $this->assertStringContainsString('Nested transactions are not supported', $exception->getMessage());
        $this->assertStringContainsString('Neo4j', $exception->getMessage());

        // Check migration hint
        $hint = $exception->getMigrationHint();
        $this->assertNotNull($hint);
        $this->assertStringContainsString("doesn't support nested transactions", $hint);
        $this->assertStringContainsString('savepoints', $hint);
        $this->assertStringContainsString('single transaction', $hint);
        $this->assertStringContainsString('application-level', $hint);
        $this->assertStringContainsString('separate transactions', $hint);
    }

    /**
     * Test transaction timeout exception
     */
    public function test_transaction_timeout(): void
    {
        $timeout = 30;
        $exception = \Look\EloquentCypher\Exceptions\GraphTransactionException::transactionTimeout($timeout);

        $this->assertInstanceOf(\Look\EloquentCypher\Exceptions\GraphTransactionException::class, $exception);
        $this->assertStringContainsString('Transaction exceeded the timeout limit', $exception->getMessage());
        $this->assertStringContainsString('30 seconds', $exception->getMessage());

        // Check migration hint
        $hint = $exception->getMigrationHint();
        $this->assertNotNull($hint);
        $this->assertStringContainsString('Long-running transactions', $hint);
        $this->assertStringContainsString('smaller transactions', $hint);
        $this->assertStringContainsString('batch operations', $hint);
        $this->assertStringContainsString('Increasing timeout', $hint);
        $this->assertStringContainsString('background jobs', $hint);
    }

    public function test_transaction_timeout_with_large_value(): void
    {
        $timeout = 3600; // 1 hour
        $exception = \Look\EloquentCypher\Exceptions\GraphTransactionException::transactionTimeout($timeout);

        $this->assertStringContainsString('3600 seconds', $exception->getMessage());
    }

    public function test_transaction_timeout_with_zero(): void
    {
        $timeout = 0;
        $exception = \Look\EloquentCypher\Exceptions\GraphTransactionException::transactionTimeout($timeout);

        $this->assertStringContainsString('0 seconds', $exception->getMessage());
    }

    /**
     * Test no active transaction exception
     */
    public function test_no_active_transaction(): void
    {
        $operation = 'commit';
        $exception = \Look\EloquentCypher\Exceptions\GraphTransactionException::noActiveTransaction($operation);

        $this->assertInstanceOf(\Look\EloquentCypher\Exceptions\GraphTransactionException::class, $exception);
        $this->assertStringContainsString("Cannot perform 'commit'", $exception->getMessage());
        $this->assertStringContainsString('no active transaction', $exception->getMessage());

        // Check migration hint
        $hint = $exception->getMigrationHint();
        $this->assertNotNull($hint);
        $this->assertStringContainsString('Start a transaction', $hint);
        $this->assertStringContainsString('DB::beginTransaction()', $hint);
        $this->assertStringContainsString('DB::commit()', $hint);
    }

    public function test_no_active_transaction_with_different_operations(): void
    {
        $operations = ['rollback', 'savepoint', 'release', 'query'];

        foreach ($operations as $operation) {
            $exception = \Look\EloquentCypher\Exceptions\GraphTransactionException::noActiveTransaction($operation);
            $this->assertStringContainsString("'{$operation}'", $exception->getMessage());
        }
    }

    /**
     * Test transaction already active exception
     */
    public function test_transaction_already_active(): void
    {
        $exception = \Look\EloquentCypher\Exceptions\GraphTransactionException::transactionAlreadyActive();

        $this->assertInstanceOf(\Look\EloquentCypher\Exceptions\GraphTransactionException::class, $exception);
        $this->assertStringContainsString('Cannot start a new transaction', $exception->getMessage());
        $this->assertStringContainsString('already active', $exception->getMessage());

        // Check migration hint
        $hint = $exception->getMigrationHint();
        $this->assertNotNull($hint);
        $this->assertStringContainsString('Complete or rollback', $hint);
        $this->assertStringContainsString('DB::commit()', $hint);
        $this->assertStringContainsString('DB::rollback()', $hint);
    }

    /**
     * Test that static factory methods return correct type
     */
    public function test_static_factory_methods_return_correct_type(): void
    {
        $methods = [
            'deadlockDetected' => [],
            'transactionRolledBack' => ['test reason'],
            'nestedTransactionsNotSupported' => [],
            'transactionTimeout' => [60],
            'noActiveTransaction' => ['test operation'],
            'transactionAlreadyActive' => [],
        ];

        foreach ($methods as $method => $params) {
            $exception = \Look\EloquentCypher\Exceptions\GraphTransactionException::$method(...$params);
            $this->assertInstanceOf(\Look\EloquentCypher\Exceptions\GraphTransactionException::class, $exception);
            $this->assertNotEmpty($exception->getMessage());
            $this->assertNotNull($exception->getMigrationHint());
        }
    }

    /**
     * Test detailed message includes all information
     */
    public function test_detailed_message_with_all_information(): void
    {
        $exception = \Look\EloquentCypher\Exceptions\GraphTransactionException::deadlockDetected();
        $exception->setCypher('MATCH (n:User) SET n.counter = n.counter + 1')
            ->setParameters(['userId' => 123]);

        $detailed = $exception->getDetailedMessage();

        // Check base message
        $this->assertStringContainsString('Transaction deadlock detected', $detailed);

        // Check migration hint is included
        $this->assertStringContainsString('Migration Hint:', $detailed);
        $this->assertStringContainsString('Deadlocks can occur', $detailed);

        // Check Cypher query is included
        $this->assertStringContainsString('Cypher Query:', $detailed);
        $this->assertStringContainsString('MATCH (n:User)', $detailed);

        // Check parameters are included
        $this->assertStringContainsString('Parameters:', $detailed);
        $this->assertStringContainsString('"userId": 123', $detailed);
    }

    /**
     * Test edge cases
     */
    public function test_empty_reason_for_rollback(): void
    {
        $exception = \Look\EloquentCypher\Exceptions\GraphTransactionException::transactionRolledBack('');

        $this->assertStringContainsString('Transaction was rolled back:', $exception->getMessage());
    }

    public function test_empty_operation_name(): void
    {
        $exception = \Look\EloquentCypher\Exceptions\GraphTransactionException::noActiveTransaction('');

        $this->assertStringContainsString("Cannot perform ''", $exception->getMessage());
    }

    public function test_negative_timeout(): void
    {
        $exception = \Look\EloquentCypher\Exceptions\GraphTransactionException::transactionTimeout(-1);

        $this->assertStringContainsString('-1 seconds', $exception->getMessage());
    }

    public function test_very_long_operation_name(): void
    {
        $longOperation = str_repeat('very_long_operation_name_', 20);
        $exception = \Look\EloquentCypher\Exceptions\GraphTransactionException::noActiveTransaction($longOperation);

        $this->assertStringContainsString($longOperation, $exception->getMessage());
    }

    public function test_special_characters_in_reason(): void
    {
        $reason = "Special characters: !@#$%^&*()_+{}|:\"<>?[];',./`~";
        $exception = \Look\EloquentCypher\Exceptions\GraphTransactionException::transactionRolledBack($reason);

        $this->assertStringContainsString($reason, $exception->getMessage());
    }

    /**
     * Test that exceptions can be chained with context
     */
    public function test_exception_chaining(): void
    {
        $exception1 = \Look\EloquentCypher\Exceptions\GraphTransactionException::deadlockDetected();
        $exception2 = \Look\EloquentCypher\Exceptions\GraphTransactionException::transactionRolledBack('Deadlock detected');

        $exception2->setCypher('MATCH (n) RETURN n')
            ->setParameters(['limit' => 10]);

        $this->assertNotEquals($exception1->getMessage(), $exception2->getMessage());
        $this->assertNotNull($exception2->getCypher());
        $this->assertCount(1, $exception2->getParameters());
    }
}
