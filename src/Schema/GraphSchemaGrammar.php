<?php

namespace Look\EloquentCypher\Schema;

class GraphSchemaGrammar
{
    public function compile(\Look\EloquentCypher\Schema\GraphBlueprint $blueprint)
    {
        $statements = [];
        $commands = $blueprint->getCommands();

        foreach ($commands as $command) {
            $method = 'compile'.ucfirst($command['name']);

            if (method_exists($this, $method)) {
                $statement = $this->{$method}($blueprint, $command);
                if ($statement) {
                    $statements[] = $statement;
                }
            }
        }

        return $statements;
    }

    protected function compileIndex($blueprint, $command)
    {
        $label = $command['label'];
        $properties = $command['properties'];
        $name = $command['indexName'] ?? $this->generateIndexName($label, $properties);
        $entity = $command['isRelationship'] ? 'r' : 'n';
        $entityType = $command['isRelationship'] ? '' : ':';

        if (count($properties) === 1) {
            $property = $properties[0];
            if ($command['isRelationship']) {
                return "CREATE INDEX $name IF NOT EXISTS FOR ()-[r:$label]-() ON (r.$property)";
            } else {
                return "CREATE INDEX $name IF NOT EXISTS FOR (n:$label) ON (n.$property)";
            }
        } else {
            $propertyList = implode(', ', array_map(function ($p) use ($entity) {
                return "$entity.$p";
            }, $properties));
            if ($command['isRelationship']) {
                return "CREATE INDEX $name IF NOT EXISTS FOR ()-[r:$label]-() ON ($propertyList)";
            } else {
                return "CREATE INDEX $name IF NOT EXISTS FOR (n:$label) ON ($propertyList)";
            }
        }
    }

    protected function compileTextIndex($blueprint, $command)
    {
        $label = $command['label'];
        $properties = $command['properties'];
        $name = $command['indexName'] ?? $this->generateIndexName($label, $properties, 'text');

        $property = $properties[0];

        return "CREATE TEXT INDEX $name IF NOT EXISTS FOR (n:$label) ON (n.$property)";
    }

    protected function compileUnique($blueprint, $command)
    {
        $label = $command['label'];
        $properties = $command['properties'];
        $name = $command['constraintName'] ?? $this->generateConstraintName($label, $properties, 'unique');
        $entity = $command['isRelationship'] ? 'r' : 'n';
        $entityType = $command['isRelationship'] ? '' : ':';

        if ($command['isRelationship']) {
            // Relationships in Neo4j don't support unique constraints the same way
            // We'll create an index instead
            return $this->compileIndex($blueprint, $command);
        }

        if (count($properties) === 1) {
            $property = $properties[0];

            return "CREATE CONSTRAINT $name IF NOT EXISTS FOR (n:$label) REQUIRE n.$property IS UNIQUE";
        } else {
            $propertyList = implode(', ', array_map(function ($p) {
                return "n.$p";
            }, $properties));

            return "CREATE CONSTRAINT $name IF NOT EXISTS FOR (n:$label) REQUIRE ($propertyList) IS UNIQUE";
        }
    }

    protected function compileDropIndex($blueprint, $command)
    {
        $name = $command['indexName'];

        return "DROP INDEX $name IF EXISTS";
    }

    protected function compileDropConstraint($blueprint, $command)
    {
        $name = $command['constraintName'];

        return "DROP CONSTRAINT $name IF EXISTS";
    }

    protected function generateIndexName($label, $properties, $type = 'index')
    {
        $parts = array_merge(
            [strtolower($label)],
            array_map('strtolower', $properties),
            [$type]
        );

        return implode('_', $parts);
    }

    protected function generateConstraintName($label, $properties, $type)
    {
        $parts = array_merge(
            [strtolower($label)],
            array_map('strtolower', $properties),
            [$type]
        );

        return implode('_', $parts);
    }
}
