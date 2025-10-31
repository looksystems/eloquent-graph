<?php

declare(strict_types=1);

namespace Look\EloquentCypher\Drivers\Neo4j;

use Laudis\Neo4j\Types\CypherList;
use Look\EloquentCypher\Contracts\SummaryInterface;

class Neo4jSummary implements SummaryInterface
{
    protected $result;

    public function __construct(CypherList $result)
    {
        $this->result = $result;
    }

    public function getExecutionTime(): float
    {
        // CypherList doesn't directly provide execution time
        // This would need to be tracked at the driver level
        return 0.0;
    }

    public function getCounters()
    {
        // Return a null object pattern for counters
        // In Laudis Neo4j, summary/counters are not always available from CypherList
        // The GraphConnection will fall back to result->count() if counters are 0
        return new class
        {
            public function nodesCreated(): int
            {
                return 0;
            }

            public function nodesDeleted(): int
            {
                return 0;
            }

            public function relationshipsCreated(): int
            {
                return 0;
            }

            public function relationshipsDeleted(): int
            {
                return 0;
            }

            public function propertiesSet(): int
            {
                return 0;
            }

            public function labelsAdded(): int
            {
                return 0;
            }

            public function labelsRemoved(): int
            {
                return 0;
            }

            public function indexesAdded(): int
            {
                return 0;
            }

            public function indexesRemoved(): int
            {
                return 0;
            }

            public function constraintsAdded(): int
            {
                return 0;
            }

            public function constraintsRemoved(): int
            {
                return 0;
            }
        };
    }

    public function getPlan(): ?array
    {
        // Plan information not available from CypherList
        return null;
    }
}
