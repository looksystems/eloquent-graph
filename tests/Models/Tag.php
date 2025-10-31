<?php

namespace Tests\Models;

class Tag extends \Look\EloquentCypher\GraphModel
{
    protected $fillable = ['name'];

    /**
     * Get all posts that have this tag.
     */
    public function posts()
    {
        return $this->morphedByMany(Post::class, 'taggable');
    }

    /**
     * Get all videos that have this tag.
     */
    public function videos()
    {
        return $this->morphedByMany(Video::class, 'taggable');
    }
}
