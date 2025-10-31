<?php

namespace Look\EloquentCypher\Services;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;

class EdgeManager
{
    protected Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function createEdge($from, $to, string $type, array $properties = [], ?string $fromTable = null, ?string $toTable = null): array
    {
        $fromId = $from instanceof Model ? $from->getKey() : $from;
        $toId = $to instanceof Model ? $to->getKey() : $to;

        // Get tables from models if not provided
        if (! $fromTable && $from instanceof Model) {
            $fromTable = $from->getTable();
        }
        if (! $toTable && $to instanceof Model) {
            $toTable = $to->getTable();
        }

        // Build Cypher query with table names if available
        if ($fromTable && $toTable) {
            $cypher = "MATCH (a:$fromTable {id: \$fromId}), (b:$toTable {id: \$toId}) ".
                      "CREATE (a)-[r:$type]->(b)";
        } else {
            $cypher = 'MATCH (a {id: $fromId}), (b {id: $toId}) '.
                      "CREATE (a)-[r:$type]->(b)";
        }

        // Add properties if provided
        if (! empty($properties)) {
            $cypher .= ' SET r += $properties';
        }

        $cypher .= ' RETURN r';

        $params = [
            'fromId' => $fromId,
            'toId' => $toId,
        ];

        if (! empty($properties)) {
            $params['properties'] = $properties;
        }

        $result = $this->connection->select($cypher, $params);

        if (empty($result)) {
            return [];
        }

        $edge = $result[0]['r'] ?? $result[0]->r ?? [];

        return [
            'id' => ['from' => $fromId, 'to' => $toId, 'type' => $type], // Composite ID
            'type' => $type,
            'properties' => is_array($edge) ? $edge : (array) $edge,
        ];
    }

    public function updateEdgeProperties($fromId, $toId = null, $edgeType = null, $properties = null, ?string $fromTable = null, ?string $toTable = null): array
    {
        // Handle composite array ID (backward compatibility)
        if (is_array($fromId) && isset($fromId['from'], $fromId['to'], $fromId['type'])) {
            $compositeId = $fromId;
            $properties = $toId; // When using composite, properties is the second parameter
            $fromId = $compositeId['from'];
            $toId = $compositeId['to'];
            $edgeType = $compositeId['type'];
            $fromTable = null;
            $toTable = null;
        }

        // Build the query
        $labelFrom = $fromTable ? ":$fromTable" : '';
        $labelTo = $toTable ? ":$toTable" : '';

        $cypher = "MATCH (a{$labelFrom} {id: \$fromId})-[r:$edgeType]->(b{$labelTo} {id: \$toId}) ".
                  'SET r += $properties '.
                  'RETURN r';

        $result = $this->connection->select($cypher, [
            'fromId' => $fromId,
            'toId' => $toId,
            'properties' => $properties,
        ]);

        if (empty($result)) {
            return [];
        }

        return $result;
    }

    public function deleteEdge($fromId, $toId = null, ?string $edgeType = null, ?string $fromTable = null, ?string $toTable = null): bool
    {
        // Handle composite array ID (backward compatibility)
        if (is_array($fromId) && isset($fromId['from'], $fromId['to'], $fromId['type'])) {
            $compositeId = $fromId;
            $fromId = $compositeId['from'];
            $toId = $compositeId['to'];
            $edgeType = $compositeId['type'];
            $fromTable = null;
            $toTable = null;
        } else {
            // Handle Model instances
            if ($fromId instanceof Model) {
                $fromTable = $fromTable ?? $fromId->getTable();
                $fromId = $fromId->getKey();
            }
            if ($toId instanceof Model) {
                $toTable = $toTable ?? $toId->getTable();
                $toId = $toId->getKey();
            }
        }

        // Build the query with optional table labels
        $labelFrom = $fromTable ? ":$fromTable" : '';
        $labelTo = $toTable ? ":$toTable" : '';

        $cypher = "MATCH (a{$labelFrom} {id: \$fromId})-[r:$edgeType]->(b{$labelTo} {id: \$toId}) ".
                  'DELETE r '.
                  'RETURN count(r) as deleted';

        $result = $this->connection->select($cypher, [
            'fromId' => $fromId,
            'toId' => $toId,
        ]);

        if (! empty($result)) {
            $deleted = $result[0]['deleted'] ?? $result[0]->deleted ?? 0;

            return $deleted > 0;
        }

        return false;
    }

    public function findEdgesBetween($from, $to, ?string $type = null, ?string $fromTable = null, ?string $toTable = null): array
    {
        $fromId = $from instanceof Model ? $from->getKey() : $from;
        $toId = $to instanceof Model ? $to->getKey() : $to;

        // Get tables from models if not provided
        if (! $fromTable && $from instanceof Model) {
            $fromTable = $from->getTable();
        }
        if (! $toTable && $to instanceof Model) {
            $toTable = $to->getTable();
        }

        // Build Cypher query
        if ($fromTable && $toTable) {
            if ($type) {
                $cypher = "MATCH (a:$fromTable {id: \$fromId})-[r:$type]->(b:$toTable {id: \$toId}) ".
                          'RETURN r, type(r) as type';
            } else {
                $cypher = "MATCH (a:$fromTable {id: \$fromId})-[r]->(b:$toTable {id: \$toId}) ".
                          'RETURN r, type(r) as type';
            }
        } else {
            if ($type) {
                $cypher = 'MATCH (a {id: $fromId})-[r:'.$type.']->(b {id: $toId}) '.
                          'RETURN r, type(r) as type';
            } else {
                $cypher = 'MATCH (a {id: $fromId})-[r]->(b {id: $toId}) '.
                          'RETURN r, type(r) as type';
            }
        }

        $results = $this->connection->select($cypher, [
            'fromId' => $fromId,
            'toId' => $toId,
        ]);

        $edges = [];
        foreach ($results as $result) {
            $edge = $result['r'] ?? $result->r ?? [];
            $edgeType = $result['type'] ?? $result->type ?? null;

            $edges[] = [
                'id' => ['from' => $fromId, 'to' => $toId, 'type' => $edgeType],
                'type' => $edgeType,
                'properties' => is_array($edge) ? $edge : (array) $edge,
            ];
        }

        return $edges;
    }

    public function edgeExists($fromId, $toId, string $edgeType, ?string $fromTable = null, ?string $toTable = null): bool
    {
        $fromId = $fromId instanceof Model ? $fromId->getKey() : $fromId;
        $toId = $toId instanceof Model ? $toId->getKey() : $toId;

        // Build the query with optional table labels
        $labelFrom = $fromTable ? ":$fromTable" : '';
        $labelTo = $toTable ? ":$toTable" : '';

        $cypher = "MATCH (a{$labelFrom} {id: \$fromId})-[r:$edgeType]->(b{$labelTo} {id: \$toId}) ".
                  'RETURN count(r) as count';

        $result = $this->connection->select($cypher, [
            'fromId' => $fromId,
            'toId' => $toId,
        ]);

        if (! empty($result)) {
            $count = $result[0]['count'] ?? $result[0]->count ?? 0;

            return $count > 0;
        }

        return false;
    }

    // Keep the old API for backward compatibility
    public function deleteAllEdgesFromNode($nodeId, string $nodeLabel, string $edgeType, string $direction = 'out'): int
    {
        $pattern = match ($direction) {
            'out' => "(a:{$nodeLabel} {id: \$nodeId})-[r:{$edgeType}]->()",
            'in' => "()-[r:{$edgeType}]->(a:{$nodeLabel} {id: \$nodeId})",
            'both' => "(a:{$nodeLabel} {id: \$nodeId})-[r:{$edgeType}]-()",
        };

        $cypher = "MATCH {$pattern} DELETE r RETURN count(r) as deleted";

        $result = $this->connection->select($cypher, ['nodeId' => $nodeId]);

        return ! empty($result) ? (int) ($result[0]['deleted'] ?? $result[0]->deleted ?? 0) : 0;
    }
}
