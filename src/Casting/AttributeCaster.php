<?php

namespace Look\EloquentCypher\Casting;

use Illuminate\Support\Collection as BaseCollection;

class AttributeCaster
{
    /**
     * The model instance.
     */
    protected $model;

    /**
     * Create a new attribute caster instance.
     */
    public function __construct($model)
    {
        $this->model = $model;
    }

    /**
     * Cast an attribute to a native PHP type.
     */
    public function cast(string $key, $value)
    {
        if (is_null($value)) {
            return $value;
        }

        $castType = $this->model->getCastType($key);

        // Handle encrypted types - delegate to parent's implementation
        if (str_starts_with($castType, 'encrypted')) {
            return $this->model->castAttributeUsingParent($key, $value);
        }

        // Try to cast using dedicated methods
        $castMethod = 'castTo'.str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $castType)));
        if (method_exists($this, $castMethod)) {
            return $this->$castMethod($value, $key);
        }

        // Handle remaining cast types
        if ($this->model->isClassCastable($key)) {
            return $this->model->getClassCastableAttributeValue($key, $value);
        }

        if ($this->model->isEnumCastable($key)) {
            return $this->model->getEnumCastableAttributeValue($key, $value);
        }

        if ($this->model->isJsonCastable($key) && ! is_null($value)) {
            return $this->model->fromJson($value);
        }

        if ($this->model->hasCast($key, $this->model->getPrimitiveCastTypes())) {
            return $value;
        }

        return $value;
    }

    /**
     * Cast value to integer.
     */
    protected function castToInt($value, $key)
    {
        return (int) $value;
    }

    /**
     * Cast value to integer (alias).
     */
    protected function castToInteger($value, $key)
    {
        return (int) $value;
    }

    /**
     * Cast value to real.
     */
    protected function castToReal($value, $key)
    {
        return $this->model->fromFloat($value);
    }

    /**
     * Cast value to float.
     */
    protected function castToFloat($value, $key)
    {
        return $this->model->fromFloat($value);
    }

    /**
     * Cast value to double.
     */
    protected function castToDouble($value, $key)
    {
        return (float) $value;
    }

    /**
     * Cast value to decimal.
     */
    protected function castToDecimal($value, $key)
    {
        $casts = $this->model->getCasts();
        $precision = explode(':', $casts[$key], 2)[1] ?? 2;

        return $this->model->asDecimal($value, $precision);
    }

    /**
     * Cast value to string.
     */
    protected function castToString($value, $key)
    {
        return (string) $value;
    }

    /**
     * Cast value to bool.
     */
    protected function castToBool($value, $key)
    {
        return $this->model->asBool($value);
    }

    /**
     * Cast value to boolean (alias).
     */
    protected function castToBoolean($value, $key)
    {
        return $this->model->asBool($value);
    }

    /**
     * Cast value to object.
     */
    protected function castToObject($value, $key)
    {
        return $this->model->fromJson($value, true);
    }

    /**
     * Cast value to array.
     */
    protected function castToArray($value, $key)
    {
        // Handle Neo4j native types (CypherList, CypherMap)
        if ($value instanceof \Laudis\Neo4j\Types\CypherList) {
            return $value->toArray();
        }
        if ($value instanceof \Laudis\Neo4j\Types\CypherMap) {
            return $value->toArray();
        }

        // Already a PHP array - return as-is
        if (is_array($value)) {
            return $value;
        }

        // Otherwise try JSON decoding
        return $this->model->castJsonAttribute($value);
    }

    /**
     * Cast value to json.
     */
    protected function castToJson($value, $key)
    {
        // Handle Neo4j native types (CypherList, CypherMap)
        if ($value instanceof \Laudis\Neo4j\Types\CypherList) {
            return $value->toArray();
        }
        if ($value instanceof \Laudis\Neo4j\Types\CypherMap) {
            return $value->toArray();
        }

        // Already a PHP array - return as-is
        if (is_array($value)) {
            return $value;
        }

        // Otherwise try JSON decoding
        return $this->model->castJsonAttribute($value);
    }

    /**
     * Cast value to collection.
     */
    protected function castToCollection($value, $key)
    {
        $decoded = $this->model->castJsonAttribute($value);

        return new BaseCollection($decoded);
    }

    /**
     * Cast value to hashed.
     */
    protected function castToHashed($value, $key)
    {
        return $this->model->castAttributeUsingParent($key, $value);
    }

    /**
     * Cast value to date.
     */
    protected function castToDate($value, $key)
    {
        return $this->model->asDate($value);
    }

    /**
     * Cast value to datetime.
     */
    protected function castToDatetime($value, $key)
    {
        return $this->model->asDateTime($value);
    }

    /**
     * Cast value to datetime with custom format.
     */
    protected function castToCustomDatetime($value, $key)
    {
        return $this->model->asDateTime($value);
    }

    /**
     * Cast value to timestamp.
     */
    protected function castToTimestamp($value, $key)
    {
        return $this->model->asTimestamp($value);
    }

    /**
     * Cast value to immutable date.
     */
    protected function castToImmutableDate($value, $key)
    {
        return $this->model->asDate($value)->toImmutable();
    }

    /**
     * Cast value to immutable datetime.
     */
    protected function castToImmutableDatetime($value, $key)
    {
        return $this->model->asDateTime($value)->toImmutable();
    }

    /**
     * Cast value to immutable custom datetime.
     */
    protected function castToImmutableCustomDatetime($value, $key)
    {
        return $this->model->asDateTime($value)->toImmutable();
    }
}
