<?php

namespace Tests\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

class UserWithSoftDeletes extends \Look\EloquentCypher\GraphModel
{
    use \Look\EloquentCypher\Concerns\GraphSoftDeletes, SoftDeletes {
        \Look\EloquentCypher\Concerns\GraphSoftDeletes::forceDelete insteadof SoftDeletes;
    }

    protected $table = 'users_with_soft_deletes';

    protected $fillable = ['name', 'email', 'age', 'status', 'salary'];

    /**
     * The attributes that should be mutated to dates.
     *
     * @var array
     */
    protected $dates = [
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    public function posts()
    {
        return $this->hasMany(PostWithSoftDeletes::class);
    }

    public function roles()
    {
        return $this->belongsToMany(RoleWithSoftDeletes::class);
    }

    public function profile()
    {
        return $this->hasOne(ProfileWithSoftDeletes::class);
    }

    public function comments()
    {
        return $this->hasMany(CommentWithSoftDeletes::class);
    }
}
