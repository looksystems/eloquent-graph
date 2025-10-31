<?php

namespace Tests\Models;

class NativeProfile extends Profile
{
    protected $table = 'profiles';

    protected $useNativeRelationships = true;

    // Override relationship to return NativeUser instance
    public function user()
    {
        return $this->belongsTo(NativeUser::class, 'user_id');
    }
}
