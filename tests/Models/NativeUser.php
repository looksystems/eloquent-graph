<?php

namespace Tests\Models;

class NativeUser extends User
{
    protected $table = 'users';

    protected $useNativeRelationships = true;

    // Optional: Custom edge types
    protected $relationshipEdgeTypes = [
        'authored_posts' => 'AUTHORED',
    ];

    // Override relationship to return NativePost instances
    public function posts()
    {
        return $this->hasMany(NativePost::class, 'user_id');
    }

    public function profile()
    {
        return $this->hasOne(NativeProfile::class, 'user_id');
    }

    public function authored_posts()
    {
        return $this->hasMany(NativePost::class, 'user_id');
    }

    public function comments()
    {
        return $this->hasManyThrough(NativeComment::class, NativePost::class);
    }

    public function customComments()
    {
        return $this->hasManyThrough(NativeComment::class, NativePost::class)
            ->withEdgeType('AUTHORED')
            ->withSecondEdgeType('RECEIVED');
    }

    // Polymorphic relationships with native edges
    public function images()
    {
        return $this->morphMany(NativeImage::class, 'imageable');
    }

    public function avatar()
    {
        return $this->morphOne(NativeImage::class, 'imageable');
    }
}
