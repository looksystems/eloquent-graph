<?php

declare(strict_types=1);

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
