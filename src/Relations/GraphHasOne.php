<?php

namespace Look\EloquentCypher\Relations;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class GraphHasOne extends \Look\EloquentCypher\Relations\GraphHasMany
{
    public function getResults()
    {
        $parent = $this->getParentKey();

        return ! is_null($parent) ? $this->query->first() : $this->getDefaultFor($this->parent);
    }

    public function initRelation(array $models, $relation)
    {
        foreach ($models as $model) {
            $model->setRelation($relation, $this->getDefaultFor($model));
        }

        return $models;
    }

    protected function getDefaultFor(Model $parent)
    {
        return null;
    }

    public function match(array $models, Collection $results, $relation)
    {
        return $this->matchOne($models, $results, $relation);
    }

    public function matchOne(array $models, Collection $results, $relation)
    {
        $dictionary = $this->buildDictionary($results);

        foreach ($models as $model) {
            $key = $model->{$this->localKey};

            if (isset($dictionary[$key])) {
                $model->setRelation(
                    $relation,
                    reset($dictionary[$key])
                );
            }
        }

        return $models;
    }

    protected function buildDictionary(Collection $results)
    {
        $foreign = $this->getForeignKeyName();

        $dictionary = [];

        foreach ($results as $result) {
            $key = $result->{$foreign};

            if (! isset($dictionary[$key])) {
                $dictionary[$key] = [];
            }

            $dictionary[$key][] = $result;
        }

        return $dictionary;
    }

    public function addConstraints()
    {
        parent::addConstraints();

        if (static::$constraints) {
            // Order by ID to ensure consistent results
            $this->query->orderBy($this->related->getKeyName());
            // Only add limit when not eager loading
            $this->query->limit(1);
        }
    }

    public function addEagerConstraints(array $models)
    {
        parent::addEagerConstraints($models);
        // Don't add limit here - we need all matching records for multiple parents
    }
}
