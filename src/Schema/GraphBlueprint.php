<?php

namespace Look\EloquentCypher\Schema;

use Closure;
use Illuminate\Support\Fluent;

class GraphBlueprint
{
    protected $label;

    protected $commands = [];

    protected $properties = [];

    protected $isRelationship = false;

    public function __construct($label, ?Closure $callback = null)
    {
        $this->label = $label;

        if ($callback) {
            $callback($this);
        }
    }

    public function setAsRelationship()
    {
        $this->isRelationship = true;

        return $this;
    }

    public function property($name)
    {
        $property = new Fluent([
            'name' => $name,
            'type' => 'property',
        ]);

        $this->properties[$name] = $property;

        return new GraphPropertyDefinition($property, $this);
    }

    public function index($properties, $name = null)
    {
        if (! is_array($properties)) {
            $properties = [$properties];
        }

        $this->commands[] = $this->createCommand('index', ['properties' => $properties, 'indexName' => $name]);

        return $this;
    }

    public function unique($property, $name = null)
    {
        if (! is_array($property)) {
            $property = [$property];
        }

        $this->commands[] = $this->createCommand('unique', [
            'properties' => $property,
            'constraintName' => $name,
        ]);

        return $this;
    }

    public function dropIndex($name)
    {
        $this->commands[] = $this->createCommand('dropIndex', ['indexName' => $name]);

        return $this;
    }

    public function dropConstraint($name)
    {
        $this->commands[] = $this->createCommand('dropConstraint', ['constraintName' => $name]);

        return $this;
    }

    public function addCommand($name, array $parameters = [])
    {
        $this->commands[] = $this->createCommand($name, $parameters);

        return $this;
    }

    protected function createCommand($name, array $parameters = [])
    {
        return array_merge(compact('name'), $parameters, [
            'label' => $this->label,
            'isRelationship' => $this->isRelationship,
        ]);
    }

    public function getCommands()
    {
        $commands = $this->commands;

        // Add commands from property definitions only once
        foreach ($this->properties as $property) {
            if (isset($property['unique']) && $property['unique']) {
                $commands[] = $this->createCommand('unique', [
                    'properties' => [$property['name']],
                    'constraintName' => $property['uniqueName'] ?? null,
                ]);
            }
            if (isset($property['index']) && $property['index']) {
                $commands[] = $this->createCommand('index', [
                    'properties' => [$property['name']],
                    'indexName' => $property['indexName'] ?? null,
                ]);
            }
            if (isset($property['textIndex']) && $property['textIndex']) {
                $commands[] = $this->createCommand('textIndex', [
                    'properties' => [$property['name']],
                    'indexName' => $property['textIndexName'] ?? null,
                ]);
            }
        }

        return $commands;
    }

    public function getLabel()
    {
        return $this->label;
    }

    public function isRelationship()
    {
        return $this->isRelationship;
    }
}

class GraphPropertyDefinition
{
    protected $property;

    protected $blueprint;

    public function __construct($property, $blueprint)
    {
        $this->property = $property;
        $this->blueprint = $blueprint;
    }

    public function unique($name = null)
    {
        $this->property['unique'] = true;
        $this->property['uniqueName'] = $name;

        return $this;
    }

    public function index($name = null)
    {
        $this->property['index'] = true;
        $this->property['indexName'] = $name;

        return $this;
    }

    public function textIndex($name = null)
    {
        $this->property['textIndex'] = true;
        $this->property['textIndexName'] = $name;

        return $this;
    }
}
