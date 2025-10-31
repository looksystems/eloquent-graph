<?php

namespace Tests\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

class PostWithSoftDeletes extends \Look\EloquentCypher\GraphModel
{
    use \Look\EloquentCypher\Concerns\GraphSoftDeletes, SoftDeletes {
        \Look\EloquentCypher\Concerns\GraphSoftDeletes::forceDelete insteadof SoftDeletes;
    }

    protected $table = 'posts_with_soft_deletes';

    protected $fillable = ['title', 'body', 'user_id', 'published', 'views'];

    protected $dates = [
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    protected $casts = [
        'published' => 'boolean',
        'views' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(UserWithSoftDeletes::class);
    }

    public function comments()
    {
        return $this->hasMany(CommentWithSoftDeletes::class);
    }

    public function tags()
    {
        return $this->belongsToMany(TagWithSoftDeletes::class);
    }
}
