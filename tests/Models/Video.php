<?php

namespace Tests\Models;

class Video extends \Look\EloquentCypher\GraphModel
{
    protected $fillable = ['title', 'url'];

    public function comments()
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    public function images()
    {
        return $this->morphMany(Image::class, 'imageable');
    }

    public function thumbnail()
    {
        return $this->morphOne(Image::class, 'imageable');
    }

    /**
     * Get all attached tags for the video.
     */
    public function attachedTags()
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }

    /**
     * Alias for attachedTags() for test compatibility.
     */
    public function tags()
    {
        return $this->attachedTags();
    }
}
