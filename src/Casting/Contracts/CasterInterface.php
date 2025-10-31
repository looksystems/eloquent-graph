<?php

namespace Look\EloquentCypher\Casting\Contracts;

/**
 * Interface for attribute casters.
 */
interface CasterInterface
{
    /**
     * Cast the given value.
     *
     * @param  mixed  $value
     * @return mixed
     */
    public function cast($value, array $options = []);

    /**
     * Cast value from database format.
     *
     * @param  mixed  $value
     * @return mixed
     */
    public function castFromDatabase($value, array $options = []);

    /**
     * Cast value for database storage.
     *
     * @param  mixed  $value
     * @return mixed
     */
    public function castForDatabase($value, array $options = []);
}
