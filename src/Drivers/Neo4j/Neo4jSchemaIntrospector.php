<?php

declare(strict_types=1);

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

        return $this->convertToPhpArrays($result->toArray());
    }

    public function getIndexes(?string $type = null): array
    {
        $cypher = 'SHOW INDEXES';
        if ($type) {
            $cypher .= " WHERE type = '{$type}'";
        }

        $result = $this->client->run($cypher);

        return $this->convertToPhpArrays($result->toArray());
    }

    /**
     * Recursively convert all Cypher types to PHP arrays.
     */
    protected function convertToPhpArrays($data)
    {
        if ($data instanceof \Laudis\Neo4j\Types\CypherList) {
            return $this->convertToPhpArrays($data->toArray());
        }

        if ($data instanceof \Laudis\Neo4j\Types\CypherMap) {
            return $this->convertToPhpArrays($data->toArray());
        }

        if ($data instanceof \Laudis\Neo4j\Types\DateTime) {
            // Convert to PHP DateTime and format
            return $data->toDateTime()->format(\DateTimeInterface::ATOM);
        }

        if (is_array($data)) {
            return array_map([$this, 'convertToPhpArrays'], $data);
        }

        return $data;
    }

    public function introspect(): array
    {
        return [
            'labels' => $this->getLabels(),
            'relationshipTypes' => $this->getRelationshipTypes(),
            'propertyKeys' => $this->getPropertyKeys(),
            'constraints' => $this->getConstraints(),
            'indexes' => $this->getIndexes(),
        ];
    }
}
