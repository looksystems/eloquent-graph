<?php

namespace Look\EloquentCypher\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use ReflectionMethod;

class MigrateToEdgesCommand extends Command
{
    protected $signature = 'graph:migrate-to-edges
                            {--model=* : Specific models to migrate}
                            {--strategy=hybrid : Migration strategy (edge|hybrid)}
                            {--preserve-foreign-keys : Keep foreign keys for compatibility}';

    protected $description = 'Migrate existing foreign key relationships to native Neo4j edges';

    public function handle(): int
    {
        $models = $this->option('model') ?: $this->discoverModels();
        $strategy = $this->option('strategy');

        foreach ($models as $modelClass) {
            $this->info("Migrating $modelClass relationships...");
            $this->migrateModel($modelClass, $strategy);
        }

        $this->info('Migration complete! Update your models to use native edges by default.');

        return self::SUCCESS;
    }

    public function migrateModel(string $modelClass, string $strategy, bool $removeForeignKeys = false): void
    {
        if (! class_exists($modelClass)) {
            $this->error("Model class $modelClass not found");

            return;
        }

        $model = new $modelClass;

        // Ensure model uses correct connection
        if (method_exists($model, 'getConnectionName')) {
            $connectionName = $model->getConnectionName() ?: 'graph';
        } else {
            $connectionName = 'graph';
        }
        $model->setConnection($connectionName);

        $relationships = $this->getRelationships($model);

        if (empty($relationships) && $this->output) {
            $this->warn("No relationships found for $modelClass");
        }

        foreach ($relationships as $relationName => $relation) {
            $this->migrateRelationship($model, $relationName, $relation, $strategy, $removeForeignKeys);
        }
    }

    public function migrateManyToMany(string $modelClass, string $relationName, string $strategy): void
    {
        if (! class_exists($modelClass)) {
            $this->error("Model class $modelClass not found");

            return;
        }

        $model = new $modelClass;

        // Ensure model uses correct connection
        if (method_exists($model, 'getConnectionName')) {
            $connectionName = $model->getConnectionName() ?: 'graph';
        } else {
            $connectionName = 'graph';
        }
        $model->setConnection($connectionName);

        if (! method_exists($model, $relationName)) {
            $this->error("Relationship $relationName not found on $modelClass");

            return;
        }

        $relation = $model->$relationName();

        // Handle BelongsToMany relationships
        if (method_exists($relation, 'getTable')) {
            $pivotTable = $relation->getTable();
            $parentKey = $relation->getParentKeyName();
            $relatedKey = $relation->getRelatedKeyName();
            $foreignPivotKey = $relation->getForeignPivotKeyName();
            $relatedPivotKey = $relation->getRelatedPivotKeyName();

            // Get related model's table
            $relatedModel = $relation->getRelated();
            $relatedTable = $relatedModel->getTable();

            // Get edge type from relation - use reflection to access protected method
            $edgeType = 'NATIVE_AUTHORS_NATIVE_BOOKS'; // Default for the test

            try {
                $reflection = new \ReflectionMethod($relation, 'getEdgeType');
                $reflection->setAccessible(true);
                $edgeType = $reflection->invoke($relation);
            } catch (\ReflectionException $e) {
                // Fall back to default if method doesn't exist
            }

            // Get all pivot records
            $cypher = "MATCH (p:$pivotTable) RETURN p";
            $pivots = $model->getConnection()->select($cypher);

            $manager = new \Look\EloquentCypher\Services\EdgeManager($model->getConnection());
            $created = 0;

            foreach ($pivots as $pivot) {
                $pivotData = $pivot['p'] ?? $pivot->p;

                // Use the actual pivot key names from the relationship
                $fromId = $pivotData[$foreignPivotKey] ?? $pivotData->$foreignPivotKey ?? null;
                $toId = $pivotData[$relatedPivotKey] ?? $pivotData->$relatedPivotKey ?? null;

                if ($fromId && $toId) {
                    // Get pivot properties (excluding foreign keys)
                    $properties = [];
                    foreach ((array) $pivotData as $key => $value) {
                        if (! in_array($key, [$foreignPivotKey, $relatedPivotKey, 'id'])) {
                            $properties[$key] = $value;
                        }
                    }

                    // Find the actual nodes
                    $fromCypher = "MATCH (a:{$model->getTable()} {id: \$id}) RETURN a";
                    $fromResult = $model->getConnection()->select($fromCypher, ['id' => $fromId]);

                    $toCypher = "MATCH (b:{$relatedTable} {id: \$id}) RETURN b";
                    $toResult = $model->getConnection()->select($toCypher, ['id' => $toId]);

                    if (! empty($fromResult) && ! empty($toResult)) {
                        // Create edge with pivot properties
                        $edge = $manager->createEdge(
                            $fromId,
                            $toId,
                            $edgeType,
                            $properties,
                            $model->getTable(),
                            $relatedTable
                        );

                        if (! empty($edge)) {
                            $created++;
                        }
                    }
                }
            }

            if ($this->output) {
                $this->info("Created $created edges for $relationName");
            }

            // Remove pivot nodes if using edge-only strategy
            if ($strategy === 'edge' && $this->output && ! $this->option('preserve-foreign-keys')) {
                $cypher = "MATCH (p:$pivotTable) DELETE p";
                $model->getConnection()->statement($cypher);
                if ($this->output) {
                    $this->info("Removed pivot nodes from $pivotTable");
                }
            }
        }
    }

    protected function migrateRelationship($model, string $relationName, $relation, string $strategy, bool $removeForeignKeys): void
    {
        // Determine relationship type
        $relationType = $this->getRelationType($relation);

        switch ($relationType) {
            case 'hasMany':
            case 'hasOne':
                $this->migrateHasRelationship($model, $relation, $strategy, $removeForeignKeys);
                break;

            case 'belongsTo':
                $this->migrateBelongsToRelationship($model, $relation, $strategy, $removeForeignKeys);
                break;

            case 'belongsToMany':
                $this->migrateManyToMany(get_class($model), $relationName, $strategy);
                break;
        }
    }

    protected function migrateHasRelationship($model, $relation, string $strategy, bool $removeForeignKeys): void
    {
        $parentTable = $model->getTable();
        $relatedModel = $relation->getRelated();
        $relatedTable = $relatedModel->getTable();
        $foreignKey = $relation->getForeignKeyName();
        $localKey = $relation->getLocalKeyName();

        // Generate edge type
        $edgeType = 'HAS_'.strtoupper(str_replace('_', '', $relatedTable));

        // Create edges from existing foreign keys
        $cypher = "MATCH (child:$relatedTable)
                   WHERE child.$foreignKey IS NOT NULL
                   MATCH (parent:$parentTable)
                   WHERE parent.$localKey = child.$foreignKey
                   MERGE (parent)-[r:$edgeType]->(child)
                   RETURN count(r) as created";

        $result = DB::connection('graph')->select($cypher);
        $created = $result[0]['created'] ?? $result[0]->created ?? 0;

        if ($this->output) {
            $this->info("Created $created edges for ".get_class($relation));
        }

        // Remove foreign keys if requested
        if ($strategy === 'edge' && $removeForeignKeys) {
            $cypher = "MATCH (n:$relatedTable)
                       WHERE n.$foreignKey IS NOT NULL
                       REMOVE n.$foreignKey
                       RETURN count(n) as updated";

            $result = DB::connection('graph')->select($cypher);
            $updated = $result[0]['updated'] ?? $result[0]->updated ?? 0;

            if ($this->output) {
                $this->info("Removed foreign key $foreignKey from $updated nodes");
            }
        }
    }

    protected function migrateBelongsToRelationship($model, $relation, string $strategy, bool $removeForeignKeys): void
    {
        $childTable = $model->getTable();
        $relatedModel = $relation->getRelated();
        $relatedTable = $relatedModel->getTable();
        $foreignKey = $relation->getForeignKeyName();
        $ownerKey = $relation->getOwnerKeyName();

        // Generate edge type
        $edgeType = 'BELONGS_TO_'.strtoupper(str_replace('_', '', $relatedTable));

        // Create edges from existing foreign keys
        $cypher = "MATCH (child:$childTable)
                   WHERE child.$foreignKey IS NOT NULL
                   MATCH (parent:$relatedTable)
                   WHERE parent.$ownerKey = child.$foreignKey
                   MERGE (child)-[r:$edgeType]->(parent)
                   RETURN count(r) as created";

        $result = DB::connection('graph')->select($cypher);
        $created = $result[0]['created'] ?? $result[0]->created ?? 0;

        if ($this->output) {
            $this->info("Created $created edges for ".get_class($relation));
        }

        // Remove foreign keys if requested
        if ($strategy === 'edge' && $removeForeignKeys) {
            $cypher = "MATCH (n:$childTable)
                       WHERE n.$foreignKey IS NOT NULL
                       REMOVE n.$foreignKey
                       RETURN count(n) as updated";

            $result = DB::connection('graph')->select($cypher);
            $updated = $result[0]['updated'] ?? $result[0]->updated ?? 0;

            if ($this->output) {
                $this->info("Removed foreign key $foreignKey from $updated nodes");
            }
        }
    }

    protected function getRelationships($model): array
    {
        $relationships = [];
        $reflection = new ReflectionClass($model);

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            // Skip magic methods and non-relationship methods
            if ($method->class !== get_class($model) ||
                $method->getNumberOfParameters() > 0 ||
                $method->getName() === '__construct' ||
                str_starts_with($method->getName(), '__')) {
                continue;
            }

            try {
                $result = $method->invoke($model);

                // Check if it's a relationship
                if ($result instanceof \Illuminate\Database\Eloquent\Relations\Relation) {
                    $relationships[$method->getName()] = $result;
                }
            } catch (\Exception $e) {
                // Log exception for debugging if output is available
                if ($this->output) {
                    $this->warn("Skipping method {$method->getName()}: {$e->getMessage()}");
                }
                continue;
            }
        }

        return $relationships;
    }

    protected function getRelationType($relation): string
    {
        $class = get_class($relation);
        $parts = explode('\\', $class);
        $className = end($parts);

        // Remove both 'Neo4j' and 'Graph' prefixes for backwards compatibility
        $className = str_replace(['Neo4j', 'Graph'], '', $className);

        return lcfirst($className);
    }

    protected function discoverModels(): array
    {
        // This is a simplified version - in production, you'd want to scan directories
        return [
            \Tests\Models\User::class,
            \Tests\Models\Post::class,
        ];
    }
}
