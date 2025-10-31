<?php

namespace Tests\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

class RoleWithSoftDeletes extends \Look\EloquentCypher\GraphModel
{
    use \Look\EloquentCypher\Concerns\GraphSoftDeletes, SoftDeletes {
        \Look\EloquentCypher\Concerns\GraphSoftDeletes::forceDelete insteadof SoftDeletes;
    }

    protected $table = 'roles_with_soft_deletes';

    protected $fillable = ['name', 'permissions'];

    protected $dates = [
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    protected $casts = [
        'permissions' => 'array',
    ];

    public function users()
    {
        return $this->belongsToMany(UserWithSoftDeletes::class);
    }
}
