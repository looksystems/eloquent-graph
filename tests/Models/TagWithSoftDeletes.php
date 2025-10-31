<?php

namespace Tests\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

class TagWithSoftDeletes extends \Look\EloquentCypher\GraphModel
{
    use \Look\EloquentCypher\Concerns\GraphSoftDeletes, SoftDeletes {
        \Look\EloquentCypher\Concerns\GraphSoftDeletes::forceDelete insteadof SoftDeletes;
    }

    protected $table = 'tags_with_soft_deletes';

    protected $fillable = ['name'];

    protected $dates = [
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    public function posts()
    {
        return $this->belongsToMany(PostWithSoftDeletes::class);
    }
}
