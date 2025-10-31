<?php

namespace Tests\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

class CommentWithSoftDeletes extends \Look\EloquentCypher\GraphModel
{
    use \Look\EloquentCypher\Concerns\GraphSoftDeletes, SoftDeletes {
        \Look\EloquentCypher\Concerns\GraphSoftDeletes::forceDelete insteadof SoftDeletes;
    }

    protected $table = 'comments_with_soft_deletes';

    protected $fillable = ['content', 'post_id', 'user_id'];

    protected $dates = [
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    public function post()
    {
        return $this->belongsTo(PostWithSoftDeletes::class);
    }

    public function user()
    {
        return $this->belongsTo(UserWithSoftDeletes::class);
    }
}
