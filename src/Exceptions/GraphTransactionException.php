<?php

namespace Look\EloquentCypher\Exceptions;

/**
 * Exception thrown when there are transaction-related issues in Neo4j
 */
class GraphTransactionException extends \Look\EloquentCypher\Exceptions\GraphException
{
    /**
     * Create a transaction deadlock exception
     *
     * @return static
     */
    public static function deadlockDetected(): self
    {
        $exception = new self(
            'Transaction deadlock detected. The transaction has been rolled back.'
        );

        $exception->setMigrationHint(
            'Deadlocks can occur when transactions lock resources in different orders. '.
            "Consider:\n".
            "• Retry the transaction with exponential backoff\n".
            "• Access nodes/relationships in a consistent order\n".
            "• Use shorter transactions\n".
            '• Implement optimistic locking patterns'
        );

        return $exception;
    }

    /**
     * Create a transaction rollback exception
     *
     * @return static
     */
    public static function transactionRolledBack(string $reason): self
    {
        $exception = new self(
            "Transaction was rolled back: {$reason}"
        );

        $exception->setMigrationHint(
            "Transactions can be rolled back due to:\n".
            "• Constraint violations\n".
            "• Explicit rollback calls\n".
            "• Connection failures\n".
            '• Timeout exceeded'
        );

        return $exception;
    }

    /**
     * Create a nested transaction exception
     *
     * @return static
     */
    public static function nestedTransactionsNotSupported(): self
    {
        $exception = new self(
            'Nested transactions are not supported in Neo4j'
        );

        $exception->setMigrationHint(
            "Neo4j doesn't support nested transactions or savepoints. ".
            "Consider:\n".
            "• Using a single transaction for all operations\n".
            "• Implementing application-level transaction management\n".
            '• Breaking complex operations into separate transactions'
        );

        return $exception;
    }

    /**
     * Create a transaction timeout exception
     *
     * @return static
     */
    public static function transactionTimeout(int $timeout): self
    {
        $exception = new self(
            "Transaction exceeded the timeout limit of {$timeout} seconds"
        );

        $exception->setMigrationHint(
            "Long-running transactions can cause performance issues. Consider:\n".
            "• Breaking large operations into smaller transactions\n".
            "• Using batch operations\n".
            "• Increasing timeout for legitimate long operations\n".
            '• Using background jobs for heavy processing'
        );

        return $exception;
    }

    /**
     * Create an exception for attempting operations outside a transaction
     *
     * @return static
     */
    public static function noActiveTransaction(string $operation): self
    {
        $exception = new self(
            "Cannot perform '{$operation}' - no active transaction"
        );

        $exception->setMigrationHint(
            "Start a transaction before performing this operation:\n".
            "DB::beginTransaction();\n".
            "// ... your operations ...\n".
            'DB::commit();'
        );

        return $exception;
    }

    /**
     * Create an exception for transaction already active
     *
     * @return static
     */
    public static function transactionAlreadyActive(): self
    {
        $exception = new self(
            'Cannot start a new transaction - a transaction is already active'
        );

        $exception->setMigrationHint(
            'Complete or rollback the current transaction before starting a new one. '.
            'Use DB::commit() or DB::rollback() to end the current transaction.'
        );

        return $exception;
    }
}
