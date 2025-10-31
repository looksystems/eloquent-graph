<?php

declare(strict_types=1);

namespace Look\EloquentCypher\Drivers\Neo4j;

use Laudis\Neo4j\Contracts\UnmanagedTransactionInterface;
use Look\EloquentCypher\Contracts\ResultSetInterface;
use Look\EloquentCypher\Contracts\TransactionInterface;

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
