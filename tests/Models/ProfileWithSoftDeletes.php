<?php

namespace Tests\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

class ProfileWithSoftDeletes extends \Look\EloquentCypher\GraphModel
{
    use \Look\EloquentCypher\Concerns\GraphSoftDeletes, SoftDeletes {
        \Look\EloquentCypher\Concerns\GraphSoftDeletes::forceDelete insteadof SoftDeletes;
    }

    protected $table = 'profiles_with_soft_deletes';

    protected $fillable = ['bio', 'website', 'user_id'];

    protected $dates = [
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    public function user()
    {
        return $this->belongsTo(UserWithSoftDeletes::class);
    }
}
