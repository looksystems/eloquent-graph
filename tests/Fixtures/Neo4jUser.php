<?php

namespace Tests\Fixtures;

use Illuminate\Support\Traits\Macroable;

class Neo4jUser extends \Look\EloquentCypher\GraphModel
{
    use Macroable {
        Macroable::__call as macroCall;
    }

    protected $table = 'users';

    protected $fillable = ['id', 'name', 'age', 'email', 'active', 'verified', 'follower_count'];

    protected $casts = [
        'active' => 'boolean',
        'verified' => 'boolean',
    ];

    /**
     * Handle dynamic method calls into the model.
     */
    public function __call($method, $parameters)
    {
        if (static::hasMacro($method)) {
            return $this->macroCall($method, $parameters);
        }

        return parent::__call($method, $parameters);
    }

    /**
     * Handle dynamic static method calls into the model.
     */
    public static function __callStatic($method, $parameters)
    {
        if (static::hasMacro($method)) {
            $instance = new static;
            $macro = static::$macros[$method];
            if ($macro instanceof \Closure) {
                $macro = $macro->bindTo(null, static::class);
            }

            return $macro(...$parameters);
        }

        return parent::__callStatic($method, $parameters);
    }
}
