<?php

namespace Tests\Models;

use Database\Factories\CommentFactory;

class Comment extends \Look\EloquentCypher\GraphModel
{
    protected $fillable = ['content', 'post_id', 'user_id', 'commentable_id', 'commentable_type', 'author', 'likes', 'approved', 'custom_post_id'];

    public function post()
    {
        return $this->belongsTo(Post::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Polymorphic relationship (for videos/posts)
    public function commentable()
    {
        return $this->morphTo();
    }

    /**
     * Create a new factory instance for the model.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory<static>
     */
    protected static function newFactory()
    {
        return CommentFactory::new();
    }
}
