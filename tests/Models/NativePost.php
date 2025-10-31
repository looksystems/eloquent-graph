<?php

namespace Tests\Models;

class NativePost extends Post
{
    protected $table = 'posts';

    protected $useNativeRelationships = true;

    // Override relationship to return NativeUser instance
    public function user()
    {
        return $this->belongsTo(NativeUser::class, 'user_id');
    }

    public function comments()
    {
        return $this->hasMany(NativeComment::class, 'post_id');
    }

    // Polymorphic relationships with native edges
    public function images()
    {
        return $this->morphMany(NativeImage::class, 'imageable');
    }

    public function featuredImage()
    {
        return $this->morphOne(NativeImage::class, 'imageable');
    }
}
