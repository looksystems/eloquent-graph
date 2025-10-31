<?php

namespace Tests\Models;

class NativeComment extends Comment
{
    protected $table = 'comments';

    protected $useNativeRelationships = true;

    public function post()
    {
        return $this->belongsTo(NativePost::class, 'post_id');
    }

    public function user()
    {
        return $this->belongsTo(NativeUser::class, 'user_id');
    }

    // Polymorphic relationship with native edges
    public function commentable()
    {
        return $this->morphTo();
    }
}
