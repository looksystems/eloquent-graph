<?php

namespace Look\EloquentCypher\Facades;

use Illuminate\Support\Facades\Facade;

class GraphSchema extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'graph.schema';
    }

    public static function label($label, $callback)
    {
        return static::getSchemaBuilder()->label($label, $callback);
    }

    public static function relationship($type, $callback)
    {
        return static::getSchemaBuilder()->relationship($type, $callback);
    }

    public static function dropLabel($label)
    {
        return static::getSchemaBuilder()->dropLabel($label);
    }

    public static function hasLabel($label)
    {
        return static::getSchemaBuilder()->hasLabel($label);
    }

    public static function hasConstraint($name)
    {
        return static::getSchemaBuilder()->hasConstraint($name);
    }

    public static function hasIndex($name)
    {
        return static::getSchemaBuilder()->hasIndex($name);
    }

    public static function dropConstraint($name)
    {
        return static::getSchemaBuilder()->dropConstraint($name);
    }

    public static function dropIndex($name)
    {
        return static::getSchemaBuilder()->dropIndex($name);
    }

    public static function renameConstraint($oldName, $newName)
    {
        return static::getSchemaBuilder()->renameConstraint($oldName, $newName);
    }

    protected static function getSchemaBuilder()
    {
        $connection = app('db')->connection('graph');

        return $connection->getSchemaBuilder();
    }
}
