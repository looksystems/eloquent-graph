<?php

namespace Look\EloquentCypher\Services;

use Illuminate\Database\Eloquent\Model;
use ReflectionClass;

class CompatibilityChecker
{
    public function requiresForeignKeys(Model $model, string $relationName): bool
    {
        // Check if model has $useNativeRelationships property set to true
        if ($this->hasNativeRelationshipsEnabled($model)) {
            return false;
        }

        // Check if model has foreign key attribute set
        $relation = $model->$relationName();
        if (method_exists($relation, 'getForeignKeyName')) {
            $foreignKey = $relation->getForeignKeyName();
            if ($model->hasAttribute($foreignKey) && $model->getAttribute($foreignKey) !== null) {
                return true;
            }
        }

        // Check if model class explicitly requires foreign keys
        $reflection = new ReflectionClass($model);
        if ($reflection->hasProperty('defaultRelationshipStorage')) {
            $prop = $reflection->getProperty('defaultRelationshipStorage');
            $prop->setAccessible(true);
            $value = $prop->getValue($model);
            if ($value === 'foreign_key') {
                return true;
            }
        }

        // Default: Models without native relationships require foreign keys
        return true;
    }

    public function suggestMigrationStrategy(Model $model, string $relationName): string
    {
        // If model uses native relationships, suggest edge-only
        if ($this->hasNativeRelationshipsEnabled($model)) {
            return 'edge';
        }

        // If model requires foreign keys, suggest hybrid
        if ($this->requiresForeignKeys($model, $relationName)) {
            return 'hybrid';
        }

        // Default to edge for new models
        return 'edge';
    }

    public function getIncompatibilityReasons(Model $model, string $relationName): array
    {
        $reasons = [];

        // Check for foreign key dependencies
        $relation = $model->$relationName();
        if (method_exists($relation, 'getForeignKeyName')) {
            $foreignKey = $relation->getForeignKeyName();
            if ($model->hasAttribute($foreignKey) && $model->getAttribute($foreignKey) !== null) {
                $reasons[] = "Model has foreign key attribute '$foreignKey' set";
            }
        }

        // Check for explicit foreign key configuration
        $reflection = new ReflectionClass($model);
        if ($reflection->hasProperty('defaultRelationshipStorage')) {
            $prop = $reflection->getProperty('defaultRelationshipStorage');
            $prop->setAccessible(true);
            $value = $prop->getValue($model);
            if ($value === 'foreign_key') {
                $reasons[] = "Model has defaultRelationshipStorage set to 'foreign_key'";
            }
        }

        // Check for third-party package usage (simplified check)
        if ($this->usesThirdPartyPackage($model)) {
            $reasons[] = 'Model may use third-party packages that depend on foreign keys';
        }

        return $reasons;
    }

    protected function hasNativeRelationshipsEnabled(Model $model): bool
    {
        $reflection = new ReflectionClass($model);

        // Check for $useNativeRelationships property
        if ($reflection->hasProperty('useNativeRelationships')) {
            $prop = $reflection->getProperty('useNativeRelationships');
            $prop->setAccessible(true);

            return (bool) $prop->getValue($model);
        }

        return false;
    }

    protected function usesThirdPartyPackage(Model $model): bool
    {
        // Check for common third-party package traits
        $traits = class_uses_recursive($model);

        $thirdPartyTraits = [
            'Spatie\ModelStatus\HasStatuses',
            'Spatie\Sluggable\HasSlug',
            'Spatie\Tags\HasTags',
            'Spatie\MediaLibrary\HasMedia',
            'Laravel\Scout\Searchable',
        ];

        foreach ($thirdPartyTraits as $trait) {
            if (isset($traits[$trait])) {
                return true;
            }
        }

        return false;
    }
}
