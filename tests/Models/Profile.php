<?php

namespace Tests\Models;

use Database\Factories\ProfileFactory;

class Profile extends \Look\EloquentCypher\GraphModel
{
    protected $table = 'profiles';

    protected $fillable = [
        'bio',
        'location',
        'website',
        'user_id',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Create a new factory instance for the model.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory<static>
     */
    protected static function newFactory()
    {
        return ProfileFactory::new();
    }
}
