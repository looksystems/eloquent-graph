<?php

namespace Tests\Models;

class NativeVideo extends Video
{
    protected $table = 'videos';

    protected $useNativeRelationships = true;

    public function comments()
    {
        return $this->morphMany(NativeComment::class, 'commentable');
    }

    public function images()
    {
        return $this->morphMany(NativeImage::class, 'imageable');
    }

    public function thumbnail()
    {
        return $this->morphOne(NativeImage::class, 'imageable');
    }
}
