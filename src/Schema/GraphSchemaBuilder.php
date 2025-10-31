<?php

namespace Look\EloquentCypher\Schema;

use Closure;

class GraphSchemaBuilder
{
    protected $connection;

    protected $grammar;

    public function __construct(\Look\EloquentCypher\GraphConnection $connection)
    {
        $this->connection = $connection;
        $this->grammar = new \Look\EloquentCypher\Schema\GraphSchemaGrammar;
    }

    public function label($label, Closure $callback)
    {
        $blueprint = new \Look\EloquentCypher\Schema\GraphBlueprint($label);
        $callback($blueprint);

        $this->build($blueprint);
    }

    public function relationship($type, Closure $callback)
    {
        $blueprint = new \Look\EloquentCypher\Schema\GraphBlueprint($type);
        $blueprint->setAsRelationship();
        $callback($blueprint);

        $this->build($blueprint);
    }

    public function dropLabel($label)
    {
        // Drop all constraints and indexes for this label
        $constraints = $this->connection->select("SHOW CONSTRAINTS WHERE labelsOrTypes = ['$label']");
        foreach ($constraints as $constraint) {
            if (isset($constraint['name'])) {
                $this->dropConstraint($constraint['name']);
            }
        }

        $indexes = $this->connection->select("SHOW INDEXES WHERE labelsOrTypes = ['$label']");
        foreach ($indexes as $index) {
            if (isset($index['name'])) {
                $this->dropIndex($index['name']);
            }
        }
    }

    public function hasLabel($label)
    {
        try {
            // Check if there are any indexes or constraints on this label
            // This is more reliable than checking for nodes since labels can exist without nodes
            $indexes = $this->connection->select('SHOW INDEXES');
            $constraints = $this->connection->select('SHOW CONSTRAINTS');

            // Check indexes
            foreach ($indexes as $index) {
                $labelsOrTypes = is_array($index) ? ($index['labelsOrTypes'] ?? []) : ($index->labelsOrTypes ?? []);
                if (in_array($label, $labelsOrTypes)) {
                    return true;
                }
            }

            // Check constraints
            foreach ($constraints as $constraint) {
                $labelsOrTypes = is_array($constraint) ? ($constraint['labelsOrTypes'] ?? []) : ($constraint->labelsOrTypes ?? []);
                if (in_array($label, $labelsOrTypes)) {
                    return true;
                }
            }

            // Also check if there are any nodes with this label
            $result = $this->connection->select("MATCH (n:$label) RETURN count(n) as count LIMIT 1");
            if (count($result) > 0) {
                $count = is_array($result[0]) ? ($result[0]['count'] ?? 0) : ($result[0]->count ?? 0);
                if ($count > 0) {
                    return true;
                }
            }

            return false;
        } catch (\Exception $e) {
            // If the query fails, return false
            return false;
        }
    }

    public function hasConstraint($name)
    {
        $constraints = $this->connection->select("SHOW CONSTRAINTS WHERE name = '$name'");

        return count($constraints) > 0;
    }

    public function hasIndex($name)
    {
        $indexes = $this->connection->select("SHOW INDEXES WHERE name = '$name'");

        return count($indexes) > 0;
    }

    public function dropConstraint($name)
    {
        $this->connection->statement("DROP CONSTRAINT $name IF EXISTS");
    }

    public function dropIndex($name)
    {
        $this->connection->statement("DROP INDEX $name IF EXISTS");
    }

    public function renameConstraint($oldName, $newName)
    {
        // Neo4j doesn't support renaming constraints directly
        // We need to get the constraint definition, drop it, and recreate with new name
        $constraints = $this->connection->select("SHOW CONSTRAINTS WHERE name = '$oldName'");

        if (count($constraints) > 0) {
            $constraint = $constraints[0];

            // Drop old constraint
            $this->dropConstraint($oldName);

            // Recreate with new name based on type
            $label = $constraint['labelsOrTypes'][0] ?? null;
            $properties = $constraint['properties'] ?? [];

            if ($label && $properties) {
                $propertyList = implode(', ', array_map(function ($p) {
                    return "n.$p";
                }, $properties));

                switch ($constraint['type'] ?? '') {
                    case 'UNIQUENESS':
                        $this->connection->statement(
                            "CREATE CONSTRAINT $newName IF NOT EXISTS FOR (n:$label) REQUIRE ($propertyList) IS UNIQUE"
                        );
                        break;
                }
            }
        }
    }

    protected function build(\Look\EloquentCypher\Schema\GraphBlueprint $blueprint)
    {
        $statements = $this->grammar->compile($blueprint);

        // Schema DDL operations (CREATE/DROP CONSTRAINT/INDEX) should NEVER use batch execution
        // They are transactional, lock-intensive, and prone to timeout in batch mode
        // Sequential execution is more reliable for schema changes with minimal performance impact
        foreach ($statements as $statement) {
            $this->connection->statement($statement);
        }
    }

    protected function getSchemaIntrospector()
    {
        return $this->connection->getDriver()->getSchemaIntrospector();
    }

    public function getAllLabels(): array
    {
        try {
            return $this->getSchemaIntrospector()->getLabels();
        } catch (\Exception $e) {
            return [];
        }
    }

    public function getAllRelationshipTypes(): array
    {
        try {
            return $this->getSchemaIntrospector()->getRelationshipTypes();
        } catch (\Exception $e) {
            return [];
        }
    }

    public function getAllPropertyKeys(): array
    {
        try {
            return $this->getSchemaIntrospector()->getPropertyKeys();
        } catch (\Exception $e) {
            return [];
        }
    }

    public function getConstraints(): array
    {
        try {
            return $this->getSchemaIntrospector()->getConstraints();
        } catch (\Exception $e) {
            return [];
        }
    }

    public function getIndexes(): array
    {
        try {
            return $this->getSchemaIntrospector()->getIndexes();
        } catch (\Exception $e) {
            return [];
        }
    }

    public function introspect(): array
    {
        try {
            return $this->getSchemaIntrospector()->introspect();
        } catch (\Exception $e) {
            return [
                'labels' => [],
                'relationshipTypes' => [],
                'propertyKeys' => [],
                'constraints' => [],
                'indexes' => [],
            ];
        }
    }

    public function getConnection()
    {
        return $this->connection;
    }

    /**
     * Get the column listing for a given table.
     *
     * @param  string  $table
     * @return array
     */
    public function getColumnListing($table)
    {
        // In Neo4j, we don't have fixed schemas like SQL tables
        // Return an empty array to indicate no fixed columns
        return [];
    }
}
