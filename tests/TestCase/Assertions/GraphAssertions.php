<?php

namespace Tests\TestCase\Assertions;

trait GraphAssertions
{
    protected function assertNodeExists(string $label, array $properties = []): void
    {
        $cypher = "MATCH (n:$label";

        if (! empty($properties)) {
            $propConditions = [];
            foreach ($properties as $key => $value) {
                $propConditions[] = "n.$key = \$properties.$key";
            }
            $cypher .= ' WHERE '.implode(' AND ', $propConditions);
        }

        $cypher .= ') RETURN count(n) as count';

        $result = $this->neo4jClient->run($cypher, ['properties' => $properties]);

        $this->assertGreaterThan(0, $result->first()->get('count'),
            "Failed asserting that node with label '$label' exists.");
    }

    protected function assertNodeDoesNotExist(string $label, array $properties = []): void
    {
        $cypher = "MATCH (n:$label";

        if (! empty($properties)) {
            $propConditions = [];
            foreach ($properties as $key => $value) {
                $propConditions[] = "n.$key = \$properties.$key";
            }
            $cypher .= ' WHERE '.implode(' AND ', $propConditions);
        }

        $cypher .= ') RETURN count(n) as count';

        $result = $this->neo4jClient->run($cypher, ['properties' => $properties]);

        $this->assertEquals(0, $result->first()->get('count'),
            "Failed asserting that node with label '$label' does not exist.");
    }

    protected function assertRelationshipExists(string $type, ?string $fromLabel = null, ?string $toLabel = null): void
    {
        $fromMatch = $fromLabel ? ":$fromLabel" : '';
        $toMatch = $toLabel ? ":$toLabel" : '';

        $cypher = "MATCH (a$fromMatch)-[r:$type]->(b$toMatch) RETURN count(r) as count";

        $result = $this->neo4jClient->run($cypher);

        $this->assertGreaterThan(0, $result->first()->get('count'),
            "Failed asserting that relationship of type '$type' exists.");
    }

    protected function assertDatabaseNodeCount(int $expectedCount, ?string $label = null): void
    {
        $match = $label ? "MATCH (n:$label)" : 'MATCH (n)';
        $cypher = "$match RETURN count(n) as count";

        $result = $this->neo4jClient->run($cypher);
        $actualCount = $result->first()->get('count');

        $this->assertEquals($expectedCount, $actualCount,
            "Failed asserting that database contains $expectedCount node(s).");
    }

    // Enhanced Neo4j-specific assertions

    protected function assertRelationshipDoesNotExist(string $type, ?string $fromLabel = null, ?string $toLabel = null): void
    {
        $fromMatch = $fromLabel ? ":$fromLabel" : '';
        $toMatch = $toLabel ? ":$toLabel" : '';

        $cypher = "MATCH (a$fromMatch)-[r:$type]->(b$toMatch) RETURN count(r) as count";

        $result = $this->neo4jClient->run($cypher);

        $this->assertEquals(0, $result->first()->get('count'),
            "Failed asserting that relationship of type '$type' does not exist.");
    }

    protected function assertDatabaseRelationshipCount(int $expectedCount, ?string $type = null): void
    {
        $typeClause = $type ? ":$type" : '';
        $cypher = "MATCH ()-[r$typeClause]->() RETURN count(r) as count";

        $result = $this->neo4jClient->run($cypher);
        $actualCount = $result->first()->get('count');

        $this->assertEquals($expectedCount, $actualCount,
            "Failed asserting that database contains $expectedCount relationship(s).");
    }

    protected function assertNodeHasProperty(string $label, array $identifier, string $property, $expectedValue = null): void
    {
        $conditions = [];
        $bindings = [];
        foreach ($identifier as $key => $value) {
            $conditions[] = "n.$key = \$identifier_$key";
            $bindings["identifier_$key"] = $value;
        }

        $cypher = "MATCH (n:$label) WHERE ".implode(' AND ', $conditions)." RETURN n.$property as value";

        $result = $this->neo4jClient->run($cypher, $bindings);

        $this->assertNotEmpty($result, "Node with label '$label' not found.");

        $actualValue = $result->first()->get('value');

        if ($expectedValue !== null) {
            $this->assertEquals($expectedValue, $actualValue,
                "Failed asserting that node property '$property' equals expected value.");
        } else {
            $this->assertNotNull($actualValue,
                "Failed asserting that node has property '$property'.");
        }
    }

    protected function assertNodeDoesNotHaveProperty(string $label, array $identifier, string $property): void
    {
        $conditions = [];
        $bindings = [];
        foreach ($identifier as $key => $value) {
            $conditions[] = "n.$key = \$identifier_$key";
            $bindings["identifier_$key"] = $value;
        }

        $cypher = "MATCH (n:$label) WHERE ".implode(' AND ', $conditions)." RETURN n.$property as value";

        $result = $this->neo4jClient->run($cypher, $bindings);

        if (! empty($result)) {
            $actualValue = $result->first()->get('value');
            $this->assertNull($actualValue,
                "Failed asserting that node does not have property '$property'.");
        }
    }

    protected function assertRelationshipHasProperty(string $type, array $fromIdentifier, array $toIdentifier, string $property, $expectedValue = null): void
    {
        $fromConditions = [];
        $toConditions = [];
        $bindings = [];

        foreach ($fromIdentifier as $key => $value) {
            $fromConditions[] = "a.$key = \$from_$key";
            $bindings["from_$key"] = $value;
        }

        foreach ($toIdentifier as $key => $value) {
            $toConditions[] = "b.$key = \$to_$key";
            $bindings["to_$key"] = $value;
        }

        $cypher = "MATCH (a)-[r:$type]->(b) ".
                 'WHERE '.implode(' AND ', $fromConditions).' AND '.implode(' AND ', $toConditions).
                 " RETURN r.$property as value";

        $result = $this->neo4jClient->run($cypher, $bindings);

        $this->assertNotEmpty($result, "Relationship of type '$type' not found.");

        $actualValue = $result->first()->get('value');

        if ($expectedValue !== null) {
            $this->assertEquals($expectedValue, $actualValue,
                "Failed asserting that relationship property '$property' equals expected value.");
        } else {
            $this->assertNotNull($actualValue,
                "Failed asserting that relationship has property '$property'.");
        }
    }

    protected function assertQueryExecutionTime(string $cypher, array $bindings, float $maxTimeSeconds): void
    {
        $startTime = microtime(true);
        $this->neo4jClient->run($cypher, $bindings);
        $executionTime = microtime(true) - $startTime;

        $this->assertLessThanOrEqual($maxTimeSeconds, $executionTime,
            "Query took {$executionTime}s, expected <= {$maxTimeSeconds}s");
    }

    protected function assertPathExists(string $fromLabel, array $fromProperties, string $toLabel, array $toProperties, int $maxLength = 5): void
    {
        $fromConditions = [];
        $toConditions = [];
        $bindings = [];

        foreach ($fromProperties as $key => $value) {
            $fromConditions[] = "start.$key = \$from_$key";
            $bindings["from_$key"] = $value;
        }

        foreach ($toProperties as $key => $value) {
            $toConditions[] = "end.$key = \$to_$key";
            $bindings["to_$key"] = $value;
        }

        $cypher = "MATCH path = (start:$fromLabel)-[*1..$maxLength]-(end:$toLabel) ".
                 'WHERE '.implode(' AND ', $fromConditions).' AND '.implode(' AND ', $toConditions).
                 ' RETURN count(path) as pathCount';

        $result = $this->neo4jClient->run($cypher, $bindings);
        $pathCount = $result->first()->get('pathCount');

        $this->assertGreaterThan(0, $pathCount,
            "No path found between specified nodes within $maxLength hops.");
    }

    protected function assertDatabaseIsEmpty(): void
    {
        $cypher = 'MATCH (n) RETURN count(n) as count';
        $result = $this->neo4jClient->run($cypher);
        $count = $result->first()->get('count');

        $this->assertEquals(0, $count, 'Database is not empty.');
    }

    protected function assertJsonPropertyContains(string $label, array $identifier, string $jsonProperty, $expectedValue): void
    {
        $conditions = [];
        $bindings = [];
        foreach ($identifier as $key => $value) {
            $conditions[] = "n.$key = \$identifier_$key";
            $bindings["identifier_$key"] = $value;
        }

        $cypher = "MATCH (n:$label) WHERE ".implode(' AND ', $conditions)." RETURN n.$jsonProperty as jsonValue";

        $result = $this->neo4jClient->run($cypher, $bindings);
        $this->assertNotEmpty($result, "Node with label '$label' not found.");

        $jsonValue = $result->first()->get('jsonValue');
        $decodedValue = json_decode($jsonValue, true);

        $this->assertContains($expectedValue, $decodedValue,
            "JSON property '$jsonProperty' does not contain expected value.");
    }

    protected function assertJsonPropertyLength(string $label, array $identifier, string $jsonProperty, int $expectedLength): void
    {
        $conditions = [];
        $bindings = [];
        foreach ($identifier as $key => $value) {
            $conditions[] = "n.$key = \$identifier_$key";
            $bindings["identifier_$key"] = $value;
        }

        $cypher = "MATCH (n:$label) WHERE ".implode(' AND ', $conditions)." RETURN size(n.$jsonProperty) as arrayLength";

        $result = $this->neo4jClient->run($cypher, $bindings);
        $this->assertNotEmpty($result, "Node with label '$label' not found.");

        $arrayLength = $result->first()->get('arrayLength');

        $this->assertEquals($expectedLength, $arrayLength,
            "JSON array property '$jsonProperty' length does not match expected length.");
    }

    protected function assertMemoryUsage(callable $operation, int $maxMemoryMB): void
    {
        $memoryBefore = memory_get_usage(true);
        $operation();
        $memoryAfter = memory_get_usage(true);

        $memoryUsedMB = ($memoryAfter - $memoryBefore) / 1024 / 1024;

        $this->assertLessThanOrEqual($maxMemoryMB, $memoryUsedMB,
            "Memory usage {$memoryUsedMB}MB exceeded limit of {$maxMemoryMB}MB");
    }

    protected function assertQueryCount(callable $operation, int $expectedQueryCount): void
    {
        // This would require query logging implementation
        // For now, this is a placeholder for future implementation
        $this->markTestIncomplete('Query count assertion requires query logging implementation');
    }

    protected function assertNodesConnected(string $fromLabel, array $fromIdentifier, string $toLabel, array $toIdentifier, ?string $relationshipType = null): void
    {
        $fromConditions = [];
        $toConditions = [];
        $bindings = [];

        foreach ($fromIdentifier as $key => $value) {
            $fromConditions[] = "from.$key = \$from_$key";
            $bindings["from_$key"] = $value;
        }

        foreach ($toIdentifier as $key => $value) {
            $toConditions[] = "to.$key = \$to_$key";
            $bindings["to_$key"] = $value;
        }

        $relationshipClause = $relationshipType ? ":$relationshipType" : '';
        $cypher = "MATCH (from:$fromLabel)-[r$relationshipClause]->(to:$toLabel) ".
                 'WHERE '.implode(' AND ', $fromConditions).' AND '.implode(' AND ', $toConditions).
                 ' RETURN count(r) as connectionCount';

        $result = $this->neo4jClient->run($cypher, $bindings);
        $connectionCount = $result->first()->get('connectionCount');

        $this->assertGreaterThan(0, $connectionCount,
            'Nodes are not connected as expected.');
    }

    protected function assertGraphStructure(array $expectedStructure): void
    {
        foreach ($expectedStructure as $nodeType => $expectedCount) {
            $this->assertDatabaseNodeCount($expectedCount, $nodeType);
        }
    }

    protected function assertTransactionIsolation(callable $operation1, callable $operation2): void
    {
        // This would test that operations in different transactions don't interfere
        // Placeholder for future implementation requiring transaction support
        $this->markTestIncomplete('Transaction isolation testing requires transaction implementation');
    }
}
