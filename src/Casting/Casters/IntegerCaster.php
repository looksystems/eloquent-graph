<?php

namespace Look\EloquentCypher\Casting\Casters;

/**
 * Caster for integer values.
 */
class IntegerCaster extends BaseCaster
{
    /**
     * Cast value from database format.
     *
     * @param  mixed  $value
     */
    public function castFromDatabase($value, array $options = []): ?int
    {
        if ($value === null) {
            return null;
        }

        return (int) $value;
    }

    /**
     * Cast value for database storage.
     *
     * @param  mixed  $value
     */
    public function castForDatabase($value, array $options = []): ?int
    {
        if ($value === null) {
            return null;
        }

        return (int) $value;
    }
}
