<?php

namespace Look\EloquentCypher\Builders;

use Illuminate\Database\Eloquent\Model;
use WikibaseSolutions\CypherDSL\Query;

trait GraphPatternHelpers
{
    /**
     * Traverse outgoing relationships from the current node.
     */
    public function outgoing(string $type, ?string $targetLabel = null): self
    {
        $source = $this->getSourceNode();
        $target = $targetLabel ? Query::node($targetLabel)->named('target') : Query::node()->named('target');

        // Build the match pattern using relationshipTo
        $pattern = $source->relationshipTo($target)->withType($type);

        $this->query->match($pattern);

        return $this;
    }

    /**
     * Traverse incoming relationships to the current node.
     */
    public function incoming(string $type, ?string $sourceLabel = null): self
    {
        $target = $this->getSourceNode();
        $source = $sourceLabel ? Query::node($sourceLabel)->named('source') : Query::node()->named('source');

        // Build the match pattern (source)-[rel]->(target)
        $pattern = $source->relationshipTo($target)->withType($type);

        $this->query->match($pattern);

        return $this;
    }

    /**
     * Traverse bidirectional relationships (any direction).
     */
    public function bidirectional(string $type, ?string $label = null): self
    {
        $source = $this->getSourceNode();
        $other = $label ? Query::node($label)->named('other') : Query::node()->named('other');

        // Use undirected relationship pattern
        $pattern = $source->relationshipUni($other)->withType($type);

        $this->query->match($pattern);

        return $this;
    }

    /**
     * Find the shortest path between two nodes.
     */
    public function shortestPath(Model|int $target, ?string $relType = null, ?int $maxDepth = null): self
    {
        // For shortest path, we need to build raw Cypher
        // The DSL doesn't have native shortest path support
        $sourceLabel = $this->getSourceLabel();
        $targetData = $this->resolveTargetData($target);

        // Build the relationship pattern
        if ($relType !== null) {
            $relPattern = ":{$relType}*".($maxDepth !== null ? "..{$maxDepth}" : '');
        } else {
            $relPattern = '*'.($maxDepth !== null ? "..{$maxDepth}" : '');
        }

        // Build the shortest path query
        if ($this->sourceNode) {
            // Instance query - use specific node ID
            $sourceMatch = "(n:{$sourceLabel} {id: {$this->sourceNode->getKey()}})";
        } else {
            // Static query - match any node
            $sourceMatch = "(n:{$sourceLabel})";
        }

        $targetMatch = "(target:{$targetData['label']} {id: {$targetData['id']}})";
        $pathQuery = "path = shortestPath({$sourceMatch}-[{$relPattern}]-{$targetMatch})";

        // Use raw to add the MATCH clause
        $this->query->raw('MATCH', $pathQuery);

        return $this;
    }

    /**
     * Find all paths between two nodes.
     */
    public function allPaths(Model|int $target, ?string $relType = null, int $maxDepth = 5): self
    {
        // Build raw Cypher for all paths
        $sourceLabel = $this->getSourceLabel();
        $targetData = $this->resolveTargetData($target);

        // Build the relationship pattern
        if ($relType !== null) {
            $relPattern = ":{$relType}*..{$maxDepth}";
        } else {
            $relPattern = "*..{$maxDepth}";
        }

        // Build the all paths query
        if ($this->sourceNode) {
            // Instance query - use specific node ID
            $sourceMatch = "(n:{$sourceLabel} {id: {$this->sourceNode->getKey()}})";
        } else {
            // Static query - match any node
            $sourceMatch = "(n:{$sourceLabel})";
        }

        $targetMatch = "(target:{$targetData['label']} {id: {$targetData['id']}})";
        $pathQuery = "path = {$sourceMatch}-[{$relPattern}]-{$targetMatch}";

        // Use raw to add the MATCH clause
        $this->query->raw('MATCH', $pathQuery);

        return $this;
    }

    /**
     * Get the source node for pattern matching.
     */
    protected function getSourceNode()
    {
        if ($this->sourceNode) {
            // Instance query - use the specific node with its ID
            $node = Query::node($this->sourceNode->getTable())->named('n');

            return $node;
        }

        if ($this->model) {
            // Static query - use the model's table
            $instance = new $this->model;

            return Query::node($instance->getTable())->named('n');
        }

        // No context - return generic node
        return Query::node()->named('n');
    }

    /**
     * Get the source label for queries.
     */
    protected function getSourceLabel(): string
    {
        if ($this->sourceNode) {
            return $this->sourceNode->getTable();
        }

        if ($this->model) {
            $instance = new $this->model;

            return $instance->getTable();
        }

        // Default to generic label if no context
        return 'Node';
    }

    /**
     * Resolve target node data from a model or ID.
     */
    protected function resolveTargetData(Model|int $target): array
    {
        if ($target instanceof Model) {
            return [
                'label' => $target->getTable(),
                'id' => $target->getKey(),
            ];
        }

        // Assume it's an ID, use the same label as the source if available
        if ($this->model) {
            $instance = new $this->model;

            return [
                'label' => $instance->getTable(),
                'id' => $target,
            ];
        }

        return [
            'label' => 'Node',
            'id' => $target,
        ];
    }

    /**
     * Resolve a target node from a model or ID.
     */
    protected function resolveTargetNode(Model|int $target)
    {
        if ($target instanceof Model) {
            return Query::node($target->getTable())
                ->named('target')
                ->withProperties(['id' => $target->getKey()]);
        }

        // Assume it's an ID, use the same label as the source if available
        if ($this->model) {
            $instance = new $this->model;

            return Query::node($instance->getTable())
                ->named('target')
                ->withProperties(['id' => $target]);
        }

        return Query::node()->named('target')->withProperties(['id' => $target]);
    }
}
