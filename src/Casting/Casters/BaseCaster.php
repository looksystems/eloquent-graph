<?php

namespace Look\EloquentCypher\Casting\Casters;

use Look\EloquentCypher\Casting\Contracts\CasterInterface;

/**
 * Base caster class with default implementations.
 */
abstract class BaseCaster implements CasterInterface
{
    /**
     * Cast the given value.
     *
     * @param  mixed  $value
     * @return mixed
     */
    public function cast($value, array $options = [])
    {
        return $this->castFromDatabase($value, $options);
    }

    /**
     * Cast value from database format.
     *
     * @param  mixed  $value
     * @return mixed
     */
    abstract public function castFromDatabase($value, array $options = []);

    /**
     * Cast value for database storage.
     * By default, returns the value as-is.
     *
     * @param  mixed  $value
     * @return mixed
     */
    public function castForDatabase($value, array $options = [])
    {
        return $value;
    }
}
