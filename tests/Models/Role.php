<?php

namespace Tests\Models;

use Database\Factories\RoleFactory;

class Role extends \Look\EloquentCypher\GraphModel
{
    protected $fillable = [
        'name', 'slug', 'permissions', 'description', 'level', 'type', 'created_at',
    ];

    protected $connection = 'graph';

    public function users()
    {
        return $this->belongsToMany(User::class);
    }

    /**
     * Create a new factory instance for the model.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory<static>
     */
    protected static function newFactory()
    {
        return RoleFactory::new();
    }
}
