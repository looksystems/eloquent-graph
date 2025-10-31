<?php

namespace Look\EloquentCypher\Checkers;

use Illuminate\Database\Eloquent\Model;

class GlobalScopeChecker
{
    /**
     * Check if a model has global scopes that require query builder usage.
     */
    public static function hasGlobalScopes(Model $model): bool
    {
        return ! empty($model->getGlobalScopes());
    }

    /**
     * Check if a model uses soft deletes trait.
     */
    public static function usesSoftDeletes(Model $model): bool
    {
        return in_array('Illuminate\Database\Eloquent\SoftDeletes', class_uses_recursive(get_class($model)));
    }

    /**
     * Determine if the model requires query builder for proper scope application.
     */
    public static function requiresQueryBuilder(Model $model): bool
    {
        return static::hasGlobalScopes($model) || static::usesSoftDeletes($model);
    }
}
