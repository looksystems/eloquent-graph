<?php

namespace Look\EloquentCypher\Commands;

use Illuminate\Console\Command;
use Look\EloquentCypher\Services\CompatibilityChecker;
use ReflectionClass;
use ReflectionMethod;

class CheckCompatibilityCommand extends Command
{
    protected $signature = 'graph:check-compatibility {model : The model class to check}';

    protected $description = 'Check if a model can safely use native Neo4j edges';

    public function handle(): int
    {
        $modelClass = $this->argument('model');

        // Handle namespace resolution
        if (! str_contains($modelClass, '\\')) {
            $modelClass = 'App\\Models\\'.$modelClass;
        }

        if (! class_exists($modelClass)) {
            $this->error("Model class $modelClass not found");

            return self::FAILURE;
        }

        $model = new $modelClass;
        $checker = new CompatibilityChecker;

        $this->info("Checking $modelClass for native edge compatibility...\n");

        $relationships = $this->getRelationships($model);

        $hasIssues = false;

        foreach ($relationships as $name => $relation) {
            $requiresForeignKeys = $checker->requiresForeignKeys($model, $name);
            $strategy = $checker->suggestMigrationStrategy($model, $name);

            $compatible = ! $requiresForeignKeys || $strategy === 'edge';
            $status = $compatible ? '✅' : '⚠️';

            $this->line("$status $name: Suggested strategy = $strategy");

            if (! $compatible) {
                $hasIssues = true;
                $reasons = $checker->getIncompatibilityReasons($model, $name);
                foreach ($reasons as $reason) {
                    $this->line("   - $reason");
                }
            }
        }

        if (! $hasIssues) {
            $this->info("\n✅ All relationships are compatible with native edges!");
        } else {
            $this->warn("\n⚠️  Some relationships may require hybrid strategy for compatibility.");
        }

        return self::SUCCESS;
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
                // Skip methods that throw exceptions
                continue;
            }
        }

        return $relationships;
    }
}
